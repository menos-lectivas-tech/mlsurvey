<?php
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
            <h2>Admin content</h2>
            <ul>
            <li><a href="user_manage">Gestionar usuarias</a></li>
            <?php
        }
        else {
            ?>
            <h2>User content</h2>
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