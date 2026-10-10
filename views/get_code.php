<?php

/**
 * This class shows the page for getting and sending participation URL.
 */

require_once 'utils/html.php';

require_once 'ifaces/registrationview.php';
require_once 'utils/dbutils.php';
require_once 'views/surveys.php';
require_once 'include/mlmailer.php';
require_once 'utils/participation.php';
require_once 'utils/showsurvey.php';
require_once 'utils/crypt.php';

class GetCode extends RegistrationView
{

    private const PAGE = "get_code";

    function getMenuGroup()
    {
        return ML_MENU_GROUP_SURVEYS;
    }

    /* Sin menú: el usuario se centra en solicitar el voto / votar. */
    function showNavigation()
    {
        return false;
    }

    /**
     * Depending on the request parameters the class takes different actions:
     * - If it's not a submit and the request does't have survey information the class shows
     * the main page.
     * - If it's a GET with survey info, shows the form asking for the email address.
     * - If it's the submit of that form and the address is already registered, sends the
     * email and shows the result (success or failure). If it isn't registered, shows a
     * second form asking for the pension scheme (Clases Pasivas or the rest), the signed
     * training record PDF and the PDF that proves it.
     * The DNI is not asked for: it is read from the training record.
     * - If it's the submit of the second form, checks the PDFs, sends the email and shows
     * the result (success or failure).
     */
    public function show()
    {
        if ($this->isSubmit()) {
            if ($this->checkSubmit())
                $this->generateCode($this->isDocsSubmit());
            return;
        }

        /* La página de una consulta se carga con GET (get_code?responseid=N)
           para que se pueda enlazar y recargar sin reenviar el formulario. */
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
            /* Sin formulario si no se puede participar: el envío se rechazaría igualmente. */
            if (!$this->isActive($db, $surveyid)) {
                echo ("<p><strong>La consulta <em>" . h($surveyname) .
                    "</em> no está abierta.</strong></p>");
                return;
            }
            if (!showSurveyHeader($db, $surveyid, true))
                return;

            $this->showEmailForm($surveyid, [
                'action' => self::PAGE,
                'title' => "Participar en la consulta",
                'infotitle' => "Cómo participar",
                'info' => "Indica tu dirección de correo.\n\n" .
                    "SI YA HAS PARTICIPADO en otra consulta, recibirás directamente un mensaje " .
                    "con un enlace personal para participar, que caduca en una hora.\n\n" .
                    "SI ES LA PRIMERA VEZ, a continuación se te pedirán dos documentos. Si son " .
                    "válidos recibirás el mensaje con el enlace.\n" .
                    "Los documentos solo se usan para comprobar que son válidos y que son " .
                    "de la misma persona. Con el DNI que figura en el extracto se evita " .
                    "que una misma persona participe dos veces. Los ficheros no se guardan.\n\n" .
                    "Si quieres pensarte las respuestas antes de enviar tus datos, puedes " .
                    "verlas en «Ver las preguntas de la consulta», debajo de este recuadro.",
                'emailinfo' => "Revísala bien antes de enviar: si la tecleas mal no recibirás " .
                    "el enlace y ya no podrás votar.",
                'emailhint' => "Revísala bien: ahí recibirás el enlace.",
                'button' => "Participar en la consulta",
            ]);
        ?>
            <details class="ml-survey-preview">
                <summary>Ver las preguntas de la consulta</summary>
                <?php showSurveyQuestions($db, $surveyid, true); ?>
            </details>
        <?php
        } catch (Exception $e) {
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} getting survey for code.");
        }
    }

    /**
     * Called with $withdocs false from the email form and true from the documents one.
     * In the first case, if the email address is not in Participants table the method
     * only shows the documents form.
     *
     * In the second case the documents are checked and the participant is looked for
     * or inserted in Participants table by RegistrationView::registerWithDocs.
     *
     * Then, if the participant hasn't participated in the survey, the method
     * generates a random 256bit key, encrypts the email address with this key
     * and stores the key (hashed), the encrypted address and the
     * survey ID in the Participation table. Then it sends this information to the email address
     * formatted as an URL.
     */
    private function generateCode(bool $withdocs)
    {
        $surveyid = $_REQUEST['surveyid'] ?? null;
        if (!is_string($surveyid) || !ctype_digit($surveyid)) {
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return;
        }
        if ($withdocs && !$this->checkConsent("participar"))
            return;
        $email = $this->getEmail();
        if ($email === null)
            return;
        $start_time = microtime(true);
        $surveyname = "";
        try {

            $db = dbConn();
            /* El nombre va tal cual en el correo y escapado en la página. */
            $mailsurveyname = $this->getSurveyName($db, $surveyid);
            if ($mailsurveyname === null) {
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                return;
            }
            $surveyname = h($mailsurveyname);
            if (!$this->isActive($db, $surveyid)) {
                echo ("<p><strong>La consulta <em>{$surveyname}</em> no está abierta.</strong></p>");
                return;
            }
            if (!$this->checkDomain($db, $email)) {
                return;
            }
            $hashemail = hash('sha256', $email);
            /* Primer paso con una dirección que aún no está registrada: antes
               de enviarle el enlace tiene que acreditarse con los documentos. */
            if (!$withdocs && $this->getParticipantId($db, $hashemail) === null) {
                if (showSurveyHeader($db, $surveyid, true))
                    $this->showDocsForm($surveyid, $email, [
                        'action' => self::PAGE,
                        'back' => self::PAGE . "?responseid=" . (int) $surveyid,
                        'title' => "Primera vez: acredita que eres docente",
                        'intro' => "no ha participado antes. Adjunta dos documentos y te enviaremos " .
                            "a esa dirección un enlace para participar.",
                    ]);
                return;
            }
            if ($withdocs) {
                $reserved = $this->registerWithDocs(
                    $db,
                    $email,
                    fn($participantid) =>
                    $this->reserveParticipation($db, $email, $hashemail, $participantid, $surveyid)
                );
            } else {
                if (!$this->lockEmail($db, $hashemail))
                    return;
                try {
                    $reserved = $this->reserveCodeEmail($db, $email, $hashemail, $surveyid);
                } finally {
                    unlockParticipant($db, $hashemail);
                }
            }
            if ($reserved === null)
                return;
            [$pid, $code] = $reserved;
            $end_time = microtime(true);
            $exectime = $end_time - $start_time;
            logMessage(LOGGER_DEBUG, "Get code exec time {$exectime}.");
            $mailer = new MlMailer();
            $mailer->configure();
            $mailer->sendCode($email, $pid, url_base64_encode($code), $mailsurveyname);
            echo ("<p><strong>El código para participar en la consulta <em>{$surveyname}</em> " .
                "ha sido enviado a la dirección indicada.</strong></p>");
        } catch (Exception $e) {
            echo ("<p><strong>Error generando código para la consulta <em>{$surveyname}</em></strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when generating code for survey");
        }
    }

    /* Guarda la petición de código de una dirección ya registrada. Se llama
       con el bloqueo del correo cogido. Devuelve [pid, código], o null si no
       procede (ya ha participado o ya pidió uno hace poco). */
    private function reserveCodeEmail($db, $email, $hashemail, $surveyid)
    {
        $participantid = $this->getParticipantId($db, $hashemail);
        if ($participantid === null) {
            echo ("<p><strong>La dirección de correo indicada no ha participado anteriormente.</strong></p>");
            return null;
        }
        return $this->reserveParticipation($db, $email, $hashemail, $participantid, $surveyid);
    }

    /* Con la participante ya identificada y su bloqueo cogido: comprueba que no ha votado ni pedido un código hace poco y guarda la
       petición. Devuelve [pid, código], o null (con el motivo ya mostrado). */
    private function reserveParticipation($db, $email, $hashemail, $participantid, $surveyid)
    {
        /*if (hasCode ($db, $participantid, $surveyid)){ //Echar un vistazo
            //Needs a time limit.
            echo ("<p><strong>La dirección de correo indicada ya ha solicitado un código para esta consulta</strong></p>");
            return null;
        }*/
        if (hasParticipated($db, $participantid, $surveyid)) {
            echo ("<p><strong>Ya se ha votado en esta consulta con la dirección de correo indicada.</strong></p>");
            return null;
        }

        /* participant va cifrado con un código aleatorio distinto en cada
           petición, así que no sirve para buscar las anteriores: el límite
           por hora se comprueba con esta etiqueta fija por email y consulta. */
        $requesttag = hash('sha256', $hashemail . ':' . $surveyid);
        if ($this->checkParticipation($db, $requesttag, $surveyid)) {
            return null;
        }

        $code = random_bytes(32);
        $passwd = hash('sha256', $code);
        $encryptedemail = base64_encode(encrypt($email, $code));
        $query = $db->prepare("INSERT into {Participation} (participant, surveyid, participationkey, requesttag) " .
            "values (:id, :sid, :pwd, :tag)");
        $query->bindParam(":id", $encryptedemail, PDO::PARAM_STR);
        $query->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $query->bindParam(":pwd", $passwd, PDO::PARAM_STR);
        $query->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $query->execute();
        return [$db->lastInsertId(), $code];
    }

    private function getSurveyName($db, $sid): ?string
    {
        $query = $db->prepare("SELECT surveyname FROM {Surveys} WHERE surveyid = :sid");
        $query->bindParam(":sid", $sid, PDO::PARAM_INT);
        $query->execute();
        $name = $query->fetchColumn();
        $query->closeCursor();
        return $name === false ? null : $name;
    }

    private function isActive($db, $sid)
    {
        $query = $db->prepare("SELECT 1 FROM {Surveys} WHERE surveyid = :sid
            AND startdate < NOW() AND enddate > NOW()");
        $query->bindParam(":sid", $sid, PDO::PARAM_INT);
        $query->execute();
        $active = $query->rowCount() > 0;
        $query->closeCursor();
        return $active;
    }

    private function checkParticipation($db, $requesttag, $sid)
    {

        $ret = false;
        $query = $db->prepare("SELECT 1 FROM {Participation} WHERE 
            requesttag = :tag AND surveyid = :sid
            AND participationdate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $query->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $query->bindParam(":sid", $sid, PDO::PARAM_INT);
        $query->execute();
        if ($query->rowCount() > 0) {
            echo ("<p><strong>Ya existe una peticion de participación 
                para esta consulta con la dirección de correo indicada.</strong></p>");
            echo ("Podrás realizar una nueva petición en una hora.");
            $ret = true;
        }
        $query->closeCursor();
        return $ret;
    }
}
