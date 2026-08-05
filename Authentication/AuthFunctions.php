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

function authNormalizeUsername($username)
{
    $username = trim((string)$username);
    return preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
}

function authHashPassword($password)
{
    // Keep strict compatibility with Ianseo core schema where AclUsers.AclUsPwd is VARCHAR(64).
    // Do not use password_hash() here: modern hashes may exceed 64 chars depending on PHP defaults.
    return hash('sha256', (string)$password);
}

function authVerifyPassword($password, $storedHash)
{
    $storedHash = (string)$storedHash;
    if ($storedHash === '') {
        return false;
    }

    // Primary storage format for this compatibility module: SHA-256, 64 chars.
    if (hash_equals($storedHash, hash('sha256', (string)$password))) {
        return true;
    }

    // Tolerate already-created password_hash() values if someone used a previous module version
    // with an expanded custom schema. We verify them but never rewrite the DB to this format.
    if (substr($storedHash, 0, 1) === '$' && password_verify((string)$password, $storedHash)) {
        return true;
    }

    return false;
}

function authNeedsRehash($storedHash)
{
    // DB compatibility rule: never auto-migrate passwords to password_hash() because
    // the native Ianseo AclUsers.AclUsPwd column is VARCHAR(64).
    return false;
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
        $error = 'Initial setup is no longer available.';
        return false;
    }

    $username = authNormalizeUsername($username);
    $name = trim((string)$name);
    $password = (string)$password;

    if ($username === '') {
        $error = 'User is required.';
        return false;
    }
    if (strlen($username) > 16) {
        $error = 'User must be 16 characters or less.';
        return false;
    }
    if ($name === '') {
        $name = $username;
    }
    if ($password === '') {
        $error = 'Password is required.';
        return false;
    }
    if (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
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
        $error = 'Initial admin was created but could not be loaded.';
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
        if ($feature <= 0) {
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
        $error = 'Invalid user or disabled account.';
        return false;
    }
    if (!authVerifyPassword($password, $user->AclUsPwd)) {
        $error = 'Invalid username or password.';
        return false;
    }
    // Keep stored hash as-is: this module intentionally avoids automatic hash migration
    // to preserve compatibility with Ianseo's native AclUsers.AclUsPwd VARCHAR(64).
    authCreateSession($user, $password);
    return true;
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
    // exists, exactly like LogIn.php/LogOut.php below.
    $publicFragments = array(
        '/modules/authentication/login.php',
        '/modules/authentication/logout.php',
        '/modules/authentication/authfunctions.php',
        '/modules/authentication/blockfunction.php',
        '/modules/authentication/competplusstart.php',
        '/modules/authentication/competpluscallback.php',
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
// Config lives in Ianseo's own config.php (site-owner-controlled, like $CFG->USERAUTH), NOT in
// this module -- see README.md "Compet+ federated login" for the block to add. Never assume a
// default client_id/secret: the feature is simply hidden (button not shown, entry points refuse)
// until an admin configures it.
function authCompetplusConfig()
{
    global $CFG;
    if (empty($CFG->COMPETPLUS_AUTH) || !is_array($CFG->COMPETPLUS_AUTH)) {
        return null;
    }
    $cfg = $CFG->COMPETPLUS_AUTH;
    $clientId = trim((string)($cfg['client_id'] ?? ''));
    $redirectUri = trim((string)($cfg['redirect_uri'] ?? ''));
    if ($clientId === '' || $redirectUri === '') {
        return null;
    }
    return array(
        'client_id' => $clientId,
        'client_secret' => (string)($cfg['client_secret'] ?? ''),
        'auth_base_url' => rtrim((string)($cfg['auth_base_url'] ?? 'https://auth.competplus.fr'), '/'),
        'redirect_uri' => $redirectUri,
    );
}

function authCompetplusEnabled()
{
    return authCompetplusConfig() !== null;
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
        $error = 'Unknown local account.';
        return false;
    }

    $existingOwner = authFindUserByExternalId($provider, $externalId);
    if ($existingOwner && (string)$existingOwner->AclUsUser !== $username) {
        $error = 'This Compet+ account is already linked to another Ianseo user.';
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

