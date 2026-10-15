<?php

/**
 * This class shows the page for sending again the participation URL to someone
 * who has already requested it.
 * It isn't in the navigation menu: the link to it is in the message GetCode shows
 * when there is already a request for the email address.
 */

require_once 'views/get_code.php';

class Resend extends GetCode
{

    private const PAGE = "resend";
    /* Minutos que tienen que pasar entre un envío del enlace y el siguiente. */
    private const WAIT_MINUTES = 5;

    /**
     * Depending on the request parameters the class takes different actions:
     * - If it's a GET with survey info, shows the form asking for the email address.
     * - If it's the submit of that form and the address has a request in force for
     * the survey, sends a new URL and shows the result (success or failure).
     */
    public function show()
    {
        if ($this->isSubmit()) {
            if ($this->checkSubmit())
                $this->resendCode();
            return;
        }

        $surveyid = $_GET['responseid'] ?? null;
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

            $this->showEmailForm($surveyid, [
                'action' => self::PAGE,
                'title' => "Reenviar el enlace para participar",
                'infotitle' => "Reenviar el enlace",
                'info' => "Si ya has solicitado participar en esta consulta y no te ha llegado " .
                    "el mensaje, indica la misma dirección de correo y te enviaremos un " .
                    "enlace nuevo.\n\n" .
                    "El enlace nuevo caduca en una hora y sustituye a los anteriores, que " .
                    "dejan de funcionar.\n\n" .
                    "Entre un envío y el siguiente tienen que pasar " . self::WAIT_MINUTES .
                    " minutos.\n\n" .
                    "Antes de pedirlo, mira en la carpeta de correo no deseado.",
                'emailinfo' => "Tiene que ser la dirección con la que solicitaste participar.",
                'emailhint' => "La misma con la que solicitaste participar.",
                'button' => "Reenviar el enlace",
            ]);
        } catch (Exception $e) {
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} getting survey for resending code.");
        }
    }

    /**
     * The code of the URL isn't stored (only its hash, and the email address is
     * encrypted with it), so the URL already sent can't be built again: the method
     * stores a new request, deletes the previous ones of that address for the survey
     * and sends the new URL.
     * There must be WAIT_MINUTES between a sending and the next one.
     */
    private function resendCode()
    {
        $surveyid = $_REQUEST['surveyid'] ?? null;
        if (!is_string($surveyid) || !ctype_digit($surveyid)) {
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return;
        }
        $email = $this->getEmail();
        if ($email === null)
            return;
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
            $hashemail = participantHash($email);
            if (!$this->lockEmail($db, $hashemail))
                return;
            try {
                $reserved = $this->renewParticipation($db, $email, $hashemail, $surveyid);
            } finally {
                unlockParticipant($db, $hashemail);
            }
            if ($reserved === null)
                return;
            [$pid, $code] = $reserved;
            $mailer = new MlMailer();
            $mailer->configure();
            $mailer->sendCode($email, $pid, url_base64_encode($code), $mailsurveyname);
            echo ("<p><strong>Hemos enviado un enlace nuevo para participar en la consulta " .
                "<em>{$surveyname}</em> a la dirección indicada.</strong></p>");
            echo ("<p>Los enlaces anteriores han dejado de funcionar.</p>");
        } catch (Exception $e) {
            echo ("<p><strong>Error reenviando el enlace para la consulta <em>{$surveyname}</em></strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when resending code for survey");
        }
    }

    /* Se llama con el bloqueo del correo cogido. Sustituye las peticiones en
       vigor de esa dirección para la consulta por una nueva. Devuelve
       [pid, código], o null (con el motivo ya mostrado) si no hay ninguna
       que reenviar o la última es demasiado reciente. */
    private function renewParticipation($db, $email, $hashemail, $surveyid)
    {
        $request = "<a href='get_code?responseid=" . (int) $surveyid . "'>solicitar participar</a>";
        $participantid = $this->getParticipantId($db, $hashemail);
        if ($participantid === null) {
            echo ("<p><strong>No hay ninguna petición de participación para esta consulta con " .
                "la dirección de correo indicada.</strong></p>");
            echo ("<p>Puedes {$request} en la consulta.</p>");
            return null;
        }
        if (hasParticipated($db, $participantid, $surveyid)) {
            echo ("<p><strong>Ya se ha votado en esta consulta con la dirección de correo indicada.</strong></p>");
            return null;
        }
        $requesttag = $this->requestTag($hashemail, $surveyid);
        /* Segundos desde el último envío; null si no hay ninguno en vigor. */
        $query = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(participationdate), NOW())
            FROM {Participation} WHERE
            requesttag = :tag AND surveyid = :sid
            AND participationdate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $query->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $query->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $query->execute();
        $elapsed = $query->fetchColumn();
        $query->closeCursor();
        if ($elapsed === null || $elapsed === false) {
            echo ("<p><strong>No hay ninguna petición de participación en vigor para esta " .
                "consulta con la dirección de correo indicada.</strong></p>");
            echo ("<p>Puedes {$request} de nuevo en la consulta.</p>");
            return null;
        }
        $wait = self::WAIT_MINUTES * 60 - (int) $elapsed;
        if ($wait > 0) {
            $minutes = (int) ceil($wait / 60);
            $retry = "<a href='" . self::PAGE . "?responseid=" . (int) $surveyid . "'>pedir el reenvío</a>";
            echo ("<p><strong>Hace menos de " . self::WAIT_MINUTES . " minutos que te enviamos el " .
                "enlace para participar en esta consulta: aún no se puede reenviar.</strong></p>");
            echo ("<p>Podrás {$retry} dentro de " .
                ($minutes == 1 ? "1 minuto" : "{$minutes} minutos") . ". Mientras tanto, revisa " .
                "la bandeja de entrada y la carpeta de correo no deseado: el mensaje puede " .
                "tardar un poco en llegar. El enlace que ya te enviamos sigue siendo válido.</p>");
            return null;
        }

        $reserved = $this->insertParticipation($db, $email, $requesttag, $surveyid);
        $delete = $db->prepare("DELETE FROM {Participation} WHERE
            requesttag = :tag AND surveyid = :sid AND participationid <> :pid");
        $delete->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $delete->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $delete->bindParam(":pid", $reserved[0], PDO::PARAM_INT);
        $delete->execute();
        return $reserved;
    }
}
