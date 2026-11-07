<?php

/**
 * Base class for the views that register participants: GetCode (which also sends
 * the participation URL) and Register.
 * It has the two forms (email address and signed declaration), the check of the
 * uploaded PDF and the insertion in Participants table.
 */

require_once 'utils/html.php';
require_once 'utils/crypt.php';

require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'utils/participation.php';
require_once 'utils/token.php';
require_once 'utils/altcha.php';
require_once 'utils/pdfsign.php';
require_once 'include/icons.php';

abstract class RegistrationView extends View
{

    private const ACTION = "Solicitar";
    /* Segundo paso: envío de la declaración de quien aún no está registrado. */
    private const ACTION_DECLARACION = "Declaracion";
    /* Modelo de la declaración que se descarga, rellena y firma. */
    private const MODEL_URL = "docs/modelo_declaracion.pdf";
    private const PRIVACY_POLICY_FILE = "privacypolicy.html";

    public function loadStyles()
    {
?>
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <?= altchaStyleHTML(); ?>
    <?php
    }

    /**
     * For avoiding bots this page includes a CAPTCHA.
     * Thanks to ALTCHA: https://altcha.org/
     * We're using the simplest implementation of ALTCHA  with the ALTCHA widget.
     */
    public function addHead()
    {
        echo (altchaScriptHTML());
    }

    /* true si la petición es el envío de uno de los dos formularios. */
    protected function isSubmit(): bool
    {
        return $this->isDeclaracionSubmit() || isset($_REQUEST[self::ACTION]);
    }

    /* true si es el envío del segundo formulario, el de la declaración. */
    protected function isDeclaracionSubmit(): bool
    {
        return isset($_REQUEST[self::ACTION_DECLARACION]);
    }

    /* Comprueba el token y el CAPTCHA del formulario enviado. Devuelve
       false (con el motivo ya mostrado) si no valen. */
    protected function checkSubmit(): bool
    {
        if (!checkToken()) {
            tokenError();
            return false;
        }
        if (!altchaCheck()) {
            altchaError();
            return false;
        }
        return true;
    }

    /* Comprueba que en el formulario de la declaración se ha aceptado su
       uso. $purpose es para qué se piden: "participar", "registrarse".
       El required del formulario se puede saltar: se comprueba también aquí. */
    protected function checkConsent(string $purpose): bool
    {
        if (($_REQUEST['acepto'] ?? null) === "1")
            return true;
        echo ("<p><strong>Para {$purpose} hay que aceptar el uso de la declaración " .
            "para verificar que eres docente de la enseñanza pública.</strong></p>");
        return false;
    }

    /* Dirección de correo del formulario enviado, normalizada, o null (con
       el motivo ya mostrado) si no es válida. */
    protected function getEmail(): ?string
    {
        $email = isset($_REQUEST['email']) && is_string($_REQUEST['email']) ?
            strtolower(trim($_REQUEST['email'])) : "";
        if ($email == "" || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            echo ("<p><strong>La dirección de correo es incorrecta.</strong></p>");
            return null;
        }
        return $email;
    }

    /* Primer paso: solo la dirección de correo. Qué se hace con ella lo
       decide cada vista, que también pone sus textos en $texts: action,
       title, infotitle, info, emailinfo, emailhint y button.
       $surveyid es null si el formulario no es de una consulta. */
    protected function showEmailForm($surveyid, array $texts)
    {
    ?>
        <section class="ml-participate-card">
            <h3><?= h($texts['title']); ?>
                <?= $this->infoButton($texts['infotitle'], $texts['info']); ?>
            </h3>
            <form id="getcode" name="getcode" method="POST" action="<?= h($texts['action']); ?>">
                <?= setTokenHTML(); ?>
                <?php if ($surveyid !== null) { ?>
                    <!-- La consulta va en el propio formulario: en la sesión
                         la pisaría otra pestaña con otra consulta abierta. -->
                    <input type="hidden" name="surveyid" value="<?= (int) $surveyid; ?>">
                <?php } ?>
                <div class="ml-participate-doc">
                    <label for="email">Dirección de correo</label>
                    <?= $this->infoButton("Dirección de correo", $this->emailInfo($texts['emailinfo'])); ?>
                    <small><?= h($texts['emailhint']); ?></small>
                </div>
                <div class="ml-participate-row">
                    <input type="email" name="email" id="email" required>
                    <?= altchaWidgetHTML(); ?>
                    <button type="submit" class="button-3 ml-participate-btn"
                        name="<?= self::ACTION; ?>" value="<?= self::ACTION; ?>">
                        <?= h($texts['button']); ?></button>
                </div>
            </form>
        </section>
    <?php
        $this->showInfoScript();
    }

