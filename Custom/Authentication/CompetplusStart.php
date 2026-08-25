<?php
/**
 * Entry point of "Se connecter avec Compet+": redirects the browser to auth.competplus.fr.
 * ?mode=link is used from Account.php by an ALREADY authenticated local user linking their own
 * account -- it requires an active local session and never creates or logs into a different
 * account. Anything else is the default ?mode=login path for an unauthenticated visitor.
 */
// Real module code lives in Modules/Custom/Authentication/ (one level
// deeper than the public Modules/Authentication/ shim that forwards
// here), hence the extra '../' compared to a plain Ianseo module.
require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/CompetplusOAuth.php');

$mode = ($_GET['mode'] ?? '') === 'link' ? 'link' : 'login';

if ($mode === 'link' && empty($_SESSION['AUTH_User'])) {
    CD_redirect('./LogIn.php');
    die();
}

try {
    $url = authCompetplusAuthorizeUrl(
        $mode,
        $mode === 'link' ? $_SESSION['AUTH_User'] : '',
        $mode === 'login' ? (string)($_GET['return'] ?? '') : ''
    );
} catch (CompetplusOAuthException $e) {
    error_log('[CompetplusStart] ' . $e->getMessage());
    $target = $mode === 'link' ? './Account.php' : './LogIn.php';
    CD_redirect($target . '?competplus_error=' . rawurlencode(authText('MsgCompetplusUnavailable')));
    die();
}

CD_redirect($url);
