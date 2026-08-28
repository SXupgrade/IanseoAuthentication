<?php
/**
 * Self-update from GitHub: checks git tags on the public SXupgrade/IanseoAuthentication repo for
 * a newer version than VERSION, and — only when an admin explicitly asks for it, via index.php's
 * "Enable/Disable" screen (same $canAdmin gate as everything else there) — downloads that tag's
 * source, and atomically swaps it in for the currently-running Modules/Custom/Authentication/.
 *
 * Versioning: a plain VERSION file (semver, no "v" prefix) shipped inside Authentication/
 * itself, compared against the highest vX.Y.Z git tag on the repo (see authUpdaterFetchLatestTag()
 * -- tags are fetched, not GitHub Releases, so cutting a release is just `git tag vX.Y.Z && git
 * push origin vX.Y.Z`, no separate "publish a release" step required).
 *
 * The repo is a hardcoded constant below, not read from $CFG or any request input -- nothing about
 * where updates come from is configurable at runtime, so there is no way to point this at anything
 * other than the real module repo. Same trust model as Ianseo's own self-updater (Update/
 * UpdateIanseo.php): HTTPS + a fixed, known-good host, no separate signature verification.
 */

if (!defined('AUTH_UPDATER_REPO')) {
    define('AUTH_UPDATER_REPO', 'SXupgrade/IanseoAuthentication');
    define('AUTH_UPDATER_TIMEOUT', 10);
    define('AUTH_UPDATER_DOWNLOAD_TIMEOUT', 60);
    define('AUTH_UPDATER_CACHE_TTL', 6 * 3600);
    define('AUTH_UPDATER_USER_AGENT', 'CompetplusIanseoAuthenticationModule');
}

if (!function_exists('authUpdaterCurrentVersion')) {
    function authUpdaterCurrentVersion($documentPath)
    {
        $path = rtrim($documentPath, '/\\') . '/Modules/Custom/Authentication/VERSION';
        $v = is_file($path) ? trim((string) @file_get_contents($path)) : '';
        return $v !== '' ? $v : '0.0.0';
    }
}

if (!function_exists('authUpdaterHttpGetJson')) {
    function authUpdaterHttpGetJson($url, &$error)
    {
        // GitHub's API rejects requests with no User-Agent (403) -- always send one.
        $headers = array('Accept: application/vnd.github+json', 'User-Agent: ' . AUTH_UPDATER_USER_AGENT);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => AUTH_UPDATER_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
            ));
            $body = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);
            if ($body === false) {
                $error = 'Requête réseau échouée : ' . $curlError;
                return null;
            }
        } else {
            $context = stream_context_create(array('http' => array(
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => AUTH_UPDATER_TIMEOUT,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
            )));
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                $error = 'Requête réseau échouée (pas de cURL disponible).';
                return null;
            }
            $status = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                        $status = intval($m[1]);
                    }
                }
            }
        }

        if ($status < 200 || $status >= 300) {
            $error = 'GitHub a répondu HTTP ' . $status . '.';
            return null;
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            $error = 'Réponse GitHub illisible.';
            return null;
        }
        return $decoded;
    }
}

if (!function_exists('authUpdaterDownloadFile')) {
    function authUpdaterDownloadFile($url, $destPath, &$error)
    {
        $headers = array('User-Agent: ' . AUTH_UPDATER_USER_AGENT);

        if (function_exists('curl_init')) {
            $fp = @fopen($destPath, 'wb');
            if (!$fp) {
                $error = 'Impossible de créer le fichier temporaire pour le téléchargement.';
                return false;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_FILE => $fp,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => AUTH_UPDATER_DOWNLOAD_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
            ));
            $ok = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);
            fclose($fp);
            if (!$ok || $status < 200 || $status >= 300) {
                @unlink($destPath);
                $error = 'Téléchargement échoué (' . ($curlError !== '' ? $curlError : ('HTTP ' . $status)) . ').';
                return false;
            }
            return true;
        }

        $context = stream_context_create(array('http' => array(
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => AUTH_UPDATER_DOWNLOAD_TIMEOUT,
            'follow_location' => 1,
            'max_redirects' => 5,
        )));
        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            $error = 'Téléchargement échoué (pas de cURL disponible).';
            return false;
        }
        if (@file_put_contents($destPath, $data) === false) {
            $error = 'Écriture du fichier téléchargé impossible.';
            return false;
        }
        return true;
    }
}

