<?php
require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/AuthFunctions.php');
authClearSession();
CD_redirect($CFG->ROOT_DIR . 'index.php');
