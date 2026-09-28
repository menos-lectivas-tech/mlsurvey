<?php
require_once 'utils/html.php';
require_once 'ifaces/view.php';
require_once 'utils/user.php';
require_once 'utils/dbutils.php';
require_once 'utils/logger.php';
require_once 'include/icons.php';

class UserManage extends View {

    private $userscount = 0;
    private const MANAGEACTION = 'manageaction';
    private const ADDACTION = 'addaction';
    private const MODIFYACTION = 'modifaction';
    private const DELACTION = 'delaction';

    function getMenuGroup (){
        return ML_MENU_GROUP_ADMIN;
    }
    
    function isMulticol (){
        startSession ();
        if (isAdmin ())
            return true;

        return false;
    }

    function leftMenu (){
        include 'include/userleftcolumn.php';
    }
    
    function show (){
        if (!isAdmin ()){
            showMain ();
            return;
        }
        if ((isset ($_REQUEST[self::MANAGEACTION]) || isset ($_REQUEST[self::ADDACTION]) ||
            isset ($_REQUEST[self::MODIFYACTION])) && !checkToken ()){
            echo ('<div class="col-md-8">');
            tokenError ();
            echo ('</div>');
            return;
        }
        if (isset($_REQUEST[self::MANAGEACTION])){
            $action = $_REQUEST[self::MANAGEACTION];
            unset ($_REQUEST[self::MANAGEACTION]);
            switch ($action) {
                case 'Añadir':
                    $this->showAddUser ();
                    return;
                    break;
                case 'Modificar':
                    $this->showModifyUser ();
                    return;
                case 'Eliminar':
                    //The warning should be previous;
                    $this->deleteUser ();
                    break;
                default:
                    logMessage (LOGGER_ERROR, "Unkown users manage action {$action}");
                    break;
            }
        }
        else if (isset($_REQUEST[self::ADDACTION])){
            $action = $_REQUEST[self::ADDACTION];
            unset ($_REQUEST[self::ADDACTION]);
            switch ($action) {
                case 'Aceptar':
                    $user = $_REQUEST['user'] ?? "";
                    $passwd = $_REQUEST['passwd'] ?? "";
                    if ($user == "" || $passwd == ""){
                        echo ("<h3>El nombre de usuaria y la clave no pueden estar vacíos</h3>");
                        break;
                    }
                    if ($passwd !== ($_REQUEST['passwd2'] ?? "")){
                        echo ("<h3>Las claves no coinciden</h3>");
                        break;
                    }
                    try {
                        if (userNameExists ($user)){
                            echo ("<h3>Ya existe una usuaria con ese nombre</h3>");
                            break;
                        }
                        if (isset($_REQUEST['isadmin'])){
                            $res = adduser ($user, $passwd, "A");
                        }
                        else {
                            $res = adduser ($user, $passwd);
                        }
                        if ($res == 0)
                            echo ("<strong>Usuaria " . htmlspecialchars ($user) . " añadida con éxito</strong>");
                        else
                            echo ("<h3>Error al añadir la usuaria</h3>");
                    }
                    catch (Exception $e){
                        echo ("<h3>Error al añadir la usuaria</h3>");
                        logMessage (LOGGER_ERROR, "Error ading user: {$e}");
                    }
                    break;
                default:
                    break;
            }
        }
        else if (isset($_REQUEST[self::MODIFYACTION])){
            $action = $_REQUEST[self::MODIFYACTION];
            unset ($_REQUEST[self::MODIFYACTION]);
            switch ($action) {
                case 'Aceptar':
                    $this->modifyUser ();
                    break;
            }
        }
        ?>
        <div class="col-md-8">
        <h2>Gestión de usuarias</h2>
        <form id="usermanage" name="usermanage" method="POST" action="user_manage" >
        <?= setTokenHTML (); ?>
        <?php
        $this->listUsers ();
        $this->showControls ();
        echo ('</form></div>');
    }

