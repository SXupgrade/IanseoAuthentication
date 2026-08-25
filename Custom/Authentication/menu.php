<?php
// This file lives in Modules/Custom/, the directory Ianseo's update process
// never touches, and Ianseo auto-includes it on every menu build (that's
// what makes this the right place to self-heal). The links below point at
// Modules/Authentication/ — a small set of forwarder files regenerated here
// on demand, since Ianseo core hard-codes that path (BlockDefines.php,
// config.php) and can't be told to look in Modules/Custom/ instead. See
// Bootstrap.php for the full explanation.
require_once(dirname(__FILE__) . '/Bootstrap.php');
competplusAuthEnsureShim($CFG->DOCUMENT_PATH);

require_once(dirname(__FILE__) . '/AuthFunctions.php');

$version = '2026-08-25 00:00:00';
$ret['AUTH'] [] = authText('MenuAccount') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/index.php';
if ((!empty($_SESSION['AUTH_ROOT']) || empty($_SESSION['AUTH_ENABLE']))) {
    $ret['AUTH'][] = authText('MenuConfiguration') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/';
    $ret['AUTH'][] = MENU_DIVIDER;
}
if (!empty($_SESSION['AUTH_User'])) {
    $ret['AUTH']['ACCOUNT'] = authText('MenuMyAccount') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/Account.php';
    // Root already gets Ianseo core's own "New competition" menu entry (Common/Menu.php) --
    // this is only for a non-root user delegated AclRoot on a specific pattern, who core's menu
    // can't surface a link for on its own (see authUserHasAnyRootGrant()). Points straight at the
    // same URL core uses (Tournament/index.php?New=); the pattern match is (re-)checked at save
    // time regardless of how this link was reached.
    if (empty($_SESSION['AUTH_ROOT']) && authUserHasAnyRootGrant($_SESSION['AUTH_User'])) {
        $ret['AUTH']['NEWCOMP'] = authText('MenuCreateCompetition') . '|' . $CFG->ROOT_DIR . 'Tournament/index.php?New=';
    }
    $ret['AUTH']['LOGOUT'] = authText('MenuLogout') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/LogOut.php';
}else {
    $ret['AUTH']['LOGIN'] = authText('MenuLogin') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php';
}
