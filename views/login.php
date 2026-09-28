<?php
/**
 * This class shows the login form and handles the login information.
 * It's used in the admin class.
 */
require_once 'ifaces/view.php';
require_once 'utils/session.php';
require_once 'utils/user.php';
include_once 'utils/classname.php';
require_once 'utils/token.php';

class Login extends View {
    public function setOnSuccess ($next_class){
        startSession ();
        $_SESSION['onsuccess'] = $next_class;
    }


    function getMenuGroup (){
        return ML_MENU_GROUP_ADMIN;
    }
    
    public function show () {
        startSession ();
        if (isset ($_SESSION['userid'])){
            $this->onSuccess ();
            return;
        }
        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'Entrar'){
            unset ($_REQUEST['action']);
            if ($this->validate () == 0)
                return;
        }

        /*$token = bin2hex (random_bytes (16));
        $_SESSION['token'] = $token;*/
?>
<link href="css/button3.css" rel="stylesheet" />
<form id="loginbox" name="loginbox" method="POST" action="login" 
onload='document.getElementById("user").focus();'>
    <p>Usuaria: <input type="text" id="user" name="user" tabindex="-1"></p>
    <p>Clave: <input type="password" id="passwd" name="passwd"></p>
    <input class="button-3" type="submit" name="action" id="ok" value="Entrar">
    
    <?= setTokenHTML (); ?>
</form>
<?php
    }

    private function onSuccess (){
        startSession ();
        if (!isset($_SESSION['onsuccess'])){
            throw new Exception("No hay clase a la que entrar");
            return 1;
        }
        $class = $_SESSION['onsuccess'];
        unset ($_SESSION['onsuccess']);
        include_once 'views/' . $class . '.php';
        $class = getClassName($class);
        $next = new $class;
        /*if ($next instanceof View)
            $next->show ();
        else {
            throw new Exception("La clase de entrada no implementa View");
            return 1;
        }
        return 0;*/
        return showView ($next);
    }

    public function validate (){
        startSession ();
        
        if (!isset($_SESSION['token'])){
            $this->show ();
            return 1;
        }

        $user = $_REQUEST['user'];
        $passwd = $_REQUEST['passwd'];
        //$token = $_REQUEST['token'];
        try{
            if (validateUser ($user, $passwd) != 0){
                //unset ($_SESSION['token']);
                ?>
                <h2>Usuario, clave o token de seguridad no válido.</h2>
                <p>En caso de repetirse, limpia las cookies y reinicia el navegador.</p>
                <?php
                logMessage (LOGGER_ERROR, "Invalid login user {$user}");
                //$this->show ();
                return 1;
            }
        }
        catch (Exception $e){
            removeToken ();
            logMessage (LOGGER_ERROR, "DB error: {$e}");
            ?>
            <h2>Usuario, clave o token de seguridad no válido.</h2>
            <p>En caso de repetirse, limpia las cookies y reinicia el navegador.</p>
            <?php
            //$this->show ();
            return 1;
        }

        try {
            error_log ("Session started {$_SESSION['userid']}");
            $this->onSuccess ();
        }
        catch (Exception $e){
            ?>
            <h2>Error tras validarse:</h2>
            <p><?= $e; ?></p>
            <?php
            logMessage (LOGGER_ERROR, "Error onsuccess: {$e}");
            return 1;
        }
        return 0;
    }
}