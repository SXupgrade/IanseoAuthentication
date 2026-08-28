<?php
/**
 * Toggles $CFG->USERAUTH in Ianseo's root config.php from the module's own admin screen
 * (index.php), instead of asking an installer to hand-edit config.php. Also installs the
 * safety-net block right there the first time the module is enabled — see the comment on
 * authConfigWriterSafetyBlock() below for why that block needs to live in config.php itself and
 * not just in menu.php.
 *
 * Deliberately conservative: config.php is Ianseo's own bootstrap file, loaded on every single
 * request, so a bad write here has the highest possible blast radius (the whole site, not just
 * this module). Every write requires an exact, single match on the $CFG->USERAUTH line first —
 * anything else (zero matches, more than one, a line that doesn't look exactly as expected)
 * aborts with a clear error and touches nothing, rather than guessing. A backup copy is taken
 * before every write, and the write itself goes through a temp file + rename (atomic on the same
 * filesystem), never a partial in-place edit.
 */

if (!function_exists('authConfigWriterSafetyBlock')) {
    // Common/BlockDefines.php does a hard require_once() on Modules/Authentication/
    // BlockFunction.php as soon as USERAUTH is true, and that happens very early (config.php ->
    // Globals.inc.php -> BlockDefines.php) -- before Modules/Custom/Authentication/menu.php (the
    // module's normal self-heal hook, see Bootstrap.php) ever gets a chance to run. Without this
    // block sitting in config.php itself, an Ianseo update that wipes the unprotected
    // Modules/Authentication/ directory while USERAUTH stays true would fatal-error every single
    // request until someone noticed and fixed it by hand. The marker comment on the first line is
    // how authSetUserAuthEnabled() recognizes the block is already present, on this call and any
    // future one, so it's only ever inserted once.
    function authConfigWriterSafetyBlock()
    {
        return <<<'PHP'
// competplus-auth-safety-net -- installed automatically by the Compet+ Authentication module's
// admin screen when USERAUTH was enabled. Do not edit by hand: re-running the toggle leaves an
// existing block alone, so a manual edit here would just persist, but a stray manual copy could
// still throw off the "insert once" marker check below. Use the module's own admin screen to
// enable/disable instead of editing this file directly.
if ($CFG->USERAUTH && is_file($CFG->DOCUMENT_PATH . 'Modules/Custom/Authentication/Bootstrap.php')) {
    require_once($CFG->DOCUMENT_PATH . 'Modules/Custom/Authentication/Bootstrap.php');
    competplusAuthEnsureShim($CFG->DOCUMENT_PATH);
}
PHP;
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

if (!function_exists('authUserAuthConfiguredValue')) {
    // Reads the CURRENT on-disk value directly, independent of the already-loaded $CFG in this
    // request (which can be stale right after a write in the same request) -- used to render an
    // accurate status right after a toggle.
    function authUserAuthConfiguredValue($documentPath)
    {
        $configPath = rtrim($documentPath, '/\\') . '/config.php';
        $content = is_readable($configPath) ? @file_get_contents($configPath) : false;
        if ($content === false) {
            return null;
        }
        if (!preg_match('/^\$CFG->USERAUTH\s*=\s*(true|false)\s*;/m', $content, $m)) {
            return null;
        }
        return $m[1] === 'true';
    }
}

if (!function_exists('authSetUserAuthEnabled')) {
    function authSetUserAuthEnabled($documentPath, $enable, &$error = null)
    {
        $error = '';
        $configPath = rtrim($documentPath, '/\\') . '/config.php';

        if (!is_file($configPath) || !is_readable($configPath)) {
            $error = 'config.php introuvable ou illisible (' . $configPath . ').';
            return false;
        }
        if (!is_writable($configPath)) {
            $error = 'config.php n\'est pas modifiable par le serveur web (permissions).';
            return false;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $error = 'Lecture de config.php impossible.';
            return false;
        }

        // $-anchored (not an optional trailing \r?\n?): the match must consume the WHOLE line,
        // nothing less. An earlier version allowed the match to stop partway through the line
        // (e.g. right before a trailing "// comment"), which still "succeeded" but silently
        // rewrote only a prefix of an unrecognized line instead of refusing it -- exactly the
        // wrong failure mode for a file this sensitive. Requiring the full line to match means an
        // unexpected line shape (or none at all) reliably reports 0 matches instead.
        $pattern = '/^\$CFG->USERAUTH\s*=\s*(?:true|false)\s*;[ \t]*$/m';
        $matchCount = preg_match_all($pattern, $content);
        if ($matchCount !== 1) {
            $error = $matchCount === 0
                ? 'Ligne $CFG->USERAUTH introuvable ou dans un format inattendu dans config.php -- écriture annulée par sécurité.'
                : 'Plusieurs lignes $CFG->USERAUTH trouvées dans config.php -- écriture annulée par sécurité.';
            return false;
        }

        $hasBlock = strpos($content, '// competplus-auth-safety-net') !== false;

        $newValue = $enable ? 'true' : 'false';
        // No trailing "\n" here: the pattern above stops right before the line's own newline
        // (that character is untouched by the match and stays exactly where it was), so adding
        // one here would insert a stray blank line on every plain toggle.
        $replacement = '$CFG->USERAUTH=' . $newValue . ';';
        if ($enable && !$hasBlock) {
            $replacement .= "\n\n" . authConfigWriterSafetyBlock();
        }

        $newContent = preg_replace($pattern, $replacement, $content, 1);
        if ($newContent === null) {
            $error = 'Erreur interne lors de la préparation du nouveau contenu de config.php.';
            return false;
        }
        if ($newContent === $content) {
            return true; // already in the requested state, nothing to write
        }

        $backupPath = $configPath . '.competplus-auth-backup-' . date('Ymd-His');
        if (@copy($configPath, $backupPath) === false) {
            $error = 'Impossible de sauvegarder config.php avant modification -- écriture annulée.';
            return false;
        }

        $tmpPath = $configPath . '.tmp-' . uniqid('', true);
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
                $error = 'La nouvelle version de config.php ne passe pas la vérification de syntaxe, écriture annulée : ' . trim((string) $lintOutput);
                return false;
            }
        }

        if (!@rename($tmpPath, $configPath)) {
            if (!@copy($tmpPath, $configPath)) {
                @unlink($tmpPath);
                $error = 'Impossible de remplacer config.php (rename et copy ont échoué).';
                return false;
            }
            @unlink($tmpPath);
        }

        return true;
    }
}