    /* Explicación del campo de la dirección de correo, con los dominios
       autorizados; $extra la completa con lo propio de cada vista. */
    private function emailInfo(string $extra): string
    {
        $domains = Config::$alloweddomains == "" ? "cualquiera" :
            str_replace(" ", ", ", Config::$alloweddomains);
        return "Dominios autorizados: {$domains}.\n\n" .
            "Es la dirección de tu declaración firmada: en esta y en " .
            "próximas consultas tendrás que usar siempre la misma.\n\n" . $extra;
    }

    /* Segundo paso, solo para quien aún no está registrado: se pide la
       declaración firmada, de la que se leen el DNI y la dirección de correo
       educamadrid con la que se registra. No se pide la dirección en el
       formulario: es la de la declaración.
       Textos de $texts: action, title e intro. */
    protected function showDeclaracionForm($surveyid, array $texts)
    {
    ?>
        <section class="ml-participate-card">
            <h3><?= h($texts['title']); ?></h3>
            <p><?= h($texts['intro']); ?></p>
            <form id="declaracion" name="declaracion" method="POST"
                action="<?= h($texts['action']); ?>" enctype="multipart/form-data">
                <?= setTokenHTML(); ?>
                <?php if ($surveyid !== null) { ?>
                    <input type="hidden" name="surveyid" value="<?= (int) $surveyid; ?>">
                <?php } ?>
                <ol class="ml-declaracion-steps">
                    <li>Descarga el <a href="<?= h(self::MODEL_URL); ?>" target="_blank"
                            rel="noopener noreferrer" download>modelo de declaración</a>.</li>
                    <li>Rellena tu número de documento (NIF o NIE) y tu dirección de correo de
                        EducaMadrid. Con esa dirección quedarás registrado/a y en ella recibirás
                        los mensajes.</li>
                    <li>Fírmalo con tu certificado digital de persona física o tu DNIe (por
                        ejemplo, con AutoFirma o Adobe Acrobat Reader). El certificado tiene que ser
                        el tuyo: su número de documento tiene que coincidir con el que has escrito.</li>
                    <li>Adjunta aquí el PDF firmado, sin modificarlo después de firmar.</li>
                </ol>
                <div class="ml-participate-doc">
                    <label for="documento">Declaración firmada</label>
                    <?= $this->infoButton(
                        "Declaración firmada",
                        "El modelo de declaración relleno con tu NIF o NIE y tu dirección de " .
                            "correo de EducaMadrid, y firmado digitalmente con tu propio certificado.\n\n" .
                            "Si lo modificas después de firmarlo, la firma deja de ser válida."
                    ); ?>
                    <small>El PDF tal como queda después de firmarlo.</small>
                </div>
                <div class="ml-participate-row">
                    <input type="file" name="documento" id="documento" required
                        accept="application/pdf,.pdf">
                </div>
                <p class="ml-participate-legal-link">Antes de enviarla, lee
                    <button type="button" class="ml-link" id="ml-legal-open"
                        aria-haspopup="dialog">cómo tratamos tu declaración y la información
                        sobre protección de datos</button>.
                </p>
                <dialog class="ml-dialog ml-legal-dialog" id="ml-legal"
                    aria-labelledby="ml-legal-title">
                    <div class="ml-dialog__form">
                        <?php if (
                            !file_exists(self::PRIVACY_POLICY_FILE) ||
                            !is_file(self::PRIVACY_POLICY_FILE)
                        ) {
                        ?>
                            <h2 class="ml-dialog__title" id="ml-legal-title">Verificación de tu
                                condición de docente de la enseñanza pública</h2>
                            <div class="ml-legal-body" tabindex="0">
                                <p>Para registrarte necesitamos tu declaración como docente en la
                                    educación pública de la Comunidad de Madrid, con tu NIF o NIE y tu
                                    dirección de correo de EducaMadrid, firmada digitalmente con tu
                                    certificado de persona física o tu DNIe.</p>
                                <p><strong>Qué hacemos con ella.</strong> La leemos de forma automática
                                    para comprobar que la firma es válida, que el NIF escrito coincide con el
                                    del certificado con el que está firmada y que la dirección es de
                                    EducaMadrid. Se procesa en memoria y se elimina en cuanto termina la
                                    comprobación. No guardamos el documento.</p>
                                <p>Lo único que conservamos es tu NIF y tu dirección de correo
                                    transformados con una función criptográfica y una clave secreta que se
                                    custodia aparte, de modo que sin esa clave no es posible conocerlos.
                                    Solo sirven para impedir que una misma persona tenga más de una cuenta.</p>
                                <h5>Información básica sobre protección de datos</h5>
                                <ul>
                                    <li><strong>Finalidad:</strong> verificar tu condición de docente en
                                        activo de la enseñanza pública y evitar cuentas duplicadas.</li>
                                    <li><strong>Base jurídica:</strong> tu consentimiento, que puedes retirar
                                        en cualquier momento.</li>
                                    <li><strong>Destinatarios:</strong> no se ceden datos a terceros. El
                                        servicio se aloja en la Unión Europea.</li>
                                    <li><strong>Derechos:</strong> acceso, rectificación, supresión,
                                        oposición, limitación y portabilidad, escribiendo a datos@huelga14o.org.
                                        También puedes reclamar ante la Agencia Española de
                                        Protección de Datos (<a href="https://www.aepd.es" target="_blank"
                                            rel="noopener noreferrer">aepd.es</a>).</li>
                                </ul>
                                <p>Si la verificación no se supera, te indicaremos el motivo y no quedará
                                    ningún registro de tu intento. Puedes volver a intentarlo cuando quieras.</p>
                            </div>
                        <?php } else {
                            echo file_get_contents(self::PRIVACY_POLICY_FILE);
                        } ?>
                        <div class="ml-dialog__actions">
                            <button type="button" class="button-3" id="ml-legal-close">
                                Cerrar</button>
                        </div>
                    </div>
                </dialog>
                <label class="ml-participate-consent"><input type="checkbox" name="acepto"
                        value="1" id="acepto" required>
                    He leído la información sobre el tratamiento de la declaración y
                    consiento su tratamiento con la finalidad indicada.</label>
                <div class="ml-participate-row">
                    <?= altchaWidgetHTML(); ?>
                    <button type="submit" class="button-3 ml-participate-btn"
                        name="<?= self::ACTION_DECLARACION; ?>" value="<?= self::ACTION_DECLARACION; ?>">
                        Enviar declaración</button>
                </div>
            </form>
        </section>
        <?php $this->showUnregister(); ?>
        <script>
            (function() {
                /* La información de protección de datos se muestra en un diálogo. */
                var legal = document.getElementById("ml-legal");
                document.getElementById("ml-legal-open").addEventListener("click", function() {
                    if (typeof legal.showModal === "function")
                        legal.showModal();
                    else
                        legal.setAttribute("open", "");
                });
                document.getElementById("ml-legal-close").addEventListener("click", function() {
                    if (typeof legal.close === "function")
                        legal.close();
                    else
                        legal.removeAttribute("open");
                });
            })();
        </script>
    <?php
        $this->showInfoScript();
    }

