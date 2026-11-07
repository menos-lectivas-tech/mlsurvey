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
     * The page is the form for uploading the signed declaration: the email address
     * is not asked for, it is read from the declaration.
     * - If it's not a submit, shows the form.
     * - If it's the submit of the form, checks the declaration, stores the
     * participant in Participants table and sends an email confirming the
     * registration. If something is wrong, shows the reason and the form again.
     */
    public function show()
    {
        if ($this->isDeclaracionSubmit() && $this->checkSubmit() && $this->register())
            return;
        $this->showDeclaracionForm(null, [
            'action' => self::PAGE,
            'title' => "Regístrate",
            'intro' => "Para registrarte tienes que declarar, firmando digitalmente con tu " .
                "certificado, que eres docente de la enseñanza pública. Te registraremos con la dirección de correo " .
                "de EducaMadrid que indiques en ella y te enviaremos allí la confirmación. Una vez " .
                "registrado/a, para participar en una consulta solo tendrás que indicar esa " .
                "dirección y recibirás en ella el enlace para votar.",
        ]);
    }

    /**
     * Checks the declaration and registers the participant with the DNI and the
     * email address read from it (RegistrationView::registerWithDeclaracion), then sends
     * the confirmation email.
     *
     * @return bool true if it's done (registered, or the address already was), false
     * (with the reason already shown) if the form should be shown again.
     */
    private function register(): bool
    {
        if (!$this->checkConsent("registrarse"))
            return false;
        try {
            $db = dbConn();
            $result = $this->registerWithDeclaracion($db, fn($participantid, $created, $email) => [$created, $email]);
            if ($result === null)
                return false;
            [$created, $email] = $result;
            $this->showSignedCopy();
            if (!$created) {
                $this->alreadyRegistered($email);
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
            echo ("<p><strong>Te has registrado correctamente con la dirección " . h($email) .
                ". Te hemos enviado un mensaje de confirmación.</strong></p>");
        } catch (Exception $e) {
            echo ("<p><strong>Te has registrado correctamente con la dirección " . h($email) .
                ", pero no se ha podido enviar el mensaje de confirmación.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} sending registration email");
        }
        $this->showSurveysLink();
        return true;
    }

    private function alreadyRegistered(string $email)
    {
        echo ("<p><strong>La dirección " . h($email) . " ya está registrada.</strong></p>");
        $this->showSurveysLink();
    }

    private function showSurveysLink()
    {
        echo ("<p>Ya puedes participar en las <a href='surveys'>consultas activas</a> " .
            "indicando solo tu dirección de correo.</p>");
    }
}
