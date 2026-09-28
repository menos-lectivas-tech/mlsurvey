<?php

function startSession (){
    if (session_id() == ""){
        /** 
         * WARNING: Hardcoding keys is insecure.
         * Load this securely from an environment variable or protected file.
         * */
        $encryptionKey = '12345678901234561234567890123456'; 
        // Enforce modern session cookie parameters
        /*session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,      // Requires HTTPS
            'httponly' => true,    // Blocks JavaScript access
            'samesite' => 'Strict' // Mitigates CSRF attacks
        ]);*/

        // Inject your handler into the engine
        /*$handler = new SecureSessionHandler($encryptionKey, "/tmp/ml");
        session_set_save_handler($handler, true);*/
        session_start();
    }
}

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

/* Borra solo lo que guarda el flujo de participación: la misma sesión
   puede tener abierta la administración, y no hay que cerrarla. */
function clearParticipationSession (){
    foreach (['surveyid', 'surveyname', 'participantid', 'privkey'] as $key)
        unset ($_SESSION[$key]);
}