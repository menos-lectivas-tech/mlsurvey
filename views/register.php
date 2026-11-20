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
require_once 'utils/verification.php';

class Register extends RegistrationView
{

    private const PAGE = "register";
    /* Segundos que se muestra el error del certificado antes de volver al sitio. */
    private const CERT_ERROR_DELAY = 8;

    /* Motivo por el que no vale el certificado electrónico, o null si vale
       (o no se pide). */
    private ?string $certerror = null;
    /* DNI del certificado electrónico, si se pide y es válido. */
    private ?string $certdni = null;
    /* Participante ya registrada con el DNI del certificado (participantid,
       participant), o null si no hay. */
    private ?array $dniparticipant = null;

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
                $this->certdni = getClientCertDni();
                $this->dniparticipant = $this->findByDni($this->certdni);
            } catch (ClientCertException $e) {
                $this->certerror = $e->getMessage();
                $site = $this->siteURL();
                if ($site !== null)
                    header("Refresh: " . self::CERT_ERROR_DELAY . "; url=" . $site);
            }
        }
    }

    /* La participante registrada con ese DNI, o null si no hay. Si falla la
       consulta se deja seguir: el registro lo vuelve a comprobar. */
    private function findByDni(string $dni): ?array
    {
        try {
            return $this->participantByDni(dbConn(), participantHash($dni));
        } catch (Exception $e) {
            logMessage(LOGGER_ERROR, "Error {$e} checking if the DNI is registered");
            return null;
        }
    }

    private function participantByDni(PDO $db, string $hashdni): ?array
    {
        $query = $db->prepare("SELECT participantid, participant FROM {Participants} " .
            "WHERE dnihashed = :dni LIMIT 1");
        $query->bindParam(":dni", $hashdni, PDO::PARAM_STR);
        $query->execute();
        $row = $query->fetch();
        $query->closeCursor();
        return $row === false ? null : $row;
    }

    /* El DNI del certificado ya tiene una cuenta: si está verificada solo
       queda votar; si no, se ofrece enviar otra vez el enlace. */
    private function showDniRegistered()
    {
        try {
            $verified = isVerified(dbConn(), (int) $this->dniparticipant['participantid']);
        } catch (Exception $e) {
            echo ("<p><strong>Error al consultar tu registro.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} checking if the account is verified");
            return;
        }
        if ($verified) {
            try {
                $unsubscribed = checkUnsubscribe(dbConn(), (int) $this->dniparticipant['participantid']);
            } catch (Exception $e) {
                logMessage(LOGGER_ERROR, "Error {$e} checking if the participant is unsubscribed");
                $unsubscribed = false;
            }
            if ($unsubscribed) {
                $this->showUnsubscribed();
                return;
            }
            echo ("<p><strong>Ya estás registrado/a con el DNI de tu certificado electrónico " .
                "y tu cuenta está verificada.</strong></p>");
            $this->showSurveysLink();
            $this->showUnregister(self::PAGE);
            return;
        }
        $this->showPendingVerification();
    }

    /* Cuenta registrada sin verificar. Si el último enlace se envió hace
       menos de VERIFY_WAIT_MINUTES solo se recuerda que lo abra; si no, se
       muestra el formulario para pedir otro. */
    private function showPendingVerification()
    {
        try {
            $wait = secondsUntilResend(dbConn(), (int) $this->dniparticipant['participantid']);
        } catch (Exception $e) {
            logMessage(LOGGER_ERROR, "Error {$e} checking when the link can be resent");
            $wait = 0;
        }
        echo ("<p><strong>Ya te has registrado con el DNI de tu certificado electrónico, pero tu " .
            "cuenta aún no está activada.</strong></p>");
        if ($wait > 0) {
            $this->showEmailSent($wait);
            return;
        }
        echo ("<p>Para activarla tienes que pinchar en el enlace que te enviamos por correo " .
            "(mira también en la carpeta de correo no deseado).</p>");
        echo ("<p>Si no te ha llegado, o ya ha caducado (vale " . VERIFY_MINUTES . " minutos), " .
            "escribe aquí tu dirección de correo y te enviaremos un enlace nuevo; el anterior " .
            "dejará de funcionar. Si al registrarte te equivocaste al escribir la dirección, " .
            "escribe la correcta: sustituirá a la anterior.</p>");
        $this->showEmailForm(null, [
            'action' => self::PAGE,
            'title' => "Volver a enviar el enlace de activación",
            'infotitle' => "Volver a enviar el enlace",
            'info' => "Usa este formulario solo si no te ha llegado el correo con el enlace para " .
                "activar tu cuenta o si ya ha caducado (vale " . VERIFY_MINUTES . " minutos).\n\n" .
                "Te enviaremos un enlace nuevo a la dirección que indiques y el anterior dejará " .
                "de funcionar. Si es distinta de la que diste al registrarte, la sustituirá.\n\n" .
                "Después de cada envío tendrás que esperar " . VERIFY_WAIT_MINUTES .
                " minutos para poder pedir otro.",
            'emailinfo' => "Revísala bien antes de enviar: ahí recibirás el enlace.",
            'emailhint' => "Ahí te enviaremos el nuevo enlace para activar tu cuenta.",
            'button' => "Enviar de nuevo el enlace",
        ]);
    }

    /* Aviso de que el enlace ya está enviado y hay que abrirlo. Durante
       $wait segundos no se puede pedir otro: pasados, la página se recarga
       y muestra el formulario para pedirlo (ver showPendingVerification). */
    private function showEmailSent(int $wait, ?string $email = null)
    {
        $minutes = max(1, (int) ceil($wait / 60));
        $to = $email === null ? "" : " a <strong>" . h($email) . "</strong>";
    ?>
        <section class="ml-participate-card">
            <h3>Revisa tu correo</h3>
            <p>Te hemos enviado un email<?= $to; ?> con un enlace para activar tu cuenta, que caduca
                en <?= VERIFY_MINUTES; ?> minutos. <strong>Pincha en el enlace para verificarla</strong>;
                hasta entonces no podrás votar. Mira también en la carpeta de correo no deseado.</p>
            <p>Si no te llega en <?= VERIFY_WAIT_MINUTES; ?> minutos, podrás volver a solicitar que
                te lo enviemos a tu dirección de correo (o a otra, si te equivocaste al escribirla).
                Podrás hacerlo dentro de <?= $minutes; ?> <?= $minutes == 1 ? "minuto" : "minutos"; ?>:
                esta página te mostrará entonces el formulario.</p>
        </section>
        <script>
            setTimeout(function() {
                window.location.href = <?= json_encode(self::PAGE); ?>;
            }, <?= ($wait + 2) * 1000; ?>);
        </script>
    <?php
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
        if ($this->dniparticipant !== null) {
            /* El botón para darse de baja. */
            if ($this->isUnregisterSubmit()) {
                $this->unregister();
                return;
            }
            /* El botón para cancelar la baja. */
            if ($this->isCancelUnregisterSubmit()) {
                $this->cancelUnregister();
                return;
            }
            /* El formulario para volver a enviar el enlace. */
            if ($this->isSubmit() && !$this->isDocsSubmit()) {
                if (!$this->checkSubmit() || !$this->resendVerification())
                    $this->showPendingVerification();
                return;
            }
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
            foreach (['tipo', 'email'] as $field)
                if (isset($_REQUEST[$field]) && is_string($_REQUEST[$field]))
                    $selected[$field] = trim($_REQUEST[$field]);
        }
        $this->showDocsForm(null, null, [
            'action' => self::PAGE,
            'title' => "Regístrate",
            'intro' => "Acredita que eres docente de la enseñanza pública en dos pasos. " .
                "Al final indicarás tu dirección de correo y te enviaremos un enlace para " .
                "activar tu cuenta. Una vez activada, para participar en una consulta solo " .
                "tendrás que identificarte con tu certificado electrónico.",
            'emailinfo' => "Revísala bien antes de enviar: si la tecleas mal no recibirás " .
                "el enlace para activar tu cuenta.",
            'emailhint' => "Revísala bien: ahí recibirás el enlace para activar tu cuenta.",
        ], $selected);
    }

    /**
     * Checks the address and the documents and registers the participant: the
     * address must be valid, of an allowed domain and not registered yet; then
     * the documents are checked and the participant is inserted in Participants
     * table by RegistrationView::registerWithDocs, and the email with the link
     * that verifies the account is sent.
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
            /* Antes de mirar los documentos: no hace falta procesarlos. Una
               dirección que no se verificó a tiempo se puede volver a usar. */
            $existing = $this->getParticipantId($db, participantHash($email));
            if ($existing !== null && !isExpiredUnverified($db, $existing)) {
                $this->alreadyRegistered();
                return true;
            }
            $participantid = null;
            $created = $this->registerWithDocs($db, $email,
                function ($id, $created) use (&$participantid) {
                    $participantid = $id;
                    return $created;
                });
            if ($created === null)
                return false;
            if (!$created) {
                $this->alreadyRegistered();
                return true;
            }
            /* El registro ya está hecho: si falla el correo se puede pedir
               otro enlace volviendo a «Regístrate». */
            $this->sendVerificationEmail($db, $participantid, $email,
                "Te has registrado correctamente.");
        } catch (Exception $e) {
            echo ("<p><strong>Error al registrar la dirección de correo.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when registering participant");
            return false;
        }
        return true;
    }

    /**
     * Sends again the link that verifies the account of the DNI of the client
     * certificate, which is registered but not verified. If the address isn't
     * the registered one, it replaces it (the certificate proves who they are):
     * the participant is deleted and inserted again with the new address, as
     * Participants doesn't allow updates.
     *
     * @return bool true if it's done, false (with the reason already shown) if
     * the form should be shown again.
     */
    private function resendVerification(): bool
    {
        $email = $this->getEmail();
        if ($email === null)
            return false;
        try {
            $db = dbConn();
            if (!$this->checkDomain($db, $email))
                return false;
            $hashdni = participantHash($this->certdni);
            $hashemail = participantHash($email);
            if (!lockParticipant($db, $hashdni)) {
                echo ("<p><strong>Hay otra petición en curso con el DNI de tu certificado. " .
                    "Inténtalo de nuevo en unos segundos.</strong></p>");
                return false;
            }
            try {
                if (!$this->lockEmail($db, $hashemail))
                    return false;
                try {
                    $participantid = $this->replaceUnverified($db, $hashdni, $hashemail);
                } finally {
                    unlockParticipant($db, $hashemail);
                }
            } finally {
                unlockParticipant($db, $hashdni);
            }
            if ($participantid === null)
                return false;
            $this->sendVerificationEmail($db, $participantid, $email, "");
        } catch (Exception $e) {
            echo ("<p><strong>Error al enviar el enlace para activar la cuenta.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} resending verification");
            return false;
        }
        return true;
    }

    /* Con los bloqueos del DNI y del correo cogidos: la participante del DNI
       con la dirección indicada, cambiándosela si es otra. Devuelve su id, o
       null (con el motivo ya mostrado). */
    private function replaceUnverified(PDO $db, string $hashdni, string $hashemail): ?int
    {
        $row = $this->participantByDni($db, $hashdni);
        if ($row === null) {
            echo ("<p><strong>No hay ningún registro con el DNI de tu certificado.</strong> " .
                "<a href='" . self::PAGE . "'>Regístrate</a>.</p>");
            return null;
        }
        $participantid = (int) $row['participantid'];
        if (isVerified($db, $participantid)) {
            echo ("<p><strong>Tu cuenta ya está verificada.</strong></p>");
            $this->showSurveysLink();
            return null;
        }
        if (hash_equals($row['participant'], $hashemail))
            return $participantid;

        /* Otra dirección. Sin esperar entre envíos se podría mandar el
           enlace a una dirección tras otra. */
        if (verificationRecentlySent($db, $participantid))
            return null; /* show() muestra que ya está enviado (showPendingVerification). */
        $other = $this->getParticipantId($db, $hashemail);
        if ($other !== null) {
            if (!isExpiredUnverified($db, $other)) {
                $this->emailInUse();
                return null;
            }
            deleteParticipant($db, $other);
        }
        $db->beginTransaction();
        try {
            deleteParticipant($db, $participantid);
            $participantid = (int) $this->insertParticipant($db, $hashdni, $hashemail);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction())
                $db->rollBack();
            throw $e;
        }
        logMessage(LOGGER_INFO, "Address of an unverified participant replaced.");
        return $participantid;
    }

    /* Genera un enlace de verificación nuevo y lo envía. $done es lo que ya
       se ha hecho (el registro), para empezar el mensaje. */
    private function sendVerificationEmail(PDO $db, int $participantid, string $email, string $done)
    {
        $code = newVerificationCode($db, $participantid);
        if ($done !== "")
            echo ("<p><strong>" . h($done) . "</strong></p>");
        if ($code === null) {
            if (isVerified($db, $participantid)) {
                echo ("<p><strong>Tu cuenta ya está verificada.</strong></p>");
                $this->showSurveysLink();
                return;
            }
            $this->showEmailSent(secondsUntilResend($db, $participantid));
            return;
        }
        try {
            $mailer = new MlMailer();
            $mailer->configure();
            $mailer->sendVerification($email, url_base64_encode($code), VERIFY_MINUTES);
            $this->showEmailSent(VERIFY_WAIT_MINUTES * 60, $email);
        } catch (Exception $e) {
            echo ("<p><strong>No se ha podido enviar el mensaje con el enlace para activar tu " .
                "cuenta.</strong></p>");
            echo ("<p>Vuelve a «Regístrate» dentro de " . VERIFY_WAIT_MINUTES .
                " minutos para pedir otro.</p>");
            logMessage(LOGGER_ERROR, "Error {$e} sending verification email");
        }
    }

    /* Da de baja a la participante del DNI del certificado: el certificado
       ya la identifica, así que la baja queda confirmada sin más. Solo se
       puede darse de baja con la cuenta verificada (si no, no puede votar). */
    private function unregister()
    {
        if (!checkToken()) {
            tokenError();
            return;
        }
        try {
            $db = dbConn();
            $participantid = (int) $this->dniparticipant['participantid'];
            if (!isVerified($db, $participantid)) {
                $this->showDniRegistered();
                return;
            }
            unsubscribe($db, $participantid);
            logMessage(LOGGER_INFO, "Participant unsubscribed.");
        } catch (Exception $e) {
            echo ("<p><strong>Ha ocurrido un error tratando de procesar tu baja del registro " .
                "de participantes.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when unsubscribing");
            return;
        }
        $this->showUnsubscribed();
    }

    /* Cancela la baja de la participante del DNI del certificado: vuelve a
       poder votar y, si quiere, a darse de baja. */
    private function cancelUnregister()
    {
        if (!checkToken()) {
            tokenError();
            return;
        }
        try {
            $db = dbConn();
            $participantid = (int) $this->dniparticipant['participantid'];
            if (checkUnsubscribe($db, $participantid)) {
                cancelUnsubscribe($db, $participantid);
                logMessage(LOGGER_INFO, "Participant unsubscription cancelled.");
                echo ("<p><strong>Has cancelado la baja. Vuelves a estar en el registro de " .
                    "participantes.</strong></p>");
            }
        } catch (Exception $e) {
            echo ("<p><strong>Ha ocurrido un error tratando de cancelar tu baja del registro " .
                "de participantes.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when cancelling the unsubscription");
            return;
        }
        $this->showDniRegistered();
    }

    /* La participante está de baja: cuándo la pidió, qué supone y el panel
       para cancelarla. */
    private function showUnsubscribed()
    {
        try {
            $date = unsubscribeDate(dbConn(), (int) $this->dniparticipant['participantid']);
        } catch (Exception $e) {
            logMessage(LOGGER_ERROR, "Error {$e} getting the unsubscription date");
            $date = null;
        }
        $when = $date === null ? "" : " el " . $date->format("d/m/Y");
        echo ("<p><strong>Solicitaste la baja del registro de participantes{$when}. En un " .
            "plazo máximo de 30 días desde entonces todos tus datos serán eliminados.</strong></p>");
        echo ("<p>Mientras estés de baja no podrás participar en ninguna consulta.</p>");
        $this->showCancelUnregister(self::PAGE);
    }

    private function alreadyRegistered()
    {
        echo ("<p><strong>La dirección de correo indicada ya está registrada.</strong></p>");
        $this->showSurveysLink();
    }

    private function showSurveysLink()
    {
        echo ("<p>Para participar en las <a href='" . h(($this->siteURL() ?? "") . "surveys") . "'>consultas " .
            "activas</a> entra en cada una e identifícate con tu certificado electrónico.</p>");
    }
}
