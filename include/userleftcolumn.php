<?php
/**
 * Left column for user and admin.
 */
?>

<div class="col-md-4">
    <link href="css/tablecard.css" rel="stylesheet" />
    <div class="card-table-container">
        <table class="card-like-table" id="usermenutable">
            <thead><tr>
                   <td>Opciones</td>
            </tr></thead>
            <tbody>
                <?php
                include_once 'utils/user.php';
                if (isAdmin ()){
                    if (isset (Config::PARAMS["ml_stresstest"]) && 
                        Config::PARAMS["ml_stresstest"]){
                        ?>
                        <tr><td><a href="stress_test">Test de stress</a></td></tr>
                        <?php
                    }
                    ?>
            <tr><td><a href="user_manage">Gestión de usuarias</a></td></tr>
            <tr><td><a href="system_manage">Configurar sistema</a></td></tr>
            <?php
                }
                ?>
            <tr><td><a href="survey_manage">Gestionar consultas</a></td></tr>
            <tr><td><a href='passwd_change'>Cambiar contraseña</a></td></tr>
            <tr id="last"><td><a href='logout'>Salir</a></td></tr>
</tbody>
</table>
</div>
</div>