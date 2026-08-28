<?php
/**
 * Toggles $CFG->USERAUTH from the module's own admin screen (index.php), instead of asking an
 * installer to hand-edit a config file. Also installs the safety-net block right there the first
 * time the module is enabled — see the comment on authConfigWriterManagedBlock() below for why
 * that block exists.
 *
 * Written into Ianseo's Common/config.inc.php — the installation-specific settings file (DB
 * credentials, etc.), never part of Ianseo's own versioned/distributed code — NOT into
 * config.php, which IS part of that distributed code and gets replaced wholesale by every
 * official Ianseo core update (see Update/UpdateIanseo.php in the Ianseo checkout: it MD5-diffs
 * and rewrites every core file, config.php included). Writing the toggle there would mean both
 * $CFG->USERAUTH and the safety-net block silently vanish on the very next core update — exactly
 * the failure mode the safety net exists to prevent, just via a different trigger. config.php
 * itself still sets $CFG->USERAUTH=false as its shipped default; config.inc.php is included right
 * after that (see config.php's own require order) and genuinely overrides it, same as any other
 * setting there (DB credentials, USERAUTH, etc. — see Ianseo/CLAUDE.md's "does config.inc.php
 * redefine config.php's variables?" note in the platform repos for the general mechanism).
 *
 * Upgrading from a version that wrote into config.php: nothing to migrate by hand. That old block
 * is simply never touched by this file again -- it keeps running harmlessly (setting the same
 * $CFG->USERAUTH value, ensuring the same shim) until config.inc.php's copy, included right after
 * it and therefore evaluated last, takes over as the effective value on the very next toggle. The
 * stale fragment in config.php then disappears on its own on the next Ianseo core update, same as
 * it always would have -- just without taking $CFG->USERAUTH or the safety net down with it.
 *
 * Deliberately conservative: config.inc.php holds real DB credentials, so a bad write here has a
 * very high blast radius. A backup copy is taken before every write, and the write itself goes
 * through a temp file + rename (atomic on the same filesystem), never a partial in-place edit.
 */

if (!function_exists('authIanseoConfigIncPath')) {
    function authIanseoConfigIncPath($documentPath)
    {
        return rtrim($documentPath, '/\\') . '/Common/config.inc.php';
    }
}

if (!function_exists('authConfigWriterFunctionAvailable')) {
    function authConfigWriterFunctionAvailable($name)
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array($name, $disabled, true);
    }
}

if (!defined('AUTH_CONFIG_BLOCK_BEGIN')) {
    define('AUTH_CONFIG_BLOCK_BEGIN', '// === competplus-auth-module BEGIN (managed by the Ianseo Authentication module\'s own admin screen -- do not edit by hand) ===');
    define('AUTH_CONFIG_BLOCK_END', '// === competplus-auth-module END ===');
}

if (!function_exists('authConfigWriterManagedBlock')) {
    // Common/BlockDefines.php does a hard require_once() on Modules/Authentication/
    // BlockFunction.php as soon as USERAUTH is true, and that happens very early (config.php ->
    // Common/config.inc.php [this block] -> ... -> Common/Globals.inc.php -> BlockDefines.php) --
    // before Modules/Custom/Authentication/menu.php (the module's normal self-heal hook, see
    // Bootstrap.php) ever gets a chance to run. Without this block sitting in config.inc.php
    // itself, an Ianseo update that wipes the unprotected Modules/Authentication/ directory while
    // USERAUTH stays true would fatal-error every single request until someone noticed and fixed
    // it by hand. The BEGIN marker is how authSetUserAuthEnabled() recognizes the block is already
    // present, on this call and any future one -- the whole block (both the USERAUTH line and the
    // shim-check) is rebuilt and replaced wholesale on every toggle, so a future change to this
    // function's output also reaches already-enabled installs on their very next toggle.
    function authConfigWriterManagedBlock($enable)
    {
        $value = $enable ? 'true' : 'false';
        return AUTH_CONFIG_BLOCK_BEGIN . "\n"
            . '$CFG->USERAUTH = ' . $value . ";\n"
            . 'if ($CFG->USERAUTH && is_file($CFG->DOCUMENT_PATH . \'Modules/Custom/Authentication/Bootstrap.php\')) {' . "\n"
            . '    require_once($CFG->DOCUMENT_PATH . \'Modules/Custom/Authentication/Bootstrap.php\');' . "\n"
            . '    competplusAuthEnsureShim($CFG->DOCUMENT_PATH);' . "\n"
            . "}\n"
            . AUTH_CONFIG_BLOCK_END;
    }
}

