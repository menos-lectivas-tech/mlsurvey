<?php

/**
 * This class shows the page for registering a new participant without asking
 * for a survey.
 */

require_once 'ifaces/registrationview.php';
require_once 'utils/dbutils.php';
require_once 'include/mlmailer.php';
require_once 'utils/crypt.php';

class Register extends RegistrationView
{

    private const PAGE = "register";

    function getMenuGroup()
    {
        return ML_MENU_GROUP_REGISTER;
    }

    /**
     * The page is the documents wizard itself: the email address is asked for in
     * its last step, together with the PDFs.
     * - If it's not a submit, shows the wizard.
     * - If it's the submit of the wizard, checks the address and the PDFs, stores
     * the participant in Participants table and sends an email confirming the
     * registration. If something is wrong, shows the reason and the wizard again
     * with what was chosen.
     */
    public function show()
    {
        if ($this->isDocsSubmit()) {
            if ($this->checkSubmit() && $this->register())
                return;
            $this->showWizard(true);
            return;
        }
        $this->showWizard(false);
    }

    /* $retry: se vuelve a mostrar tras un envío que no ha valido, con lo
       que se eligió. Los ficheros no se pueden conservar. */
    private function showWizard(bool $retry)
    {
        $selected = [];
        if ($retry) {
            foreach (['regimen', 'tipo', 'email'] as $field)
                if (isset($_REQUEST[$field]) && is_string($_REQUEST[$field]))
                    $selected[$field] = trim($_REQUEST[$field]);
        }
        $this->showDocsForm(null, null, [
            'action' => self::PAGE,
            'title' => "Regístrate",
            'intro' => "Acredita que eres docente de la enseñanza pública en tres pasos. " .
                "Al final indicarás tu dirección de correo y te enviaremos la confirmación " .
                "del registro. Una vez registrado/a, para participar en una consulta solo " .
                "tendrás que indicar esa dirección.",
            'emailinfo' => "Revísala bien antes de enviar: si la tecleas mal no recibirás " .
                "los enlaces para participar y ya no podrás registrarte con otra.",
            'emailhint' => "Revísala bien: ahí recibirás la confirmación.",
        ], $selected);
    }

    /**
     * Checks the address and the documents and registers the participant: the
     * address must be valid, of an allowed domain and not registered yet; then
     * the documents are checked and the participant is inserted in Participants
     * table by RegistrationView::registerWithDocs, and the confirmation email is
     * sent.
     *
     * @return bool true if it's done (registered, or the address already was), false
     * (with the reason already shown) if the wizard should be shown again.
     */
    private function register(): bool
    {
        if (!$this->checkConsent("registrarse"))
            return false;
        $email = $this->getEmail();
        if ($email === null)
            return false;
        try {
            $db = dbConn();
            if (!$this->checkDomain($db, $email))
                return false;
            /* Antes de mirar los documentos: no hace falta procesarlos. */
            if ($this->getParticipantId($db, participantHash($email)) !== null) {
                $this->alreadyRegistered();
                return true;
            }
            $created = $this->registerWithDocs($db, $email, fn($participantid, $created) => $created);
            if ($created === null)
                return false;
            if (!$created) {
                $this->alreadyRegistered();
                return true;
            }
        } catch (Exception $e) {
            echo ("<p><strong>Error al registrar la dirección de correo.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when registering participant");
            return false;
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
        return true;
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
