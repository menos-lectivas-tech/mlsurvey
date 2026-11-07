<?php
/**
 * Clears the session and shows the login form.
 */
include_once 'ifaces/view.php';
require_once 'utils/session.php';
require_once 'views/login.php';
class Logout extends View {
    function doInit (){
        clearSession ();
    }
    
    function show (){
        $main = new Login ();
        $main->show ();
    }
}