    /* Los iconos de información abren su explicación en un diálogo. */
    private function showInfoScript()
    {
    ?>
        <script>
            document.querySelectorAll(".ml-participate-card .ml-info").forEach(function(button) {
                button.addEventListener("click", function() {
                    mlDialog.alert({
                        title: button.dataset.title,
                        message: button.dataset.info,
                        confirmText: "Cerrar"
                    });
                });
            });
        </script>
    <?php
    }

    /* Icono de información: al pincharlo se muestra la explicación en un diálogo. */
    private function infoButton(string $title, string $info): string
    {
        return '<button type="button" class="ml-info"' .
            ' aria-label="Más información: ' . h($title) . '" data-title="' . h($title) .
            '" data-info="' . h($info) . '">' . mlIcon('info') . '</button>';
    }

    /* Coge el bloqueo de la dirección de correo. Devuelve false (con el
       motivo ya mostrado) si no se ha podido. */
    protected function lockEmail($db, $hashemail): bool
    {
        if (lockParticipant($db, $hashemail))
            return true;
        echo ("<p><strong>Hay otra petición en curso con la dirección de correo indicada. " .
            "Inténtalo de nuevo en unos segundos.</strong></p>");
        return false;
    }

    /**
     * Handles the submit of the declaration form: checks the signed declaration
     * (getDeclaracion), from which the DNI and the email address are read, checks
     * the domain of the address and looks for the participant with that DNI and
     * email address, inserting it in Participants table if it doesn't exist.
     * The hash of the DNI (dnihashed) identifies the participant. The hash of the email
     * address is stored with it (participant), and both are unique: the same DNI
     * can't be used from several addresses, nor the same address with the DNI of
     * several people.
     *
     * $then is called with the participant id, whether it has just been created and
     * the email address of the declaration, still holding the locks of the DNI and
     * the email address.
     *
     * @return mixed What $then returns, or null (the reason is already shown) if the
     * declaration isn't valid or the DNI or the address belong to another participant.
     */
    protected function registerWithDeclaracion($db, callable $then)
    {
        $declaracion = $this->getDeclaracion();
        if ($declaracion === null)
            return null;
        ["dni" => $dni, "email" => $email] = $declaracion;
        if (!$this->checkDomain($email))
            return null;
        $hashdni = participantHash($dni);
        $hashemail = participantHash($email);

        if (!lockParticipant($db, $hashdni)) {
            echo ("<p><strong>Hay otra petición en curso con el DNI de la declaración. " .
                "Inténtalo de nuevo en unos segundos.</strong></p>");
            return null;
        }
        try {
            if (!$this->lockEmail($db, $hashemail))
                return null;
            try {
                $created = false;
                $participantid = $this->findOrCreateParticipant($db, $hashdni, $hashemail, $created);
                return $participantid === null ? null : $then($participantid, $created, $email);
            } finally {
                unlockParticipant($db, $hashemail);
            }
        } finally {
            unlockParticipant($db, $hashdni);
        }
    }

