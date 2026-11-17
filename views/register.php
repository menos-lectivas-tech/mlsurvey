<?php

/**
 * This class shows the page for registering a new participant without asking
 * for a survey.
 */

require_once 'ifaces/registrationview.php';
require_once 'utils/dbutils.php';
require_once 'include/mlmailer.php';
require_once 'utils/crypt.php';
require_once 'utils/clientcert.php';
require_once 'utils/host.php';

class Register extends RegistrationView
{

    private const PAGE = "register";
    /* Segundos que se muestra el error del certificado antes de volver al sitio. */
    private const CERT_ERROR_DELAY = 8;

    /* Motivo por el que no vale el certificado electrónico, o null si vale
       (o no se pide). */
    private ?string $certerror = null;
    /* true si el DNI del certificado ya está registrado. */
    private bool $dniregistered = false;

    function getMenuGroup()
    {
        return ML_MENU_GROUP_REGISTER;
    }

    /* El asistente se muestra ya en la URL del registro, la que pide el
       certificado electrónico: allí se genera su token y su CAPTCHA, y allí
       llega su envío con la misma sesión. Si se mandara desde el sitio, el
       navegador podría no enviar a la URL del registro (otro puerto) la
       cookie de sesión del sitio. Desde el sitio no se va directamente: antes
       se avisa de que se pedirá el certificado (ver showCertNotice). */
    public function doInit()
    {
        /* En la URL del registro: sin un certificado válido no se puede
           registrar, así que se avisa y se vuelve al sitio (el que no pide
           certificado). */
        if (clientCertEnabled() && !$this->mustGoToRegistration()) {
            try {
                $this->dniregistered = $this->isDniRegistered(getClientCertDni());
            } catch (ClientCertException $e) {
                $this->certerror = $e->getMessage();
                $site = $this->siteURL();
                if ($site !== null)
                    header("Refresh: " . self::CERT_ERROR_DELAY . "; url=" . $site);
            }
        }
    }

    /* true si ya hay una participante registrada con ese DNI. Si falla la
       consulta se deja seguir: el registro lo vuelve a comprobar. */
    private function isDniRegistered(string $dni): bool
    {
        try {
            return $this->getParticipantIdByDni(dbConn(), participantHash($dni)) !== null;
        } catch (Exception $e) {
            logMessage(LOGGER_ERROR, "Error {$e} checking if the DNI is registered");
            return false;
        }
    }

    private function showDniRegistered()
    {
        echo ("<p><strong>Ya hay un usuario registrado con el DNI de tu certificado " .
            "electrónico.</strong></p>");
        $this->showSurveysLink();
    }

    /* URL del sitio, sin certificado, o null si no está configurada. */
    private function siteURL(): ?string
    {
        try {
            return getURL();
        } catch (Exception $e) {
            logMessage(LOGGER_ERROR, $e->getMessage());
            return null;
        }
    }

    /* En el sitio, antes de ir a la URL del registro: avisa en un diálogo
       de que el navegador pedirá el certificado electrónico, y va allí si
       se acepta o a la página principal si no. Sin JavaScript queda el
       mismo aviso con un enlace. */
    private function showCertNotice()
    {
        $title = "Se te pedirá tu certificado electrónico";
        $message = "Para registrarte, el navegador te pedirá que selecciones tu certificado " .
            "electrónico personal (DNI electrónico, certificado de la FNMT, de empleado/a " .
            "público/a...). El DNI del certificado debe coincidir con el del documento que aportes.";
        $target = $this->registrationAction(self::PAGE);
        $site = $this->siteURL() ?? ".";
    ?>
        <section class="ml-participate-card">
            <h3>Regístrate</h3>
            <p><strong><?= h($title); ?></strong></p>
            <p><?= h($message); ?></p>
            <p><a class="button-3" href="<?= h($target); ?>">Continuar</a></p>
        </section>
        <script>
            mlDialog.confirm({
                title: <?= json_encode($title); ?>,
                message: <?= json_encode($message); ?>,
                confirmText: "Continuar"
            }).then(function(ok) {
                window.location.href = ok ? <?= json_encode($target); ?> : <?= json_encode($site); ?>;
            });
        </script>
    <?php
    }

    private function showCertError()
    {
        echo ("<p><strong>Para registrarte debes aportar un certificado digital válido.</strong></p>");
        echo ("<p>" . h($this->certerror) . "</p>");
        $site = $this->siteURL();
        if ($site !== null)
            echo ("<p>En unos segundos volverás a la <a href='" . h($site) . "'>página principal</a>.</p>");
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
        if ($this->certerror !== null) {
            $this->showCertError();
            return;
        }
        if ($this->dniregistered) {
            $this->showDniRegistered();
            return;
        }
        if (!$this->isDocsSubmit() && $this->mustGoToRegistration()) {
            $this->showCertNotice();
            return;
        }
        if ($this->isDocsSubmit()) {
            /* El envío va a la URL del registro, que pide el certificado. */
            if ($this->mustGoToRegistration()) {
                $this->notRegistrationHost();
                $this->showWizard(true);
                return;
            }
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
