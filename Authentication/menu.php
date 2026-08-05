<?php
require_once(dirname(__FILE__) . '/AuthFunctions.php');

$version = '2026-06-04 00:00:00';
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
