<?php
require_once 'utils/session.php';
require_once 'utils/logger.php';

/* Tokens pendientes que se guardan por sesión. Cada formulario lleva el
   suyo y cada uno sirve para un único envío, pero caben varios a la vez:
   así una pestaña no invalida el formulario abierto en otra. */
const TOKEN_MAX = 20;

function setToken (){
    startSession ();
    if (!isset ($_SESSION['tokens']) || !is_array ($_SESSION['tokens']))
        $_SESSION['tokens'] = array ();
    $token = bin2hex (random_bytes (16));
    $_SESSION['tokens'][] = $token;
    while (count ($_SESSION['tokens']) > TOKEN_MAX)
        array_shift ($_SESSION['tokens']);
    return $token;
}

function setTokenHTML (){
    $token = setToken ();
    return "<input type='hidden' name='token' value='{$token}'>";
}

/* Descarta el último token generado, el del formulario que no se llegó a mostrar. */
function removeToken (){
    if (!empty ($_SESSION['tokens']))
        array_pop ($_SESSION['tokens']);
}

function hasToken (){
    startSession ();
    return !empty ($_SESSION['tokens']);
}

function checkToken (){
    startSession ();
    if (empty ($_SESSION['tokens']))
        return false;

    if (!isset ($_REQUEST['token']) || !is_string ($_REQUEST['token']))
        return false;

    foreach ($_SESSION['tokens'] as $key => $token){
        if (hash_equals ($token, $_REQUEST['token'])){
            unset ($_SESSION['tokens'][$key]);
            return true;
        }
    }
    return false;
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
