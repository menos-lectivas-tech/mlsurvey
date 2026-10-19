<?php
/**
 * This class handle the administraton page.
 * Depending on the user profile it shows different options.
 * If no user is loged in, it shows the login page.
 */
require_once 'ifaces/view.php';
require_once 'utils/user.php';
require_once 'views/login.php';

class Admin extends View {
    function getMenuGroup (){
        return ML_MENU_GROUP_ADMIN;
    }
    
    function isMulticol (){
        startSession ();
        if (isUser ())
            return true;

        return false;
    }

    function show (){
        startSession ();
        if (isUser ()){
            $this->adminContent ();
        }
        else{
            ?>
            <div class="ml-admin-intro">
                <h2>Panel de administración</h2>
                <p>Esta sección es solo para las personas que administran el sitio:
                    crean y gestionan las consultas y las usuarias.</p>
                <p>Si quieres participar en una consulta no necesitas entrar aquí: ve a
                    <a href="surveys">Consultas activas</a>.</p>
            </div>
            <?php
            $login = new Login;
            $login->setOnSuccess ('admin');
            $login->show ();
        }
    }

    function leftMenu (){
        include 'include/userleftcolumn.php';
    }
    function adminContent (){
        echo ('<div class="col-md-8">');
        if (isAdmin ()){
            ?>
            <h2>Panel de administración</h2>
            <ul>
            <li><a href="user_manage">Gestionar usuarias</a></li>
            <?php
        }
        else {
            ?>
            <h2>Panel de administración</h2>
            <ul>
            <?php
        }?>
        <li><a href="survey_manage">Gestionar consultas</a></li>
        <li><a href='logout'>Salir</a></li>
        </ul>
</div>
        <?php
    }
}