<?php
/**
 * Functions for handle the security token used in some pages to prevent dumb robots attacks
 */
require_once 'utils/session.php';
require_once 'utils/logger.php';

/**
 * Creates a session token.
 * 
 * @return string The token hex coded.
 */
function setToken (){
    startSession ();
    if (isset ($_SESSION['token'])){
        unset ($_SESSION['token']);
        /*throw new Exception("Residual token in page");
        return;*/
    }
    $token = bin2hex (random_bytes (16));
    $_SESSION['token'] = $token;
    return $token;
}

/**
 * Inserts token into HTML 
 */
function setTokenHTML (){
    $token = setToken ();
    return "<input type='hidden' name='token' value='{$token}'>";
}

/**
 * Removes token from session.
 */
function removeToken (){
    unset ($_SESSION['token']);
}


/**
 * Check if session token equals request token.
 */
function checkToken (){
    startSession ();
    if (!isset ($_SESSION['token']))
        return false;
    $token = $_SESSION['token'];
    unset ($_SESSION['token']);

    if (!isset ($_REQUEST['token']))
        return false;

    if ($token != $_REQUEST['token'])
        return false;
    
    return true;
}

/**
 * Inserts HTML token error text and logs it.
 */
function tokenError (){
    ?>
    <strong>
        <p>Error con el token de seguridad.</p>
        <p>No utilices las teclas de <em>Avanzar</em> y <em>Retroceder</em> del navegador.</p>
        <p>Si se repite el error prueba a eliminar las cookies y reiniciar el navegador.</p>
    </strong>
    <?php
    $e = new \Exception;
    logMessage (LOGGER_DEBUG, "Token error " . $e->getTraceAsString());
}
