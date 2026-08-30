<?php
/**
 * Return point from auth.competplus.fr. Handles both flows started by CompetplusStart.php:
 * 'login' (unauthenticated visitor signing in -- succeeds only if this Compet+ identity is
 * already linked to a local account, see Account.php; NEVER creates an account) and 'link' (an
 * already-authenticated local user attaching their Compet+ identity).
 */
// Real module code lives in Modules/Custom/Authentication/ (one level
// deeper than the public Modules/Authentication/ shim that forwards
// here), hence the extra '../' compared to a plain Ianseo module.
require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/CompetplusOAuth.php');

function competplusCallbackRedirect($target, $message = '', $isError = true)
{
    $sep = strpos($target, '?') !== false ? '&' : '?';
    if ($message !== '') {
        $target .= $sep . ($isError ? 'competplus_error=' : 'competplus_message=') . rawurlencode($message);
    }
    CD_redirect($target);
    die();
}

// A pending flow's mode tells us where to send the user back to on error, even before the
// code/state exchange below has run.
$pendingMode = isset($_SESSION[COMPETPLUS_OAUTH_SESSION_KEY]['mode']) ? $_SESSION[COMPETPLUS_OAUTH_SESSION_KEY]['mode'] : 'login';
$errorTarget = $pendingMode === 'link' ? './Account.php' : './LogIn.php';

if (!empty($_GET['error'])) {
    unset($_SESSION[COMPETPLUS_OAUTH_SESSION_KEY]);
    competplusCallbackRedirect($errorTarget, authText('MsgLoginCancelled'));
}

$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

try {
    $result = authCompetplusCompleteFlow($code, $state);
} catch (CompetplusOAuthException $e) {
    error_log('[CompetplusCallback] ' . $e->getMessage());
    competplusCallbackRedirect($errorTarget, authText('MsgLoginFailed'));
}

if ($result['mode'] === 'link') {
    // The session that started the link must still be the same local user -- refuses a stale or
    // hijacked session from completing a link for someone else's account.
    if (empty($_SESSION['AUTH_User']) || $_SESSION['AUTH_User'] !== $result['linkUsername']) {
        competplusCallbackRedirect('./Account.php', authText('MsgSessionChanged'));
    }
    $linkError = '';
    if (!authLinkExternalIdentity($result['linkUsername'], 'competplus', $result['sub'], $linkError)) {
        competplusCallbackRedirect('./Account.php', $linkError);
    }
    competplusCallbackRedirect('./Account.php', authText('MsgAccountLinked'), false);
}

// mode === 'login' -- see competplusResolveLoginUser() (CompetplusOAuth.php) for the matching
// logic itself, shared with CompetplusDeviceLogin.php's device-flow equivalent.
$user = competplusResolveLoginUser($result);

if (!$user) {
    competplusCallbackRedirect('./LogIn.php', authText('MsgNoAccountLinked'));
}

authCreateSession($user, null);
$return = $result['return'] !== '' ? $result['return'] : ($CFG->ROOT_DIR . 'index.php');
CD_redirect($return);
