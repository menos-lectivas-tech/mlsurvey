<?php

/**
 * Safe start session checking if it has been started before.
 */
function startSession (){
    if (session_id() == ""){
        session_start();
    }
}

/**
 * Clears the session. Must be called when starting the HTML header.
 */
function clearSession (){
    logMessage (LOGGER_DEBUG, "Clearing session");
    startSession ();
    session_unset();
    session_destroy();
    session_write_close();
    setcookie(session_name(), '', 0, '/');
    startSession ();
    session_regenerate_id(true);
    return;
}

/**
 * Clears all session variables without clearing the session.
 */
function clearSessionVariables (){
    $_SESSION = array();
}