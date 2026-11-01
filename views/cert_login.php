<?php

/**
 * This class lets participate in a survey identifying with a digital certificate
 * (TLS client certificate) instead of the email address.
 * - If the DNI of the certificate is already registered, the survey form is shown
 * right away.
 * - If it isn't, the documents wizard is shown (the email address is asked for in
 * its last step). The DNI of the documents must be the one of the certificate; once
 * registered, the survey form is shown.
 *
 * The certificate is asked for by Apache (see README.md): this page must be served
 * where SSLVerifyClient is set, and so must be the submits of its forms (they go to
 * relative URLs).
 */

require_once 'views/get_code.php';
require_once 'views/participate.php';
require_once 'utils/clientcert.php';
require_once 'utils/host.php';

class CertLogin extends GetCode
{

    private const PAGE = "cert_login";

    /**
     * - A GET with the survey (cert_login?responseid=N) checks the certificate and shows
     * the survey form or, if not registered, the documents wizard.
     * - The submit of the wizard checks the certificate again, registers the participant
     * and shows the survey form.
     */
    public function show()
    {
        if (!clientCertEnabled()) {
            echo ("<p><strong>La identificación con certificado digital no está " .
                "disponible.</strong></p>");
            return;
        }
        $submit = $this->isDocsSubmit();
        $surveyid = $submit ? ($_REQUEST['surveyid'] ?? null) : ($_GET['responseid'] ?? null);
        if (!is_string($surveyid) || !ctype_digit($surveyid)) {
            if ($submit)
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            else
                showMain();
            return;
        }
        if ($submit && !$this->checkSubmit())
            return;
        try {
            $db = dbConn();
            $surveyname = $this->getSurveyName($db, $surveyid);
            if ($surveyname === null) {
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                return;
            }
            if (!$this->isActive($db, $surveyid)) {
                echo ("<p><strong>La consulta <em>" . h($surveyname) .
                    "</em> no está abierta.</strong></p>");
                return;
            }
            /* El certificado llega en cada petición: no se guarda en la sesión. */
            try {
                $dni = getClientCertDni();
            } catch (ClientCertException $e) {
                echo ("<p><strong>" . h($e->getMessage()) . "</strong></p>");
                $this->showBackLink($surveyid);
                return;
            }
            if ($submit)
                $participantid = $this->registerCert($db, $dni, $surveyid);
            else
                $participantid = $this->findCert($db, $dni, $surveyid);
            if ($participantid === null)
                return;
            if (!$this->canVote($db, $participantid, $surveyid))
                return;
            $formid = Participate::saveParticipation((int) $surveyid, $participantid);
            Participate::showSurvey($db, $surveyid, $formid);
        } catch (Exception $e) {
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} in certificate login.");
        }
    }

    /* Participante registrada con el DNI del certificado. Si no lo está,
       muestra el asistente de los documentos y devuelve null. */
    private function findCert($db, string $dni, $surveyid): ?int
    {
        $participantid = $this->getParticipantIdByDni($db, participantHash($dni));
        if ($participantid !== null)
            return $participantid;
        if (showSurveyHeader($db, $surveyid, true))
            $this->showWizard($surveyid, []);
        return null;
    }

    /* Envío del asistente: registra a la participante con los documentos,
       que tienen que ser del DNI del certificado. Devuelve su id, o null (con
       el motivo ya mostrado y el asistente otra vez) si no se ha podido. */
    private function registerCert($db, string $dni, $surveyid): ?int
    {
        $participantid = null;
        $created = false;
        $email = null;
        if ($this->checkConsent("participar") && ($email = $this->getEmail()) !== null &&
            $this->checkDomain($db, $email)
        ) {
            $participantid = $this->registerWithDocs(
                $db,
                $email,
                function ($id, $new) use (&$created) {
                    $created = $new;
                    return $id;
                },
                $dni
            );
        }
        if ($participantid === null) {
            $selected = [];
            foreach (['regimen', 'tipo', 'email'] as $field)
                if (isset($_REQUEST[$field]) && is_string($_REQUEST[$field]))
                    $selected[$field] = trim($_REQUEST[$field]);
            if (showSurveyHeader($db, $surveyid, true))
                $this->showWizard($surveyid, $selected);
            return null;
        }
        if ($created)
            $this->sendRegistered($email);
        return (int) $participantid;
    }

    /* Ya registrada: solo se avisa por correo, el voto no depende de ello. */
    private function sendRegistered(string $email)
    {
        try {
            $mailer = new MlMailer();
            $mailer->configure();
            $mailer->sendRegistered($email);
            echo ("<p><strong>Te has registrado correctamente. Hemos enviado un mensaje de " .
                "confirmación a la dirección indicada.</strong></p>");
        } catch (Exception $e) {
            echo ("<p><strong>Te has registrado correctamente, pero no se ha podido enviar " .
                "el mensaje de confirmación.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} sending registration email");
        }
    }

    private function showWizard($surveyid, array $selected)
    {
        $this->showDocsForm($surveyid, null, [
            'action' => self::PAGE,
            'title' => "Primera vez: acredita que eres docente",
            'intro' => "Te has identificado con tu certificado digital, pero aún no estás " .
                "registrado/a. Acredita que eres docente de la enseñanza pública en tres pasos: " .
                "los documentos tienen que ser de la persona del certificado. Al terminar " .
                "podrás votar directamente.",
            'emailinfo' => "En próximas consultas podrás participar con tu certificado digital " .
                "o con esta dirección.",
            'emailhint' => "Revísala bien: ahí recibirás la confirmación del registro.",
        ], $selected);
    }

    /* Mismas comprobaciones que antes de mostrar el formulario por correo;
       al enviarlo se vuelve a comprobar que no ha votado. */
    private function canVote($db, int $participantid, $surveyid): bool
    {
        if (hasParticipated($db, $participantid, $surveyid)) {
            echo ("<p><strong>Ya se ha votado en esta consulta con este DNI.</strong></p>");
            return false;
        }
        if (checkUnsubscribe($db, $participantid)) {
            echo "<p><strong>No puedes participar en la consulta porque has confirmado
                la solicitud de baja del registro de participantes.</strong></p>";
            return false;
        }
        return true;
    }

    private function getParticipantIdByDni(PDO $db, string $hashdni): ?int
    {
        $query = $db->prepare("SELECT participantid FROM {Participants}
            WHERE dnihashed = :dni LIMIT 1");
        $query->bindParam(":dni", $hashdni, PDO::PARAM_STR);
        $query->execute();
        $participantid = $query->fetchColumn();
        $query->closeCursor();
        return $participantid === false ? null : (int) $participantid;
    }

    private function showBackLink($surveyid)
    {
        /* La página puede estar en otro host: se vuelve al sitio. */
        $url = getURL() . "get_code?responseid=" . (int) $surveyid;
        echo ("<p><a href='" . h($url) . "'>Elegir otro método de identificación</a></p>");
    }
}
