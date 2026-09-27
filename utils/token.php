<?php
require_once 'utils/session.php';
require_once 'utils/logger.php';

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

function setTokenHTML (){
    $token = setToken ();
    return "<input type='hidden' name='token' value='{$token}'>";
}

function removeToken (){
    unset ($_SESSION['token']);
}

function checkToken (){
    startSession ();
    if (!isset ($_SESSION['token']))
        return false;
    $token = $_SESSION['token'];
    unset ($_SESSION['token']);

    if (!isset ($_REQUEST['token']) || !is_string ($_REQUEST['token']))
        return false;

    if (!hash_equals ($token, $_REQUEST['token']))
        return false;
    
    return true;
}

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