    private function listUsers (){
        try {
            $dbconn = dbConn ();
            $query = $dbconn->prepare ("SELECT userid, username from {Users} WHERE " .
                "userid <> :userid " .
                "ORDER BY username");
            $query->bindParam (':userid', $_SESSION['userid']);
            $query->execute ();
            $this->userscount = $query->rowCount ();
            if ($this->userscount == 0){
                ?>
                    <strong>Actualmente no hay usuarias para gestionar.</strong>
                <?php
                return;
            }
            ?>
            <div class="card-table-container">
            <table class="card-like-table ml-stack" id="userstable">
                <thead><tr>
                    <td>Usuaria</td><td class="ml-row-actions">Acciones</td>
                </tr></thead>
                <tbody>
                <?php
                while ($row = $query->fetch ()){
                    $id = $row['userid'];
                    $name = $row['username'];
                    ?>
                    <tr id="<?= $id; ?>">
                        <td><span class="username" id="us-<?= $id; ?>"><?= h ($name) ?></span></td>
                        <td class="ml-row-actions">
                            <button type="submit" class="button-3 is-ghost is-icon"
                                name="<?= self::MANAGEACTION ?>" value="Modificar"
                                id="modify-<?= $id; ?>" onclick="return pick_user (<?= $id; ?>);"
                                title="Modificar" aria-label="Modificar a <?= htmlspecialchars ($name); ?>">
                                <?= mlIcon ('edit'); ?>
                            </button>
                            <button type="submit" class="button-3 is-ghost is-icon is-danger"
                                name="<?= self::MANAGEACTION ?>" value="Eliminar"
                                id="delete-<?= $id; ?>" onclick="return confirm_delete (<?= $id; ?>, this);"
                                title="Eliminar" aria-label="Eliminar a <?= htmlspecialchars ($name); ?>">
                                <?= mlIcon ('trash'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
</div>
<!-- Los botones de cada fila escriben aquí a quién afectan: el servidor
     sigue leyendo $_REQUEST['userid'], como con el antiguo radio. -->
<input type="hidden" name="userid" id="selecteduser" value="">
            <?php
            $query->closeCursor ();
        }
        catch (Exception $e){
            ?>
                <p>Error obteniendo la lista de usuarios. Contacte con soporte.</p>
            <?php
            logMessage (LOGGER_ERROR, "DB error {$e} getting users list");
        }
    }

    private function showControls (){
       ?>
       <script type="text/javascript">
        /* Cada fila dice sobre quién actúa antes de enviar el formulario. */
        function pick_user (id){
            document.getElementById ('selecteduser').value = id;
            return true;
        }
        /* El diálogo del tema no detiene la ejecución como confirm(), así
           que este onclick siempre frena el envío y, si la respuesta es
           que sí, vuelve a pulsar el mismo botón: la segunda vez pasa de
           largo y el formulario se envía con su name y su value. */
        function confirm_delete (id, boton){
            if (boton.dataset.confirmado === '1')
                return true;

            pick_user (id);
            var username = document.getElementById ('us-' + id).innerText;
            mlDialog.confirm ({
                title: "Eliminar usuaria",
                message: "¿Seguro que quieres eliminar a " + username + "?\nEsto no se puede deshacer.",
                confirmText: "Eliminar",
                danger: true
            }).then (function (confirmado){
                if (!confirmado)
                    return;
                boton.dataset.confirmado = '1';
                boton.click ();
            });
            return false;
        }
        </script>
       <p>
       <input type="submit" class="button-3" name="<?= self::MANAGEACTION ?>" id="add" value="Añadir">
        </p>
        <?php
       
    }

    private function showAddUser (){
        ?>
        <div class="col-md-8">
        <script type="text/javascript">
            function validate_add (){
                var username = document.getElementById ("user").value;
                if (username == ""){
                    mlDialog.alert ("El nombre de usuaria no puede estar vacío");
                    return false;
                }
                var pw1 = document.getElementById ('passwd').value;
                var pw2 = document.getElementById ('passwd2').value;
                if (pw1 == ""){
                    mlDialog.alert ("LA clave no puede estar vacía");
                    return false;
                }
                if (pw1 != pw2){
                    mlDialog.alert ("Las claves no coinciden");
                    return false;
                }
                return true;
            }
        </script>
        <h2>Añadir usuaria</h2>
        <form id="adduser" name="adduser" method="POST" action="user_manage" 
onload='document.getElementById("user").focus();'>
        <?= setTokenHTML (); ?>
        <p>Usuaria: <input type="text" id="user" name="user" tabindex="-1"></p>
        <p>Clave: <input type="password" id="passwd" name="passwd"></p>
        <p>Confirmar clave: <input type="password" id="passwd2" name="passwd2"></p>
        <p>Es admin: <input type="checkbox" id="isadmin" name="isadmin"></p>
        <p><input class="button-3" type="submit" onclick="return validate_add ();"name="<?= self::ADDACTION ?>" id="ok" value="Aceptar">
        <input class="button-3" type="submit" name="<?= self::ADDACTION ?>" id="ok" value="Cancelar">
        </p>
                </form>
                </div>
        <?php
    }

    private function deleteUser (){
        $userid = $_REQUEST['userid'];
        $username = "";
        try {
            $username = getUserName ($userid);
            rmUser ($userid);
            echo ("<strong>Usuaria " . h ($username) . " eliminada con éxito.</strong>");
        }
        catch (Exception $e){
            echo ("<strong>Error al eliminar usuaria</strong>");
            logMessage (LOGGER_ERROR, "Error {$e} when deleting user {$userid} ({$username})");
        }
    }

    private function showModifyUser (){
        $userid = $_REQUEST['userid'];
        $dbconn = dbConn ();
        $name = getUserName ($userid);
        $isadmin = "";
        if (isAdmin ($userid)){
            $isadmin = "checked";
        }
        ?>
        <div class="col-md-8">
        <script type="text/javascript">
            function validate_mod (){
                var username = document.getElementById ("user").value;
                if (username == ""){
                    mlDialog.alert ("El nombre de usuaria no puede estar vacío");
                    return false;
                }
                var pw1 = document.getElementById ('passwd').value;
                var pw2 = document.getElementById ('passwd2').value;
                
                if (pw1 != pw2){
                    mlDialog.alert ("Las claves no coinciden");
                    return false;
                }
                return true;
            }
        </script>
        <h2>Modificar usuaria</h2>
        <form id="moduser" name="moduser" method="POST" action="user_manage" 
onload='document.getElementById("user").focus();'>
        <?= setTokenHTML (); ?>
        <p>Usuaria: <input type="text" id="user" name="user" tabindex="-1" value="<?= htmlspecialchars ($name ?? ""); ?>"></p>
        <p>Nueva clave: <input type="password" id="passwd" name="passwd" placeholder="Vacío sin cambios"></p>
        <p>Confirmar nueva clave: <input type="password" id="passwd2" name="passwd2"></p>
        <p>Es admin: <input type="checkbox" id="isadmin" name="isadmin" <?= $isadmin; ?>></p>
        <p><input class="button-3" type="submit" onclick="return validate_mod ();"name="<?= self::MODIFYACTION; ?>" id="ok" value="Aceptar">
        <input class="button-3" type="submit" name="<?= self::MODIFYACTION; ?>" id="ok" value="Cancelar">
        <input type="hidden" name="userid" value="<?= $userid; ?>">
        </p>
                </form>
                </div>
        <?php
    }
    private function modifyUser (){
        $userid = $_REQUEST['userid'];
        $newname = $_REQUEST['user'] ?? "";
        $newpasswd = $_REQUEST['passwd'] ?? "";
        if ($newname == ""){
            echo ('<strong>El nombre de usuaria no puede estar vacío</strong>');
            return;
        }
        if ($newpasswd !== ($_REQUEST['passwd2'] ?? "")){
            echo ('<strong>Las claves no coinciden</strong>');
            return;
        }
        $isadmin = "";
        if (isset ($_REQUEST['isadmin'])){
            $isadmin = "A";
        }
        try {
            if (userNameExists ($newname, $userid)){
                echo ('<strong>Ya existe otra usuaria con ese nombre</strong>');
                return;
            }
            alterUser ($userid, $newname, $isadmin, $newpasswd);
            echo ("<strong>Usuaria " . htmlspecialchars ($newname) . " modificada con éxito</strong>");
        }
        catch (Exception $e){
            echo ('<strong>Error al intentar modificar la usuaria</strong>');
            logMessage (LOGGER_ERROR, "Error modifying user {$e}");
        }
    }

    function loadStyles (){
        ?>
        <link href="css/tablecard.css" rel="stylesheet" />
        <link href="css/button3.css" rel="stylesheet" />
        <?php
    }
}