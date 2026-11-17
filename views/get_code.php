<?php

/**
 * This class shows the page of an active survey, with the button for
 * participating: voting is done in the URL that asks for the client
 * certificate (Participate), which identifies the participant.
 */

require_once 'utils/html.php';
require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'utils/showsurvey.php';
require_once 'utils/clientcert.php';
require_once 'include/icons.php';

class GetCode extends View
{

    function getMenuGroup()
    {
        return ML_MENU_GROUP_SURVEYS;
    }

    /* Sin menú: el usuario se centra en votar. */
    function showNavigation()
    {
        return false;
    }

    public function loadStyles()
    {
    ?>
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
    <?php
    }

    /**
     * The survey page is loaded with GET (get_code?responseid=N) so it can be
     * linked and reloaded. If the survey is active, it shows its header, the
     * button for participating (participate?sid=N in the certificate URL) and
     * its questions.
     */
    public function show()
    {
        if (!isset($_GET['responseid'])) {
            showMain();
            return;
        }
        $surveyid = $_GET['responseid'];
        if (!is_string($surveyid) || !ctype_digit($surveyid)) {
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return;
        }
        try {
            $db = dbConn();
            $surveyname = $this->getSurveyName($db, $surveyid);
            if ($surveyname === null) {
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                logMessage(LOGGER_ERROR, "Survey {$surveyid} does not exist.");
                return;
            }
            if (!$this->isActive($db, $surveyid)) {
                echo ("<p><strong>La consulta <em>" . h($surveyname) .
                    "</em> no está abierta.</strong></p>");
                return;
            }
            if (!showSurveyHeader($db, $surveyid, true))
                return;
            $this->showParticipate((int) $surveyid);
        ?>
            <details class="ml-survey-preview">
                <summary>Ver las preguntas de la consulta</summary>
                <?php showSurveyQuestions($db, $surveyid, true); ?>
            </details>
        <?php
        } catch (Exception $e) {
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} getting survey.");
        }
    }

    private function showParticipate(int $surveyid)
    {
        $target = certURL("participate?sid=" . $surveyid);
        ?>
        <section class="ml-participate-card">
            <h3>Participar en la consulta</h3>
            <?php if (clientCertEnabled()) { ?>
                <p>Para votar, el navegador te pedirá que selecciones tu certificado electrónico
                    personal (DNI electrónico, certificado de la FNMT, de empleado/a público/a...).
                    Tiene que ser el del DNI con el que te registraste, y tu cuenta tiene que estar
                    verificada con el enlace que te enviamos por correo.</p>
            <?php } ?>
            <p>Si aún no te has registrado, hazlo antes en
                <a href="<?= h(certURL("register")); ?>">Regístrate</a>.</p>
            <p>Si quieres pensarte el voto antes, puedes leer las preguntas en «Ver las preguntas
                de la consulta», debajo de este recuadro.</p>
            <p><a class="button-3" href="<?= h($target); ?>">Participar en la consulta</a></p>
        </section>
        <?php
    }

    protected function getSurveyName($db, $sid): ?string
    {
        $query = $db->prepare("SELECT surveyname FROM {Surveys} WHERE surveyid = :sid");
        $query->bindParam(":sid", $sid, PDO::PARAM_INT);
        $query->execute();
        $name = $query->fetchColumn();
        $query->closeCursor();
        return $name === false ? null : $name;
    }

    protected function isActive($db, $sid)
    {
        $query = $db->prepare("SELECT 1 FROM {Surveys} WHERE surveyid = :sid
            AND startdate < NOW() AND enddate > NOW()");
        $query->bindParam(":sid", $sid, PDO::PARAM_INT);
        $query->execute();
        $active = $query->rowCount() > 0;
        $query->closeCursor();
        return $active;
    }
}
