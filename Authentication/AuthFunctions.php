<?php
/**
 * Compet+ lightweight Authentication module for Ianseo.
 * Original implementation using the public hooks already called by Ianseo core.
 */

if (!defined('AuthModule')) {
    // This file can be included from config.php before BlockDefines.php defines AuthModule.
}

function authEnsureTables()
{
    safe_w_SQL("CREATE TABLE IF NOT EXISTS `AclUsers` (
        `AclUsUser` VARCHAR(16) NOT NULL,
        `AclUsName` VARCHAR(100) NOT NULL,
        `AclUsPwd` VARCHAR(64) NOT NULL,
        `AclUsEnabled` TINYINT NOT NULL DEFAULT 1,
        `AclUsAuthAdmin` TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (`AclUsUser`)
    ) ENGINE=InnoDB", false, array(1050));

    safe_w_SQL("CREATE TABLE IF NOT EXISTS `AclUserFeatures` (
        `AclUFUser` VARCHAR(16) NOT NULL,
        `AclUFPattern` VARCHAR(150) NOT NULL,
        `AclUFFeature` TEXT NOT NULL,
        PRIMARY KEY (`AclUFUser`, `AclUFPattern`)
    ) ENGINE=InnoDB", false, array(1050));

    // Links a local AclUsers account to an external identity provider (Compet+ "Login with
    // Compet+" federated auth, see CompetplusOAuth.php). Owned entirely by this module (unlike
    // AclUsers/AclUserFeatures above, which are native Ianseo tables) -- never added as extra
    // columns on AclUsers, to avoid touching a table core Ianseo also manages.
    // PRIMARY KEY (Provider, AclUsUser): a local account has at most one linked identity per
    // provider. UNIQUE (Provider, ExternalId): an external identity can be linked to at most one
    // local account -- both directions enforced at the DB level, not just in application code.
    safe_w_SQL("CREATE TABLE IF NOT EXISTS `AclUserExternalAuth` (
        `Provider` VARCHAR(32) NOT NULL,
        `AclUsUser` VARCHAR(16) NOT NULL,
        `ExternalId` VARCHAR(64) NOT NULL,
        `LinkedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`Provider`, `AclUsUser`),
        UNIQUE KEY `UqAclUserExternalAuthExternalId` (`Provider`, `ExternalId`)
    ) ENGINE=InnoDB", false, array(1050));
}

// ── i18n ───────────────────────────────────────────────────────────────────
// Self-contained translation files under Languages/<code>.php -- this module's own folder, NOT
// Ianseo core's Common/Languages/ tree (get_text()'s usual home). Keeps the module a single
// drop-in copy with nothing to install into Ianseo core. Language DETECTION still reuses
// Ianseo's own SelectLanguage() (cookie/query/browser, see Common/Globals.inc.php) when
// available, so this module automatically follows whatever language a visitor already has for
// the rest of Ianseo -- no separate first-time choice needed, though a small switcher is still
// shown on this module's own pages (authLanguageSwitcherHtml()) since Ianseo's own page chrome
// isn't present on a bare login screen.
function authAvailableLanguages()
{
    static $codes = null;
    if ($codes !== null) {
        return $codes;
    }
    $codes = array();
    foreach (glob(dirname(__FILE__) . '/Languages/*.php') as $file) {
        $codes[] = basename($file, '.php');
    }
    sort($codes);
    return $codes;
}

function authCurrentLanguage()
{
    $detected = 'en';
    if (function_exists('SelectLanguage')) {
        $detected = strtolower((string)SelectLanguage());
    } elseif (!empty($_COOKIE['UseLanguage'])) {
        $detected = strtolower((string)$_COOKIE['UseLanguage']);
    }
    return in_array($detected, authAvailableLanguages(), true) ? $detected : 'en';
}

function authLoadLanguageStrings($code)
{
    static $cache = array();
    if (isset($cache[$code])) {
        return $cache[$code];
    }
    $lang = array();
    $file = dirname(__FILE__) . '/Languages/' . $code . '.php';
    if (file_exists($file)) {
        include $file; // defines $lang
    }
    $cache[$code] = $lang;
    return $lang;
}

// 'en' is always merged in first as a fallback base: a language file that's missing a newer key
// (translation not caught up yet) still renders readable English instead of a raw/empty string.
// Simple {placeholder} substitution -- no plural/gender rules; the one genuinely count-dependent
// string (index.php's "N write / M read") is composed from several authText() calls rather than
// grammar logic living here, see authAccessLabel().
function authText($key, $vars = null)
{
    $currentLang = authCurrentLanguage();
    $strings = $currentLang !== 'en'
        ? array_merge(authLoadLanguageStrings('en'), authLoadLanguageStrings($currentLang))
        : authLoadLanguageStrings('en');

    $text = isset($strings[$key]) ? $strings[$key] : $key;

    if (is_array($vars)) {
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
    }

    return $text;
}

// Compet+ logo mark (ring + centered "+"), inlined as SVG rather than a linked image file --
// keeps the module a single self-contained drop-in folder, no separate asset to copy at install
// time. Colored with Ianseo's own header blue (Common/Styles/colors.css's --header-dark: #004488,
// the color used across Ianseo's own table/section title bars) rather than Compet+'s usual brand
// color, so the module reads as part of Ianseo rather than a separate product bolted on.
function authCompetplusLogoSvg()
{
    return '<svg viewBox="0 0 200 200" role="img" aria-label="Compet+"><g transform="rotate(-9 100 100)">'
        . '<path d="M 149.72,129.87 A 17.00 17.00 0 0 1 178.86,147.38 L 174.46,154.03 L 169.50,160.28 L 164.02,166.07 L 158.06,171.36 L 151.67,176.12 L 144.88,180.31 L 137.76,183.89 L 130.36,186.85 L 122.72,189.15 L 114.92,190.78 L 107.00,191.73 L 99.04,191.99 L 91.08,191.57 L 83.18,190.45 L 75.41,188.65 L 67.83,186.19 L 60.49,183.08 L 53.44,179.35 L 46.75,175.02 L 40.45,170.13 L 34.61,164.71 L 29.25,158.81 L 24.42,152.46 L 20.17,145.72 L 16.51,138.64 L 13.48,131.26 L 11.09,123.66 L 9.38,115.87 L 8.35,107.97 L 8.00,100.00 L 8.35,92.03 L 9.38,84.13 L 11.09,76.34 L 13.48,68.74 L 16.51,61.36 L 20.17,54.28 L 24.42,47.54 L 29.25,41.19 L 34.61,35.29 L 40.45,29.87 L 46.75,24.98 L 53.44,20.65 L 60.49,16.92 L 67.83,13.81 L 75.41,11.35 L 83.18,9.55 L 91.08,8.43 L 99.04,8.01 L 107.00,8.27 L 114.92,9.22 L 122.72,10.85 L 130.36,13.15 L 137.76,16.11 L 144.88,19.69 L 151.67,23.88 L 158.06,28.64 L 164.02,33.93 L 169.50,39.72 L 174.46,45.97 L 178.86,52.62 A 4.00 4.00 0 0 1 172.00,56.74 L 167.64,50.92 L 162.80,45.53 L 157.55,40.61 L 151.92,36.19 L 145.96,32.29 L 139.71,28.94 L 133.23,26.17 L 126.57,23.98 L 119.78,22.38 L 112.92,21.39 L 106.03,21.00 L 99.17,21.20 L 92.40,22.00 L 85.75,23.38 L 79.29,25.32 L 73.05,27.80 L 67.09,30.79 L 61.44,34.28 L 56.15,38.21 L 51.24,42.57 L 46.76,47.32 L 42.73,52.40 L 39.18,57.78 L 36.13,63.42 L 33.60,69.27 L 31.60,75.28 L 30.13,81.41 L 29.21,87.60 L 28.83,93.82 L 29.00,100.00 L 29.70,106.11 L 30.92,112.10 L 32.64,117.92 L 34.86,123.54 L 37.53,128.91 L 40.64,133.99 L 44.17,138.76 L 48.06,143.17 L 52.31,147.20 L 56.85,150.82 L 61.66,154.01 L 66.70,156.75 L 71.93,159.03 L 77.30,160.83 L 82.76,162.15 L 88.29,162.99 L 93.83,163.33 L 99.34,163.20 L 104.78,162.58 L 110.11,161.51 L 115.29,159.98 L 120.28,158.02 L 125.05,155.66 L 129.56,152.90 L 133.79,149.78 L 137.70,146.33 L 141.27,142.58 L 144.47,138.57 L 147.29,134.32 L 149.72,129.87 Z" fill="#004488"/>'
        . '</g><path d="M 94.13,92.64 Q 94.13,89.14 97.63,89.14 L 98.86,89.14 Q 102.37,89.14 102.37,92.64 L 102.37,94.59 Q 102.37,98.09 105.87,98.09 L 107.81,98.09 Q 111.32,98.09 111.32,101.59 L 111.32,102.82 Q 111.32,106.32 107.81,106.32 L 105.87,106.32 Q 102.37,106.32 102.37,109.83 L 102.37,111.77 Q 102.37,115.27 98.86,115.27 L 97.63,115.27 Q 94.13,115.27 94.13,111.77 L 94.13,109.83 Q 94.13,106.32 90.63,106.32 L 88.68,106.32 Q 85.18,106.32 85.18,102.82 L 85.18,101.59 Q 85.18,98.09 88.68,98.09 L 90.63,98.09 Q 94.13,98.09 94.13,94.59 Z" fill="#004488"/></svg>';
}

// Small language switcher reusing Ianseo's own ?SetLanguage= cookie mechanism
// (Common/Globals.inc.php, top-level code -- runs on every page automatically) -- works on any
// page of this module with no extra wiring, and preserves the rest of the current query string
// (e.g. ?return=... on LogIn.php) since Ianseo's own handler takes care of that.
function authLanguageSwitcherHtml()
{
    $current = authCurrentLanguage();
    $links = array();
    foreach (authAvailableLanguages() as $code) {
        $label = htmlspecialchars(strtoupper($code));
        $links[] = $code === $current ? '<strong>' . $label . '</strong>'
            : '<a href="?SetLanguage=' . htmlspecialchars($code) . '">' . $label . '</a>';
    }
    return '<div class="cp-lang-switch">' . implode(' &middot; ', $links) . '</div>';
}

function authNormalizeUsername($username)
{
    $username = trim((string)$username);
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
}

// PASSWORD_BCRYPT explicitly, not PASSWORD_DEFAULT: bcrypt always produces a fixed 60-char
// '$2y$10$...' hash, which fits AclUsers.AclUsPwd VARCHAR(64) -- the column size was never
// actually the constraint the previous SHA-256 choice assumed it was. PASSWORD_DEFAULT is
// pinned to bcrypt today but PHP explicitly reserves the right to change it (e.g. to Argon2id,
// whose encoded hashes routinely exceed 90 chars) in a future version -- picking the algorithm
// explicitly means a PHP upgrade can't silently start producing hashes too long for this column.
define('AUTH_PASSWORD_ALGO', PASSWORD_BCRYPT);

function authHashPassword($password)
{
    // Salted per-call and cost-tunable, unlike the bare unsalted SHA-256 this replaces.
    return password_hash((string)$password, AUTH_PASSWORD_ALGO);
}

function authVerifyPassword($password, $storedHash)
{
    $storedHash = (string)$storedHash;
    if ($storedHash === '') {
        return false;
    }

    // Current storage format: password_hash()/bcrypt, always starting with '$'.
    if (substr($storedHash, 0, 1) === '$') {
        return password_verify((string)$password, $storedHash);
    }

    // Legacy format from earlier versions of this module: unsalted SHA-256, 64 hex chars.
    // Verified for backward compatibility with existing installs -- authNeedsRehash() below
    // flags these so authLogin() can transparently upgrade them to bcrypt on next successful
    // login, never by a bulk migration that would need the plaintext passwords up front.
    if (strlen($storedHash) === 64 && hash_equals($storedHash, hash('sha256', (string)$password))) {
        return true;
    }

    return false;
}

function authNeedsRehash($storedHash)
{
    $storedHash = (string)$storedHash;
    // Legacy unsalted SHA-256 hash: always needs upgrading to bcrypt.
    if (substr($storedHash, 0, 1) !== '$') {
        return true;
    }
    // Already bcrypt, but not necessarily at bcrypt's current default cost (e.g. after a PHP
    // upgrade raises it) -- let PHP itself decide.
    return password_needs_rehash($storedHash, AUTH_PASSWORD_ALGO);
}

function authLoadUser($username)
{
    authEnsureTables();
    $username = authNormalizeUsername($username);
    if ($username === '') {
        return null;
    }
    $sql = "SELECT * FROM `AclUsers` WHERE `AclUsUser`=" . StrSafe_DB($username) . " LIMIT 1";
    $q = safe_r_SQL($sql);
    if ($r = safe_fetch($q)) {
        return $r;
    }
    return null;
}

function authLoadUserRules($username)
{
    authEnsureTables();
    $rules = array();
    $username = authNormalizeUsername($username);
    if ($username === '') {
        return $rules;
    }
    $sql = "SELECT `AclUFPattern`, `AclUFFeature` FROM `AclUserFeatures` WHERE `AclUFUser`=" . StrSafe_DB($username) . " ORDER BY `AclUFPattern`";
    $q = safe_r_SQL($sql);
    while ($r = safe_fetch($q)) {
        $rules[] = array(
            'pattern' => (string)$r->AclUFPattern,
            'features' => (string)$r->AclUFFeature,
        );
    }
    return $rules;
}

// True if $username has been granted AclRoot (full delegated administration) for at least one
// competition pattern, regardless of which one -- used only to decide whether to surface a
// "Create a competition" menu link (see menu.php), since Ianseo core's own menu can't do this by
// itself: it only shows that link when a specific tournament code can already be checked against
// the user's rules, which is never true before a tournament exists (see
// Common/Menu.php:513-514's possibleFeature(AclRoot, AclReadWrite) call, evaluated with an empty
// code). The actual save-time enforcement (Tournament/index.php) is unaffected either way -- this
// is purely a discoverability aid, not a security boundary.
function authUserHasAnyRootGrant($username)
{
    foreach (authLoadUserRules($username) as $rule) {
        $parsed = authParseFeatureRules($rule['features']);
        if (isset($parsed[AclRoot]) && authFeatureEffectiveLevel($parsed[AclRoot]) >= AclReadWrite) {
            return true;
        }
    }
    return false;
}

function authCountUsers()
{
    authEnsureTables();
    $q = safe_r_SQL("SELECT COUNT(*) AS `cnt` FROM `AclUsers`");
    if ($r = safe_fetch($q)) {
        return intval($r->cnt);
    }
    return 0;
}

function authIsInitialSetupRequired()
{
    return authCountUsers() === 0;
}

function authBuildSupremeFeatureString()
{
    global $listACL;
    $items = array();

    if (isset($listACL) && is_array($listACL)) {
        foreach (array_keys($listACL) as $feature) {
            $feature = intval($feature);
            if ($feature > 0) {
                $items[] = $feature . '||' . AclReadWrite;
            }
        }
    }

    // Fallback for early bootstrap contexts where $listACL is not populated yet.
    // Root/admin still bypasses ACL checks, but storing a broad rule makes the first
    // admin visible in standard tournament filters and keeps the DB self-explanatory.
    if (!$items) {
        foreach (range(1, 99) as $feature) {
            $items[] = $feature . '||' . AclReadWrite;
        }
    }

    return implode('#', array_values(array_unique($items)));
}

function authCreateInitialAdmin($username, $name, $password, &$error = '')
{
    authEnsureTables();

    if (!authIsInitialSetupRequired()) {
        $error = authText('ErrSetupNotAvailable');
        return false;
    }

    $username = authNormalizeUsername($username);
    $name = trim((string)$name);
    $password = (string)$password;

    if ($username === '') {
        $error = authText('ErrUserRequired');
        return false;
    }
    if (strlen($username) > 16) {
        $error = authText('ErrUserTooLong');
        return false;
    }
    if ($name === '') {
        $name = $username;
    }
    if ($password === '') {
        $error = authText('ErrPasswordRequired');
        return false;
    }
    if (strlen($password) < 8) {
        $error = authText('ErrPasswordTooShort');
        return false;
    }

    safe_w_SQL("INSERT INTO `AclUsers` (`AclUsUser`, `AclUsName`, `AclUsPwd`, `AclUsEnabled`, `AclUsAuthAdmin`) VALUES (" .
        StrSafe_DB($username) . ", " .
        StrSafe_DB($name) . ", " .
        StrSafe_DB(authHashPassword($password)) . ", 1, 1)");

    safe_w_SQL("INSERT INTO `AclUserFeatures` (`AclUFUser`, `AclUFPattern`, `AclUFFeature`) VALUES (" .
        StrSafe_DB($username) . ", '*', " . StrSafe_DB(authBuildSupremeFeatureString()) . ")");

    $user = authLoadUser($username);
    if (!$user) {
        $error = authText('ErrInitialAdminLoadFailed');
        return false;
    }

    authCreateSession($user, $password);
    return true;
}

function authMatchPattern($pattern, $toCode)
{
    $pattern = (string)$pattern;
    $toCode = (string)$toCode;
    if ($pattern === '' || $pattern === '*') {
        return true;
    }
    $regex = '/^' . str_replace(array('\\*', '%'), '.*', preg_quote($pattern, '/')) . '$/i';
    return (bool)preg_match($regex, $toCode);
}

function authParseFeatureRules($featureString)
{
    $acl = array();
    $featureString = trim((string)$featureString);
    if ($featureString === '') {
        return $acl;
    }
    foreach (explode('#', $featureString) as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $parts = explode('|', $item);
        if (count($parts) < 3) {
            continue;
        }
        $feature = intval($parts[0]);
        $subFeature = trim((string)$parts[1]);
        $level = max(AclNoAccess, min(AclReadWrite, intval($parts[2])));
        // AclRoot (0) IS a valid, grantable feature here: pattern-scoped delegation (e.g. a rule
        // for pattern "13092*") intentionally supports granting it -- Ianseo core's own
        // checkFullACL(AclRoot, ...)/possibleFeature(AclRoot, ...) calls are pattern-aware by
        // design (see Tournament/index.php's tournament-creation save path). Only truly invalid
        // (negative) feature ids are rejected.
        if ($feature < AclRoot) {
            continue;
        }
        if (!isset($acl[$feature])) {
            $acl[$feature] = array('_level' => AclNoAccess, '_sub' => array(), '_hasLevel' => false);
        }

        // Empty subFeature is the feature-level permission.
        // Non-empty subFeature is a specific override and must not silently raise the global feature level.
        if ($subFeature === '') {
            $acl[$feature]['_level'] = max($acl[$feature]['_level'], $level);
            $acl[$feature]['_hasLevel'] = true;
        } else {
            $acl[$feature]['_sub'][$subFeature] = $level;
        }
    }
    return $acl;
}

function authFeatureEffectiveLevel($featureRule)
{
    $level = intval($featureRule['_level'] ?? AclNoAccess);
    foreach (($featureRule['_sub'] ?? array()) as $subLevel) {
        $level = max($level, intval($subLevel));
    }
    return $level;
}

function authResolveFeatureLevel($featureRule, $subFeature = '')
{
    $subFeature = trim((string)$subFeature);
    if ($subFeature !== '' && array_key_exists($subFeature, ($featureRule['_sub'] ?? array()))) {
        return intval($featureRule['_sub'][$subFeature]);
    }
    if ($subFeature === '') {
        return authFeatureEffectiveLevel($featureRule);
    }
    return intval($featureRule['_level'] ?? AclNoAccess);
}

function authMergeAcl(&$target, $featureRules)
{
    foreach ($featureRules as $feature => $data) {
        // actualACL() only has one cell per feature; expose the highest effective level so menus can open.
        // Fine-grained checks are handled by subFeatureAcl() / authCheckACL().
        $target[$feature] = max($target[$feature] ?? AclNoAccess, authFeatureEffectiveLevel($data));
    }
}

function authCurrentUserRulesForTournament($toCode = '')
{
    if (empty($_SESSION['AUTH_User'])) {
        return array();
    }
    $rules = authLoadUserRules($_SESSION['AUTH_User']);
    $matched = array();
    foreach ($rules as $rule) {
        if ($toCode === '' || authMatchPattern($rule['pattern'], $toCode)) {
            $matched[] = $rule;
        }
    }
    return $matched;
}

function authCreateSession($userRow, $password = null)
{
    $_SESSION['AUTH_User'] = (string)$userRow->AclUsUser;
    $_SESSION['AUTH_Pwd'] = $password === null ? 'session' : hash('sha256', (string)$password);
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_ROOT'] = intval($userRow->AclUsAuthAdmin) ? 1 : 0;
    $_SESSION['AUTH_COMP'] = array();

    if (empty($_SESSION['AUTH_ROOT'])) {
        foreach (authLoadUserRules($userRow->AclUsUser) as $rule) {
            $_SESSION['AUTH_COMP'][] = str_replace('*', '%', $rule['pattern']);
        }
        $_SESSION['AUTH_COMP'] = array_values(array_unique($_SESSION['AUTH_COMP']));
    }
}

function authClearSession()
{
    unset($_SESSION['AUTH_User'], $_SESSION['AUTH_Pwd'], $_SESSION['AUTH_ENABLE'], $_SESSION['AUTH_ROOT'], $_SESSION['AUTH_COMP']);
}

function authLogin($username, $password, &$error = '')
{
    $user = authLoadUser($username);
    if (!$user || !intval($user->AclUsEnabled)) {
        $error = authText('ErrInvalidOrDisabledAccount');
        return false;
    }
    if (!authVerifyPassword($password, $user->AclUsPwd)) {
        $error = authText('ErrInvalidCredentials');
        return false;
    }
    // Transparent upgrade path: a legacy unsalted-SHA-256 hash (or a bcrypt hash at an
    // outdated cost) is rewritten to the current algorithm now that we have the plaintext in
    // hand -- never in a bulk migration, which would need plaintext passwords up front and
    // therefore can't exist. Best-effort: a write failure here must not block the login itself.
    if (authNeedsRehash($user->AclUsPwd)) {
        safe_w_SQL("UPDATE `AclUsers` SET `AclUsPwd`=" . StrSafe_DB(authHashPassword($password)) . " WHERE `AclUsUser`=" . StrSafe_DB($user->AclUsUser));
    }
    authCreateSession($user, $password);
    return true;
}

// ── CSRF ─────────────────────────────────────────────────────────────────
// One token per PHP session (not per-form/per-request): simpler, and this module's pages are
// always plain full-page POSTs with no concurrent-tab JSON traffic that a rotating token would
// otherwise break. Session-bound, not cookie-bound -- can't be set by an attacker the way a
// double-submit cookie could.
function authCsrfToken()
{
    if (empty($_SESSION['AUTH_CSRF']) || !is_string($_SESSION['AUTH_CSRF'])) {
        $_SESSION['AUTH_CSRF'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['AUTH_CSRF'];
}

// Hidden field to drop inside every <form method="post"> this module renders.
function authCsrfField()
{
    return '<input type="hidden" name="auth_csrf" value="' . htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Must be the first thing checked in every POST handler, before acting on any other $_POST
// field. hash_equals() avoids leaking the token's value through a timing side channel.
function authCsrfCheck()
{
    return isset($_SESSION['AUTH_CSRF'], $_POST['auth_csrf'])
        && is_string($_POST['auth_csrf'])
        && hash_equals((string)$_SESSION['AUTH_CSRF'], (string)$_POST['auth_csrf']);
}

function authRequireAdmin()
{
    if (empty($_SESSION['AUTH_ROOT'])) {
        CD_redirect('./LogIn.php');
        die();
    }
}

function authRefreshSessionFromStoredUser()
{
    global $CFG;
    if (empty($CFG->USERAUTH)) {
        return;
    }
    if (empty($_SESSION['AUTH_User'])) {
        return;
    }
    if (!empty($_SESSION['AUTH_ENABLE']) && isset($_SESSION['AUTH_ROOT']) && isset($_SESSION['AUTH_COMP'])) {
        return;
    }

    $user = authLoadUser($_SESSION['AUTH_User']);
    if (!$user || !intval($user->AclUsEnabled)) {
        authClearSession();
        return;
    }

    $storedSessionPassword = isset($_SESSION['AUTH_Pwd']) ? $_SESSION['AUTH_Pwd'] : 'session';
    $_SESSION['AUTH_User'] = (string)$user->AclUsUser;
    $_SESSION['AUTH_Pwd'] = $storedSessionPassword;
    $_SESSION['AUTH_ENABLE'] = 1;
    $_SESSION['AUTH_ROOT'] = intval($user->AclUsAuthAdmin) ? 1 : 0;
    $_SESSION['AUTH_COMP'] = array();

    if (empty($_SESSION['AUTH_ROOT'])) {
        foreach (authLoadUserRules($user->AclUsUser) as $rule) {
            $_SESSION['AUTH_COMP'][] = str_replace('*', '%', $rule['pattern']);
        }
        $_SESSION['AUTH_COMP'] = array_values(array_unique($_SESSION['AUTH_COMP']));
    }
}


function authGetCurrentRequestPath()
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '') {
        return str_replace('\\', '/', $script);
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH);
    return str_replace('\\', '/', (string)$path);
}

function authIsPublicRequest()
{
    $path = authGetCurrentRequestPath();
    $pathLower = strtolower($path);

    // Authentication module entry points must remain reachable without a session.
    // CompetplusStart.php/CompetplusCallback.php: entry/exit points of the "Se connecter avec
    // Compet+" federated login (see CompetplusOAuth.php) -- reached before any local session
    // exists, exactly like LogIn.php/LogOut.php below. CompetplusDeviceLogin.php: same thing for
    // the Device Authorization Grant flow (see CompetplusDeviceAuth.php) -- one page handles both
    // the initial load and its own AJAX poll action, both unauthenticated.
    $publicFragments = array(
        '/modules/authentication/login.php',
        '/modules/authentication/logout.php',
        '/modules/authentication/authfunctions.php',
        '/modules/authentication/blockfunction.php',
        '/modules/authentication/competplusstart.php',
        '/modules/authentication/competpluscallback.php',
        '/modules/authentication/competplusdevicelogin.php',
    );
    foreach ($publicFragments as $fragment) {
        if (strpos($pathLower, $fragment) !== false) {
            return true;
        }
    }

    // Some deployments expose login as LogIn.php but URLs can be case-normalized above.
    if (preg_match('#/modules/authentication/login\.php$#i', $path)) {
        return true;
    }

    return false;
}

function authBuildReturnUrl()
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') {
        return '';
    }

    // Keep only local relative URLs. Never persist an absolute external redirect target.
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri) || substr($uri, 0, 2) === '//') {
        return '';
    }

    // Avoid bouncing back to authentication pages after a successful login.
    if (stripos($uri, '/Modules/Authentication/') !== false) {
        return '';
    }

    return $uri;
}


function authIsRootIndexRequest()
{
    global $CFG;
    $path = authGetCurrentRequestPath();
    $pathLower = strtolower($path);
    $rootDir = isset($CFG->ROOT_DIR) ? strtolower(str_replace('\\', '/', (string)$CFG->ROOT_DIR)) : '/';
    if ($rootDir === '') {
        $rootDir = '/';
    }
    if (substr($rootDir, -1) !== '/') {
        $rootDir .= '/';
    }

    // Match both /index.php and installations hosted in a subfolder, e.g. /ianseo/index.php.
    if ($pathLower === rtrim($rootDir, '/') . '/index.php') {
        return true;
    }
    if ($pathLower === $rootDir . 'index.php') {
        return true;
    }
    return (bool)preg_match('#/index\.php$#i', $path);
}

function authRequireLoginForRequest($force = false)
{
    global $CFG, $SKIP_AUTH;

    if (PHP_SAPI === 'cli') {
        return;
    }
    if (empty($CFG->USERAUTH)) {
        return;
    }
    if (!empty($SKIP_AUTH)) {
        return;
    }
    if (authIsPublicRequest()) {
        return;
    }
    if (!$force && !authIsRootIndexRequest()) {
        return;
    }
    if (!empty($_SESSION['AUTH_User'])) {
        return;
    }

    $target = $CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php';
    $returnUrl = authBuildReturnUrl();
    if ($returnUrl !== '') {
        $target .= '?return=' . rawurlencode($returnUrl);
    }

    if (!headers_sent()) {
        CD_redirect($target);
        exit();
    }

    // index.php calls the auth hook after the template head can already be printed.
    // In that specific case, fall back to a client-side redirect instead of breaking the page.
    $safeTarget = htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
    echo '<script>window.location.replace(' . json_encode($target) . ');</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeTarget . '"></noscript>';
    exit();
}


// ── "Se connecter avec Compet+" (auth.competplus.fr) ──────────────────────────
// Config lives in Ianseo's own Common/config.inc.php (site-owner-controlled, like
// $CFG->USERAUTH -- not config.php, which Ianseo's own core updater replaces wholesale on every
// update), NOT in this module -- see README.md "Compet+ federated login" for the block to add.
// Never assume a default client_id/secret: the feature is simply hidden (button not shown, entry
// points refuse) until an admin configures it.
//
// redirect_uri is the one field NOT required here (unlike client_id) -- an installation
// self-registered via compet+'s own "Connexion avec un compte Compet+" toggle may have no fixed,
// reliably-reachable URL to register one for, in which case only the device-code button
// (CompetplusDeviceLogin.php) can work; see authCompetplusHasRedirectFlow() below for the finer
// gate the redirect-based button itself needs.
function authCompetplusConfig()
{
    global $CFG;
    if (empty($CFG->COMPETPLUS_AUTH) || !is_array($CFG->COMPETPLUS_AUTH)) {
        return null;
    }
    $cfg = $CFG->COMPETPLUS_AUTH;
    $clientId = trim((string)($cfg['client_id'] ?? ''));
    if ($clientId === '') {
        return null;
    }
    return array(
        'client_id' => $clientId,
        'client_secret' => (string)($cfg['client_secret'] ?? ''),
        'auth_base_url' => rtrim((string)($cfg['auth_base_url'] ?? 'https://auth.competplus.fr'), '/'),
        // Used only for the best-effort FFTA-licence pairing fallback (see
        // authCompetplusFetchArcherProfile() / authFindUserByLicenceAsUsername()) -- the archer
        // profile (and its FFTA licence number) is owned by cloud.competplus.fr, not auth, so a
        // second Bearer-authenticated call is needed with the SAME access_token issued by the
        // token exchange (opaque platform session tokens are valid across all Compet+ apps, not
        // just auth -- see competplus_current_user() on the platform side).
        'cloud_base_url' => rtrim((string)($cfg['cloud_base_url'] ?? 'https://cloud.competplus.fr'), '/'),
        'redirect_uri' => trim((string)($cfg['redirect_uri'] ?? '')),
    );
}

// Gates the device-code button (CompetplusDeviceLogin.php) -- the only requirement is a
// configured client_id, since the Device Authorization Grant never presents a redirect_uri to
// auth.competplus.fr at all.
function authCompetplusEnabled()
{
    return authCompetplusConfig() !== null;
}

// Gates the classic redirect-based button (CompetplusStart.php) specifically -- needs a
// non-empty redirect_uri on top of authCompetplusEnabled(), which an install with no fixed,
// reliably-reachable URL may not have (see authCompetplusConfig() above).
function authCompetplusHasRedirectFlow()
{
    $config = authCompetplusConfig();
    return $config !== null && $config['redirect_uri'] !== '';
}

// Local account currently linked to a given Compet+ identity ("sub"), or null.
function authFindUserByExternalId($provider, $externalId)
{
    authEnsureTables();
    $sql = "SELECT `AclUsUser` FROM `AclUserExternalAuth` WHERE `Provider`=" . StrSafe_DB($provider)
        . " AND `ExternalId`=" . StrSafe_DB($externalId) . " LIMIT 1";
    $q = safe_r_SQL($sql);
    if ($r = safe_fetch($q)) {
        return authLoadUser($r->AclUsUser);
    }
    return null;
}

// AclUsers has no e-mail column: this only matches installs where the login username itself
// IS the person's e-mail address (common convention, and AclUsUser being VARCHAR(16) means it
// only ever matches short addresses -- longer ones simply never match, which is fine, they fall
// back to the manual link flow on Account.php). Exact, case-insensitive match only -- never a
// partial/LIKE match, to avoid matching an unrelated short username that happens to be a prefix
// of an email address.
function authFindUserByEmailAsUsername($email)
{
    $email = strtolower(trim((string)$email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    authEnsureTables();
    $sql = "SELECT * FROM `AclUsers` WHERE LOWER(`AclUsUser`)=" . StrSafe_DB($email) . " LIMIT 1";
    $q = safe_r_SQL($sql);
    if ($r = safe_fetch($q)) {
        return $r;
    }
    return null;
}

// Same idea as authFindUserByEmailAsUsername(), but for installs that use the archer's FFTA
// licence number as their login username instead of (or in addition to) an e-mail -- arguably a
// BETTER fit than e-mail for this module specifically, since an FFTA licence (7 digits + 1
// letter, 8 chars) comfortably fits AclUsUser's VARCHAR(16), unlike most e-mail addresses.
// $licence comes from cloud.competplus.fr's archer profile (see
// authCompetplusFetchArcherProfile()), NOT from auth's userinfo -- auth has no notion of FFTA
// licence, it belongs to the archer profile owned by cloud (see DATA_OWNERSHIP.md on the
// competplus-platform repo). Exact, case-insensitive match only.
function authFindUserByLicenceAsUsername($licence)
{
    $licence = strtoupper(trim((string)$licence));
    if ($licence === '' || strlen($licence) > 16) {
        return null;
    }
    authEnsureTables();
    $sql = "SELECT * FROM `AclUsers` WHERE UPPER(`AclUsUser`)=" . StrSafe_DB($licence) . " LIMIT 1";
    $q = safe_r_SQL($sql);
    if ($r = safe_fetch($q)) {
        return $r;
    }
    return null;
}

// Linked identity for a local account (for display on Account.php), or null.
function authGetLinkedExternalIdentity($username, $provider)
{
    authEnsureTables();
    $username = authNormalizeUsername($username);
    if ($username === '') {
        return null;
    }
    $sql = "SELECT * FROM `AclUserExternalAuth` WHERE `Provider`=" . StrSafe_DB($provider)
        . " AND `AclUsUser`=" . StrSafe_DB($username) . " LIMIT 1";
    $q = safe_r_SQL($sql);
    if ($r = safe_fetch($q)) {
        return $r;
    }
    return null;
}

// Links $username (an EXISTING, already-authenticated local account -- this function never
// creates one) to an external identity. Refuses if that identity is already linked to a
// DIFFERENT local account, rather than silently stealing the link.
function authLinkExternalIdentity($username, $provider, $externalId, &$error = '')
{
    authEnsureTables();
    $username = authNormalizeUsername($username);
    if ($username === '' || !authLoadUser($username)) {
        $error = authText('ErrUnknownLocalAccount');
        return false;
    }

    $existingOwner = authFindUserByExternalId($provider, $externalId);
    if ($existingOwner && (string)$existingOwner->AclUsUser !== $username) {
        $error = authText('ErrIdentityAlreadyLinked');
        return false;
    }

    safe_w_SQL("INSERT INTO `AclUserExternalAuth` (`Provider`, `AclUsUser`, `ExternalId`) VALUES ("
        . StrSafe_DB($provider) . ", " . StrSafe_DB($username) . ", " . StrSafe_DB($externalId)
        . ") ON DUPLICATE KEY UPDATE `ExternalId`=" . StrSafe_DB($externalId) . ", `LinkedAt`=NOW()");
    return true;
}

function authUnlinkExternalIdentity($username, $provider)
{
    authEnsureTables();
    $username = authNormalizeUsername($username);
    if ($username === '') {
        return;
    }
    safe_w_SQL("DELETE FROM `AclUserExternalAuth` WHERE `Provider`=" . StrSafe_DB($provider)
        . " AND `AclUsUser`=" . StrSafe_DB($username));
}

function authBootstrapRequest()
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    // Keep the global include non-blocking: config.php is used by many endpoints.
    // Access blocking is triggered by Ianseo's auth hook (isAuthEnabled), where the page context is known.
    if (function_exists('safe_r_SQL') && session_status() === PHP_SESSION_ACTIVE) {
        authRefreshSessionFromStoredUser();
    }
}

// Ianseo resets most session keys when a tournament is opened and keeps only AUTH_User/AUTH_Pwd.
// Rehydrate the missing authentication flags on each request so the user remains logged in.
authBootstrapRequest();

