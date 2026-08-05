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
    $ret['AUTH']['LOGOUT'] = authText('MenuLogout') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/LogOut.php';
}else {
    $ret['AUTH']['LOGIN'] = authText('MenuLogin') . '|' . $CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php';
}
