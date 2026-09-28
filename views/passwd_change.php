<?php

require_once 'ifaces/view.php';
require_once 'utils/session.php';
require_once 'utils/user.php';
require_once 'utils/token.php';
include_once 'utils/logger.php';

class PasswdChange extends View {

    private const CHGPW_ACTION = "chgpwd";

    public function loadStyles (){
        ?>
        <link href="css/button3.css" rel="stylesheet" />
        <?php
    }
    public function doInit (){
        if (!isUser () || !isset ($_REQUEST[self::CHGPW_ACTION]))
            return;
    }

    public function isMulticol (){
        startSession ();
        if (isUser ())
            return true;

        return false;
    }

    public function leftMenu (){
        include 'include/userleftcolumn.php';
    }

    public function getMenuGroup (){
        return ML_MENU_GROUP_ADMIN;
    }

    public function show (){
        if (!isUser ()){
            showMain ();
            return;
        }
        if (isset ($_REQUEST[self::CHGPW_ACTION])){
            if ($this->changePassword ())
                return;
        }
        ?>
        <form id="chgpwd" name="chgpwd" method="POST" action="passwd_change">
            <div class="ml-form">
                <div class="ml-form-row">
                    <label for="current">Clave actual:</label>
                    <input type="password" id="current" name="current" autocomplete="current-password" required>
                </div>
                <div class="ml-form-row">
                    <label for="newpasswd">Nueva clave:</label>
                    <input type="password" id="newpasswd" name="newpasswd" autocomplete="new-password" required>
                </div>
                <div class="ml-form-row">
                    <label for="newpasswd1">Confirmar nueva clave:</label>
                    <input type="password" id="newpasswd1" name="newpasswd1" autocomplete="new-password" required>
                </div>

                <p class="ml-form-wide">
                    <input class="button-3" type="submit" name="<?= self::CHGPW_ACTION ?>"
                        id="<?= self::CHGPW_ACTION ?>" value="Cambiar" onclick="return validatePasswd ();">
                </p>
            </div>

            <?= setTokenHTML (); ?>
        </form>
        <?php
    }

    private function changePassword (): bool {
        startSession ();
        $oldpw = $_REQUEST["current"] ?? "";
        $newpwplain = $_REQUEST["newpasswd"] ?? "";
        $userid = $_SESSION['userid'];
        if (empty ($oldpw) || empty ($newpwplain)){
            echo ("<p><strong>Ninguna clave puede estar vacía</strong></p>");
            return false;
        }
        if ($newpwplain !== ($_REQUEST["newpasswd1"] ?? "")){
            echo ("<p><strong>La nueva clave y su confirmación no coinciden</strong></p>");
            return false;
        }
        $newpw = password_hash ($newpwplain, PASSWORD_DEFAULT);
        try {
            if (validateUser ("", $oldpw, $userid) != 0){
                echo ("<p><strong>Clave actual erronea o token de seguridad no válido.</strong></p>");
                return false;
            }   
            $db = dbConn ();
            $query = $db->prepare ("UPDATE {Users} SET passwd = :pass WHERE userid = :id");
            $query->bindParam (":id", $userid, PDO::PARAM_INT);
            $query->bindParam (":pass", $newpw, PDO::PARAM_STR);
            $query->execute ();
            echo ("<p><strong>Clave cambiada satisfactoriamente.</strong></p>");
        }
        catch (Exception $e){
            echo ("<p><strong>Hubo un error al cambiar la clave.</strong></p>");
            logMessage (LOGGER_ERROR, "Error changing password for user {$userid}: {$e}");
            return false;
        }
        return true;
    }

    public function addJavascript (){
        ?>
        <script type="text/javascript">
            function validatePasswd (){
                const pw = document.getElementById ("newpasswd").value;
                const pw1 = document.getElementById ("newpasswd1").value;
                if (pw != pw1){
                    mlDialog.alert ("La nueva clave y su confirmación no coinciden");
                    return false;
                }
                return true;
            }
        </script>
        <?php
    }
}