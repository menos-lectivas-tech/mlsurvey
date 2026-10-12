<?php
require_once 'utils/html.php';
require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'views/surveys.php';
require_once 'utils/participation.php';
require_once 'utils/token.php';

class ResponseSurvey extends View {
    public const ACTION = "Participar";
    function getMenuGroup (){
        return ML_MENU_GROUP_SURVEYS;
    }
    
    public function loadStyles (){
        ?>
        <link href="css/button3.css" rel="stylesheet" />
        <?php
    }

    public function show (){
        startSession ();
        /*if (isset ($_REQUEST[self::ACTION])){
            $this->participate ();
            return;
        }*/
        
        if (!isset ($_REQUEST[Surveys::SURVEY_RESPONSE]) || !isset($_REQUEST['responseid'])){
            showMain ();
            return;
        }
        $_SESSION['surveyid'] = $_REQUEST['responseid'];
        try {
            $db = dbConn ();
            $survey = $db->prepare ("SELECT surveyname FROM {Surveys} " .
                "WHERE surveyid = :id");
            $survey->bindParam (":id", $_SESSION['surveyid'], PDO::PARAM_INT);
            $survey->execute ();
            if ($survey->rowCount () == 0){
                removeToken ();
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                logMessage (LOGGER_ERROR, "Survey {$_SESSION['surveyid']} does not exist.");
                clearParticipationSession ();
                return;
            }
            $row = $survey->fetch ();
            echo ("<h2>Participar en la consulta <em>" . h ($row['surveyname']) . "</em>.</h2>");
            $survey->closeCursor ();
        }
        catch (Exception $e){
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} getting survey for response.");
            clearParticipationSession ();
            return;
        }
        ?>
        <form id="participate" name="participate" method="POST" action="participate"
            onload='document.getElementById("email").focus();'>
            <?= setTokenHTML (); ?>
            <p><label for="email">Dirección de correo:</label>
            <input type="email" name="email" id="email" class="ml-field-md" required
                placeholder="nombre.apellido@educa.madrid.org"></p>
            <p><label for="code">Código:</label>
            <input type="password" name="code" id="code" inputmode="numeric"
            size="6" minlength="6" maxlength="6" required></p>
            <p><input type="submit" class="button-3" name="<?= self::ACTION; ?>"
                value="<?= self::ACTION; ?>"></p>
        </form>
        <?php
    }
}