    /**
     * If the DNI and email are not in Parciciants table the method stores the hashed
     * email address (participant) and the hashed DNI (dnihashed) in Participants table.
     */
    private function insertParticipant($db, $hashdni, $hashemail)
    {
        $query = $db->prepare("INSERT into {Participants} (participant, dnihashed) " .
            "values (:part, :dni)");
        $query->bindParam(":part", $hashemail, PDO::PARAM_STR);
        $query->bindParam(":dni", $hashdni, PDO::PARAM_STR);
        $query->execute();
        return $db->lastInsertId();
    }

    /* Comprueba la declaración firmada subida. Devuelve ["dni", "email"],
       o null (con el motivo ya mostrado) si no vale.
       El fichero se queda en el temporal de la subida: no se guarda. */
    private function getDeclaracion(): ?array
    {
        $file = $_FILES['documento'] ?? null;
        $error = is_array($file) && is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error == UPLOAD_ERR_NO_FILE) {
            echo ("<p><strong>Hay que adjuntar la declaración firmada en PDF.</strong></p>");
            return null;
        }
        if ($error == UPLOAD_ERR_INI_SIZE || $error == UPLOAD_ERR_FORM_SIZE) {
            echo ("<p><strong>El fichero de la declaración es demasiado grande.</strong></p>");
            return null;
        }
        if ($error != UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            echo ("<p><strong>Error al recibir la declaración.</strong></p>");
            logMessage(LOGGER_ERROR, "Upload error {$error} getting the PDF.");
            return null;
        }
        try {
            return checkDeclaracion($file['tmp_name']);
        } catch (PdfSignException $e) {
            echo ("<p><strong>Declaración: " . h($e->getMessage()) . "</strong></p>");
            return null;
        }
    }

    /* Id de la participante registrada con esa dirección, o null si no hay. */
    protected function getParticipantId(PDO $db, string $hashemail): ?int
    {
        $participants = $db->prepare("SELECT participantid
            FROM {Participants}
            WHERE participant = :participant LIMIT 1");
        $participants->bindParam(":participant", $hashemail, PDO::PARAM_STR);
        $participants->execute();
        $participantid = $participants->fetchColumn();
        $participants->closeCursor();
        return $participantid === false ? null : (int) $participantid;
    }

    /* Busca a la participante con ese DNI y esa dirección y, si no existe,
       la crea. Se llama con los bloqueos del DNI y del correo cogidos.
       Devuelve su id, o null (con el motivo ya mostrado) si el DNI o el
       correo ya están asociados a otro. $created indica si se ha creado. */
    private function findOrCreateParticipant($db, $hashdni, $hashemail, &$created = false)
    {
        $created = false;
        $participants = $db->prepare("SELECT participantid, participant, dnihashed From {Participants} " .
            "WHERE participant = :participant OR dnihashed = :dni");
        $participants->bindParam(":participant", $hashemail, PDO::PARAM_STR);
        $participants->bindParam(":dni", $hashdni, PDO::PARAM_STR);
        $participants->execute();
        $rows = $participants->fetchAll();
        $participants->closeCursor();
        $participantid = -1;
        foreach ($rows as $row) {
            /* Las filas anteriores a la declaración no tienen DNI y la tabla
               es inmutable: no se les puede asociar uno. */
            if ($row['dnihashed'] === null) {
                echo ("<p><strong>La dirección de correo indicada ya se usó antes de que se " .
                    "pidiera la declaración y no se puede asociar a ella. Hay que usar otra " .
                    "dirección.</strong></p>");
                return null;
            }
            if ($row['dnihashed'] !== $hashdni) {
                $this->emailInUse();
                return null;
            }
            if ($row['participant'] !== $hashemail) {
                echo ("<p><strong>El DNI de la declaración ya se ha usado con otra dirección " .
                    "de correo. Hay que usar siempre la misma dirección.</strong></p>");
                return null;
            }
            $participantid = $row['participantid'];
        }
        if ($participantid == -1) {
            try {
                $participantid = $this->insertParticipant($db, $hashdni, $hashemail);
            } catch (PDOException $e) {
                /* Dos peticiones simultáneas con el mismo correo y distinto
                   DNI: el bloqueo es por DNI, las para el índice único. */
                if ($e->getCode() != "23000")
                    throw $e;
                $this->emailInUse();
                return null;
            }
            $created = true;
        }
        return (int) $participantid;
    }

    private function emailInUse()
    {
        echo ("<p><strong>La dirección de correo de la declaración ya se ha usado con el DNI " .
            "de otra persona.</strong></p>");
    }

    /* Comprueba que la dirección es de uno de los dominios permitidos en la
       configuración del sistema (sin dominios, vale cualquiera). Devuelve
       false (con el motivo ya mostrado) si no. */
    protected function checkDomain(string $email): bool
    {
        if (!Config::$haveconfig) {
            echo ("<p><strong>El sistema no está configurado aún. No se puede participar.</strong></p>");
            return false;
        }
        $domains = array_filter(explode(" ", strtolower(trim(Config::$alloweddomains))));
        if (empty($domains) || in_array(substr(strrchr($email, "@"), 1), $domains, true))
            return true;
        echo ("<p><strong>La dirección de correo proporcionada no es de un dominio autorizado.</strong></p>");
        return false;
    }

    private function showUnregister()
    {
    ?>
        <section class="ml-participate-card" id="unregister">
            <form name="unregisterform" method="POST" action="unsubscribe">
                <?= setTokenHTML(); ?>
                <h3>Date de baja</h3>
                <p>Date de baja indicando tu dirección de correo.</p>
                <p><strong>El proceso se puede alargar hasta 30 días para que no puedas repetir tu participación
                        en ninguna consulta.</strong>
                </p>
                <p>
                    Una vez solicitada la baja recibiras un mensaje de correo con un enlace para confirmarla.
                    Para finalizar el proceso debes pulsar en ese enlace, de otra forma no causarás baja.
                </p>
                <div class="ml-participate-doc">
                    <label for="email">Dirección de correo</label>

                    <small>Debe coindidir con la que proporcionaste al registrarte.</small>
                </div>
                <div class="ml-participate-row">
                    <input type="email" name="email" id="email" required>
                    <?= altchaWidgetHTML(); ?>
                    <button type="submit" class="button-3 ml-participate-btn"
                        name="<?= self::ACTION; ?>" value="<?= self::ACTION; ?>">
                        <?= self::ACTION; ?></button>
                </div>
            </form>
        </section>
<?php
    }
}
