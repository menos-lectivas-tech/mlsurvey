<?php
/**
 * Clears the session and shows the login form.
 */
include_once 'ifaces/view.php';
require_once 'utils/session.php';
//require_once 'views/main.php';
require_once 'views/login.php';
require_once 'utils/logger.php';
class Logout extends View {
    function doInit (){
        clearSession ();
    }
    
    function show (){
        //error_log ("LOGOUT!!!!!!");
        $main = new Login ();
        $main->show ();
    }
}