if (!function_exists('authUpdaterFetchLatestTag')) {
    // Picks the highest well-formed vX.Y.Z (or X.Y.Z) tag by semver, not just the first one the
    // API happens to return -- tag list order is not guaranteed to be version-sorted.
    function authUpdaterFetchLatestTag(&$error)
    {
        $url = 'https://api.github.com/repos/' . AUTH_UPDATER_REPO . '/tags?per_page=30';
        $tags = authUpdaterHttpGetJson($url, $error);
        if ($tags === null) {
            return null;
        }

        $best = null;
        foreach ($tags as $tag) {
            $name = isset($tag['name']) ? (string) $tag['name'] : '';
            if (!preg_match('/^v?(\d+\.\d+\.\d+)$/', $name, $m)) {
                continue;
            }
            $version = $m[1];
            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = array(
                    'version' => $version,
                    'tag' => $name,
                    'zipUrl' => isset($tag['zipball_url']) ? (string) $tag['zipball_url'] : '',
                );
            }
        }
        if ($best === null || $best['zipUrl'] === '') {
            $error = 'Aucun tag de version exploitable trouvé sur le dépôt GitHub.';
            return null;
        }
        return $best;
    }
}

if (!function_exists('authUpdaterCacheStatus')) {
    function authUpdaterCacheStatus($current, $cached)
    {
        return array(
            'currentVersion' => $current,
            'latestVersion' => $cached['version'],
            'tag' => $cached['tag'],
            'zipUrl' => $cached['zipUrl'],
            'updateAvailable' => version_compare($cached['version'], $current, '>'),
            'checkedAt' => $cached['checkedAt'],
        );
    }
}

if (!function_exists('authUpdaterCheckForUpdate')) {
    // $forceRefresh bypasses the cache TTL (used by the "Check now" button and always internally
    // by authUpdaterApplyUpdate() before it downloads anything, so an update always applies the
    // truly latest tag rather than a stale cached one). On a network failure this still falls back
    // to a stale cache rather than erroring, so a passive page load never breaks just because
    // GitHub happened to be briefly unreachable -- only an explicit check/apply click surfaces the
    // error, via the caller checking $error itself.
    function authUpdaterCheckForUpdate($documentPath, &$error, $forceRefresh = false)
    {
        $error = '';
        $moduleDir = rtrim($documentPath, '/\\') . '/Modules/Custom/Authentication';
        $cachePath = $moduleDir . '/.update-check-cache.json';
        $current = authUpdaterCurrentVersion($documentPath);

        $cached = is_file($cachePath) ? json_decode((string) @file_get_contents($cachePath), true) : null;
        $cacheFresh = is_array($cached) && isset($cached['checkedAt'])
            && (time() - $cached['checkedAt']) < AUTH_UPDATER_CACHE_TTL;

        if (!$forceRefresh && $cacheFresh) {
            return authUpdaterCacheStatus($current, $cached);
        }

        $latest = authUpdaterFetchLatestTag($error);
        if ($latest === null) {
            if (is_array($cached)) {
                $error = ''; // stale-but-usable cache takes priority over surfacing a transient network error
                return authUpdaterCacheStatus($current, $cached);
            }
            return null;
        }

        $fresh = array('checkedAt' => time(), 'version' => $latest['version'], 'tag' => $latest['tag'], 'zipUrl' => $latest['zipUrl']);
        @file_put_contents($cachePath, json_encode($fresh));

        return authUpdaterCacheStatus($current, $fresh);
    }
}

