<?php
require_once 'utils/host.php';

/**
 * Safe start session checking if it has been started before.
 */
function startSession (){
    if (session_id() == ""){
        /* Los atributos de la cookie se fijan aquí y no en php.ini: así no
           dependen de cómo esté configurado el servidor. Secure solo con
           HTTPS: servida por HTTP, el navegador descartaría la cookie y no
           habría sesión. */
        session_set_cookie_params ([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isHttps (),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
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


/* Borra solo lo que guarda el flujo de participación: la misma sesión
   puede tener abierta la administración, y no hay que cerrarla. */
function clearParticipationSession (){
    foreach (['surveyid', 'surveyname', 'participantid', 'privkey'] as $key)
        unset ($_SESSION[$key]);

}