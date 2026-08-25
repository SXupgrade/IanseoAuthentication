<?php
// Real module code lives in Modules/Custom/Authentication/ (one level
// deeper than the public Modules/Authentication/ shim that forwards
// here), hence the extra '../' compared to a plain Ianseo module.
require_once(dirname(__FILE__) . '/../../../config.php');
require_once(dirname(__FILE__) . '/AuthFunctions.php');
authClearSession();
CD_redirect($CFG->ROOT_DIR . 'index.php');