if (!function_exists('authUserAuthConfiguredValue')) {
    // Reads the CURRENT on-disk value directly, independent of the already-loaded $CFG in this
    // request (which can be stale right after a write in the same request) -- used to render an
    // accurate status right after a toggle. Returns false (not null) when config.inc.php is
    // readable but has no managed block yet: that genuinely means USERAUTH is still at config.php's
    // own shipped default (false), not an error. Returns null only when config.inc.php itself
    // can't be read at all -- on any real running installation that would mean something is
    // seriously wrong (this file also holds the DB credentials Ianseo needs to boot).
    function authUserAuthConfiguredValue($documentPath)
    {
        $configIncPath = authIanseoConfigIncPath($documentPath);
        $content = is_readable($configIncPath) ? @file_get_contents($configIncPath) : false;
        if ($content === false) {
            return null;
        }
        if (!preg_match('/\$CFG->USERAUTH\s*=\s*(true|false)\s*;/', $content, $m)) {
            return false;
        }
        return $m[1] === 'true';
    }
}

if (!function_exists('authConfigIncStripTrailingCloseTag')) {
    // A stray closing PHP tag left at the end of config.inc.php would turn anything appended
    // after it into literal output (echoed on every single page load, breaking the site) instead
    // of PHP -- strip it before appending, matching the standard "omit the closing tag in
    // pure-PHP files" practice so this can never bite on a future edit either, ours or a manual
    // one. (Note for anyone editing this comment: never write the literal two-character closing
    // tag inside a // comment in this file -- PHP's tokenizer ends PHP mode on that sequence
    // wherever it appears, comment or not, which is exactly the class of bug this function exists
    // to prevent.)
    function authConfigIncStripTrailingCloseTag($content)
    {
        return preg_replace('/\?>\s*\z/', '', $content);
    }
}

if (!function_exists('authSetUserAuthEnabled')) {
    function authSetUserAuthEnabled($documentPath, $enable, &$error = null)
    {
        $error = '';
        $configIncPath = authIanseoConfigIncPath($documentPath);

        if (!is_file($configIncPath) || !is_readable($configIncPath)) {
            $error = 'Common/config.inc.php introuvable ou illisible (' . $configIncPath . ').';
            return false;
        }
        if (!is_writable($configIncPath)) {
            $error = 'Common/config.inc.php n\'est pas modifiable par le serveur web (permissions).';
            return false;
        }

        $content = file_get_contents($configIncPath);
        if ($content === false) {
            $error = 'Lecture de Common/config.inc.php impossible.';
            return false;
        }

        $beginCount = substr_count($content, AUTH_CONFIG_BLOCK_BEGIN);
        if ($beginCount > 1) {
            $error = 'Plusieurs blocs de gestion trouvés dans Common/config.inc.php -- écriture annulée par sécurité.';
            return false;
        }

        $newBlock = authConfigWriterManagedBlock($enable);

        if ($beginCount === 1) {
            $pattern = '/' . preg_quote(AUTH_CONFIG_BLOCK_BEGIN, '/') . '.*?' . preg_quote(AUTH_CONFIG_BLOCK_END, '/') . '/s';
            if (!preg_match($pattern, $content)) {
                $error = 'Bloc de gestion incomplet (BEGIN sans END correspondant) dans Common/config.inc.php -- écriture annulée par sécurité.';
                return false;
            }
            $newContent = preg_replace($pattern, $newBlock, $content, 1);
            if ($newContent === null) {
                $error = 'Erreur interne lors de la préparation du nouveau contenu de Common/config.inc.php.';
                return false;
            }
        } else {
            $trimmed = rtrim(authConfigIncStripTrailingCloseTag($content));
            $newContent = $trimmed . "\n\n" . $newBlock . "\n";
        }

        if ($newContent === $content) {
            return true; // already in the requested state, nothing to write
        }

        $backupPath = $configIncPath . '.competplus-auth-backup-' . date('Ymd-His');
        if (@copy($configIncPath, $backupPath) === false) {
            $error = 'Impossible de sauvegarder Common/config.inc.php avant modification -- écriture annulée.';
            return false;
        }

        $tmpPath = $configIncPath . '.tmp-' . uniqid('', true);
        if (@file_put_contents($tmpPath, $newContent, LOCK_EX) === false) {
            $error = 'Impossible d\'écrire le fichier temporaire.';
            @unlink($tmpPath);
            return false;
        }

        // Best-effort syntax check before it goes live, when the environment allows shelling out
        // (often disabled on managed hosting -- skipped silently when unavailable, not an error).
        if (authConfigWriterFunctionAvailable('shell_exec')) {
            $lintOutput = @shell_exec('php -l ' . escapeshellarg($tmpPath) . ' 2>&1');
            if ($lintOutput !== null && stripos((string) $lintOutput, 'No syntax errors detected') === false) {
                @unlink($tmpPath);
                $error = 'La nouvelle version de Common/config.inc.php ne passe pas la vérification de syntaxe, écriture annulée : ' . trim((string) $lintOutput);
                return false;
            }
        }

        if (!@rename($tmpPath, $configIncPath)) {
            if (!@copy($tmpPath, $configIncPath)) {
                @unlink($tmpPath);
                $error = 'Impossible de remplacer Common/config.inc.php (rename et copy ont échoué).';
                return false;
            }
            @unlink($tmpPath);
        }

        return true;
    }
}
