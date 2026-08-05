<?php
/**
 * Return point from auth.competplus.fr. Handles both flows started by CompetplusStart.php:
 * 'login' (unauthenticated visitor signing in -- succeeds only if this Compet+ identity is
 * already linked to a local account, see Account.php; NEVER creates an account) and 'link' (an
 * already-authenticated local user attaching their Compet+ identity).
 */
require_once(dirname(__FILE__) . '/../../config.php');
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
    competplusCallbackRedirect($errorTarget, 'Compet+ login was cancelled or refused.');
}

$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');

try {
    $result = authCompetplusCompleteFlow($code, $state);
} catch (CompetplusOAuthException $e) {
    error_log('[CompetplusCallback] ' . $e->getMessage());
    competplusCallbackRedirect($errorTarget, 'Compet+ login failed. Please try again or use your local credentials.');
}

if ($result['mode'] === 'link') {
    // The session that started the link must still be the same local user -- refuses a stale or
    // hijacked session from completing a link for someone else's account.
    if (empty($_SESSION['AUTH_User']) || $_SESSION['AUTH_User'] !== $result['linkUsername']) {
        competplusCallbackRedirect('./Account.php', 'Your session changed during the Compet+ login, please try linking again.');
    }
    $linkError = '';
    if (!authLinkExternalIdentity($result['linkUsername'], 'competplus', $result['sub'], $linkError)) {
        competplusCallbackRedirect('./Account.php', $linkError);
    }
    competplusCallbackRedirect('./Account.php', 'Your Compet+ account is now linked.', false);
}

// Links $candidate (if it's a usable, unlinked match) to $sub and returns it, or null if
// $candidate is null/disabled/already linked to a different identity -- shared by both
// auto-pairing fallbacks below, never silently steals an existing manual link (see Account.php).
function competplusTryPairCandidate($candidate, $sub)
{
    if (!$candidate || !intval($candidate->AclUsEnabled) || authGetLinkedExternalIdentity($candidate->AclUsUser, 'competplus')) {
        return null;
    }
    $linkError = '';
    if (!authLinkExternalIdentity($candidate->AclUsUser, 'competplus', $sub, $linkError)) {
        return null;
    }
    return $candidate;
}

// mode === 'login'
$user = authFindUserByExternalId('competplus', $result['sub']);

// No explicit link yet -- try auto-pairing by e-mail: some Ianseo installs use the person's
// e-mail address as their login username (AclUsUser). Gated on Compet+'s email_verified (never
// trust an unverified address for this).
if (!$user && !empty($result['emailVerified'])) {
    $user = competplusTryPairCandidate(authFindUserByEmailAsUsername($result['email']), $result['sub']);
}

// Still nothing -- try auto-pairing by FFTA licence number, for installs that use it as the
// login username instead (arguably a better fit than e-mail, see authFindUserByLicenceAsUsername()).
// Requires one extra Bearer-authenticated call to cloud.competplus.fr (best-effort: silently
// skipped if the archer has no cloud profile yet, or cloud is briefly unreachable).
if (!$user && !empty($result['accessToken'])) {
    $archerProfile = authCompetplusFetchArcherProfile($result['accessToken'], authCompetplusConfig());
    if ($archerProfile && !empty($archerProfile['fftaLicence'])) {
        $user = competplusTryPairCandidate(authFindUserByLicenceAsUsername($archerProfile['fftaLicence']), $result['sub']);
    }
}

if (!$user || !intval($user->AclUsEnabled)) {
    competplusCallbackRedirect(
        './LogIn.php',
        'No Ianseo account is linked to this Compet+ identity yet. Log in with your local credentials, then link your account from "My account".'
    );
}

authCreateSession($user, null);
$return = $result['return'] !== '' ? $result['return'] : ($CFG->ROOT_DIR . 'index.php');
CD_redirect($return);