if (!function_exists('authUpdaterCopyDir')) {
    function authUpdaterCopyDir($src, $dst)
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }
        $entries = @scandir($src);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $src . '/' . $entry;
            $dstPath = $dst . '/' . $entry;
            if (is_dir($srcPath)) {
                if (!authUpdaterCopyDir($srcPath, $dstPath)) {
                    return false;
                }
            } elseif (!@copy($srcPath, $dstPath)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('authUpdaterRemoveDir')) {
    function authUpdaterRemoveDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(@scandir($dir) ?: array(), array('.', '..')) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? authUpdaterRemoveDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

if (!function_exists('authUpdaterApplyUpdate')) {
    function authUpdaterApplyUpdate($documentPath, &$error)
    {
        $error = '';

        if (!class_exists('ZipArchive')) {
            $error = 'L\'extension PHP "zip" n\'est pas disponible sur ce serveur -- mise à jour automatique impossible ici.';
            return false;
        }

        $status = authUpdaterCheckForUpdate($documentPath, $error, true);
        if ($status === null) {
            return false; // $error already set
        }
        if (!$status['updateAvailable']) {
            return true; // already on the latest version, nothing to do
        }

        $tmpZip = sys_get_temp_dir() . '/competplus-auth-update-' . uniqid('', true) . '.zip';
        if (!authUpdaterDownloadFile($status['zipUrl'], $tmpZip, $error)) {
            return false;
        }

        $extractDir = sys_get_temp_dir() . '/competplus-auth-extract-' . uniqid('', true);
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            $error = 'Archive téléchargée invalide (ouverture impossible).';
            return false;
        }
        $extracted = $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tmpZip);
        if (!$extracted) {
            authUpdaterRemoveDir($extractDir);
            $error = 'Extraction de l\'archive téléchargée échouée.';
            return false;
        }

        // A GitHub zipball extracts into a single top-level "<owner>-<repo>-<shortsha>/" folder --
        // its exact name changes on every download, so find it rather than hardcoding it.
        $entries = array_values(array_diff(@scandir($extractDir) ?: array(), array('.', '..')));
        if (count($entries) !== 1 || !is_dir($extractDir . '/' . $entries[0])) {
            authUpdaterRemoveDir($extractDir);
            $error = 'Structure de l\'archive téléchargée inattendue.';
            return false;
        }
        // Le dépôt source place désormais Authentication/ directement à sa racine (avant :
        // Custom/Authentication/) -- voir le commit "Move folder". Le zip GitHub reflète donc
        // cette même racine.
        $sourceDir = $extractDir . '/' . $entries[0] . '/Authentication';
        if (!is_dir($sourceDir) || !is_file($sourceDir . '/VERSION')) {
            authUpdaterRemoveDir($extractDir);
            $error = 'L\'archive téléchargée ne contient pas de module valide (Authentication introuvable).';
            return false;
        }

        $moduleDir = rtrim($documentPath, '/\\') . '/Modules/Custom/Authentication';
        // Staging/backup dirs must NOT be direct children of Modules/Custom/ named
        // "Authentication-something": Ianseo's own menu builder globs Modules/Custom/*/menu.php,
        // one path segment, no further filtering -- a sibling like Authentication-backup-<ts>/
        // matches that glob just as well as Authentication/ does, and since it's a full copy it
        // has its own menu.php, so Ianseo would load BOTH and immediately fatal-error on every
        // function they both declare (found by testing this against a real Ianseo instance).
        // A dot-prefixed *containing* directory is never matched by a bare "*" glob segment, and
        // nothing has a menu.php directly inside .authentication-backups/ itself (only one level
        // deeper, inside each timestamped subfolder) -- so this is safe however "*" and dotfiles
        // interact in a given PHP glob() build, not just relying on this one build's specific
        // behavior. Same filesystem as the live module either way, so rename() stays atomic.
        $stagingDir = rtrim($documentPath, '/\\') . '/Modules/Custom/.authentication-staging/' . uniqid('', true);
        $backupDir = rtrim($documentPath, '/\\') . '/Modules/Custom/.authentication-backups/' . date('Ymd-His');

        // Build the new version fully in a staging dir first, untouched by the live one -- if
        // this fails partway (disk full, permissions...), nothing about the live install has been
        // touched yet.
        if (!authUpdaterCopyDir($sourceDir, $stagingDir)) {
            authUpdaterRemoveDir($stagingDir);
            authUpdaterRemoveDir($extractDir);
            $error = 'Préparation des nouveaux fichiers échouée -- rien n\'a été modifié.';
            return false;
        }
        authUpdaterRemoveDir($extractDir);

        // rename() needs its destination's parent to already exist (unlike mkdir(..., true), it
        // won't create it) -- .authentication-backups/ may not exist yet on the very first update.
        if (!is_dir(dirname($backupDir)) && !@mkdir(dirname($backupDir), 0755, true)) {
            authUpdaterRemoveDir($stagingDir);
            $error = 'Impossible de préparer le dossier de sauvegarde -- rien n\'a été modifié.';
            return false;
        }

        // Only two fast, same-filesystem, atomic rename()s touch the live directory. The PHP
        // process currently executing this request keeps running fine even though its own source
        // file's directory entry moves out from under it -- the already-compiled code stays in
        // memory for the rest of this request; only the *next* request reads from disk again, by
        // which point the new files are already in place.
        if (!@rename($moduleDir, $backupDir)) {
            authUpdaterRemoveDir($stagingDir);
            $error = 'Impossible de mettre de côté les fichiers actuels -- rien n\'a été modifié.';
            return false;
        }
        if (!@rename($stagingDir, $moduleDir)) {
            @rename($backupDir, $moduleDir); // best-effort rollback
            $error = 'Impossible d\'activer les nouveaux fichiers -- restauration automatique effectuée, rien n\'a changé.';
            return false;
        }

        return true;
    }
}
