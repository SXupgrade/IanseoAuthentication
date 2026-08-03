<?php
$version = '2026-06-04 00:00:00';
$ret['AUTH'] [] = 'Account|' . $CFG->ROOT_DIR . 'Modules/Authentication/index.php';
if ((!empty($_SESSION['AUTH_ROOT']) || empty($_SESSION['AUTH_ENABLE']))) {
    $ret['AUTH'][] = 'Configuration|' . $CFG->ROOT_DIR . 'Modules/Authentication/';
    $ret['AUTH'][] = MENU_DIVIDER;
}
if (!empty($_SESSION['AUTH_User'])) {
    $ret['AUTH']['ACCOUNT'] = 'My account|' . $CFG->ROOT_DIR . 'Modules/Authentication/Account.php';
    $ret['AUTH']['LOGOUT'] = 'Logout|' . $CFG->ROOT_DIR . 'Modules/Authentication/LogOut.php';
}else {
    $ret['AUTH']['LOGIN'] = 'Login|' . $CFG->ROOT_DIR . 'Modules/Authentication/LogIn.php';
}
