<?php

/**
 * This class shows the page for registering a new participant without asking
 * for a survey.
 */

require_once 'ifaces/registrationview.php';
require_once 'utils/dbutils.php';
require_once 'include/mlmailer.php';

class Register extends RegistrationView
{

    private const PAGE = "register";

    function getMenuGroup()
    {
        return ML_MENU_GROUP_REGISTER;
    }

    /**
     * Depending on the request parameters the class takes different actions:
     * - If it's not a submit, shows the form asking for the email address.
     * - If it's the submit of that form and the address is already registered, says so.
     * If it isn't, shows the form asking for the pension scheme and the two PDFs.
     * - If it's the submit of the second form, checks the PDFs, stores the participant
     * in Participants table and sends an email confirming the registration.
     */
    public function show()
    {
        if ($this->isSubmit()) {
            if ($this->checkSubmit())
                $this->register($this->isDocsSubmit());
            return;
        }
        $this->showEmailForm(null, [
            'action' => self::PAGE,
            'title' => "Regístrate",
            'infotitle' => "Cómo registrarse",
            'info' => "Indica tu dirección de correo.\n\n" .
                "A continuación se te pedirán dos documentos. Si son válidos quedarás " .
                "registrado/a y recibirás un mensaje de confirmación.\n" .
                "Los documentos solo se usan para comprobar que son válidos y que son " .
                "de la misma persona. Con el DNI que figura en el extracto se evita " .
                "que una misma persona se registre dos veces. Los ficheros no se guardan.\n\n" .
                "Una vez registrado/a, para participar en una consulta solo tendrás que " .
                "indicar tu dirección de correo.",
            'emailinfo' => "Revísala bien antes de enviar: si la tecleas mal no recibirás " .
                "los enlaces para participar y ya no podrás registrarte con otra.",
            'emailhint' => "Revísala bien: ahí recibirás la confirmación.",
            'button' => "Registrarme",
        ]);
    }

    /**
     * Called with $withdocs false from the email form and true from the documents one.
     * In the first case only checks the email address and shows the documents form.
     * In the second one the documents are checked and the participant is inserted in
     * Participants table by RegistrationView::registerWithDocs, and the confirmation
     * email is sent.
     */
    private function register(bool $withdocs)
    {
        if ($withdocs && !$this->checkConsent("registrarse"))
            return;
        $email = $this->getEmail();
        if ($email === null)
            return;
        try {
            $db = dbConn();
            if (!$this->checkDomain($db, $email)) {
                return;
            }
            if (!$withdocs) {
                if ($this->getParticipantId($db, hash('sha256', $email)) !== null) {
                    $this->alreadyRegistered();
                    return;
                }
                $this->showDocsForm(null, $email, [
                    'action' => self::PAGE,
                    'back' => self::PAGE,
                    'title' => "Acredita que eres docente",
                    'intro' => "aún no está registrada. Adjunta dos documentos y te " .
                        "enviaremos a esa dirección la confirmación del registro.",
                ]);
                return;
            }
            $created = $this->registerWithDocs($db, $email, fn($participantid, $created) => $created);
            if ($created === null)
                return;
            if (!$created) {
                $this->alreadyRegistered();
                return;
            }
        } catch (Exception $e) {
            echo ("<p><strong>Error al registrar la dirección de correo.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when registering participant");
            return;
        }
        /* El registro ya está hecho y la tabla no admite borrados: si falla
           el correo no se puede deshacer, así que solo se avisa. */
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
        $this->showSurveysLink();
    }

    private function alreadyRegistered()
    {
        echo ("<p><strong>La dirección de correo indicada ya está registrada.</strong></p>");
        $this->showSurveysLink();
    }

    private function showSurveysLink()
    {
        echo ("<p>Ya puedes participar en las <a href='surveys'>consultas activas</a> " .
            "indicando solo tu dirección de correo.</p>");
    }
}
