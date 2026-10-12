<?php

/**
 * Base class for the views that register participants: GetCode (which also sends
 * the participation URL) and Register.
 * It has the two forms (email address and documents), the checks of the uploaded
 * PDFs and the insertion in Participants table.
 */

require_once 'utils/html.php';

require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'utils/participation.php';
require_once 'utils/token.php';
require_once 'utils/altcha.php';
require_once 'utils/pdfsign.php';
require_once 'include/icons.php';

abstract class RegistrationView extends View
{

    private int $maxage = 30;
    private const ACTION = "Solicitar";
    /* Segundo paso: envío de los documentos de quien aún no está registrado. */
    private const ACTION_DOCS = "Documentos";
    private const KIND_PASSIVE = "pasivas";
    private const KIND_OTHER = "resto";
    /* Páginas en las que se descarga cada documento. */
    private const URL_EXTRACTO = "https://gestiona.comunidad.madrid/gifp_web";
    private const URL_MUFACE = "https://sede.muface.gob.es/sedeclave/public/servicio.htm?idServicio=11";
    private const URL_VIDA_LABORAL = "https://portal.seg-social.gob.es/wps/portal/importass/importass/Categorias/Vida+laboral+e+informes/Informes+sobre+tu+situacion+laboral/Informe+de+tu+vida+laboral";

    private array $docs = array ();

    function __construct()
    {
        if (isset (Config::PARAMS["pdf_max_age_days"]))
            $this->maxage = Config::PARAMS["pdf_max_age_days"];
        $this->docs = [
            self::KIND_PASSIVE => [
                'label' => "Clases Pasivas",
                'name' => "Certificado de afiliación a MUFACE",
                'url' => self::URL_MUFACE,
                'info' => "Se descarga en la sede electrónica de MUFACE.\n\n" .
                    "Adjunta el PDF tal como lo descargaste (firmado digitalmente) y " .
                    "expedido en los últimos {$this->maxage} días.",
            ],
            self::KIND_OTHER => [
                'label' => "Resto",
                'name' => "Informe de vida laboral",
                'url' => self::URL_VIDA_LABORAL,
                'info' => "Se descarga en el portal de la Seguridad Social.\n\n" .
                    "Adjunta el PDF tal como lo descargaste y expedido en los últimos " .
                    "{$this->maxage} días.",
            ],
        ];
    }

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
        return $this->isDocsSubmit() || isset($_REQUEST[self::ACTION]);
    }

    /* true si es el envío del segundo formulario, el de los documentos. */
    protected function isDocsSubmit(): bool
    {
        return isset($_REQUEST[self::ACTION_DOCS]);
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

    /* Comprueba que en el formulario de los documentos se ha aceptado su
       uso. $purpose es para qué se piden: "participar", "registrarse".
       El required del formulario se puede saltar: se comprueba también aquí. */
    protected function checkConsent(string $purpose): bool
    {
        if (($_REQUEST['acepto'] ?? null) === "1")
            return true;
        echo ("<p><strong>Para {$purpose} hay que aceptar el uso de los documentos " .
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
        $domains = Config::$alloweddomains == "" ? "cualquiera" :
            str_replace(" ", ", ", Config::$alloweddomains);
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
                    <?= $this->infoButton(
                        "Dirección de correo",
                        "Dominios autorizados: {$domains}.\n\n" .
                            "La dirección queda asociada a tus documentos: en esta y en " .
                            "próximas consultas tendrás que usar siempre la misma.\n\n" .
                            $texts['emailinfo']
                    ); ?>
                    <small><?= h($texts['emailhint']); ?></small>
                </div>
                <div class="ml-participate-row">
                    <input type="email" name="email" id="email" required
                        placeholder="nombre.apellido@educa.madrid.org" autocomplete="email">
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

    /* Segundo paso, solo para direcciones que aún no están registradas:
       régimen y los dos documentos. La dirección ya comprobada en el primer
       paso viaja en el formulario y se vuelve a comprobar al recibirlo.
       Textos de $texts: action, back, title e intro. */
    protected function showDocsForm($surveyid, $email, array $texts)
    {
        /* Documento que acredita cada régimen: al cambiar de régimen se
           cambian el título, el enlace y la explicación del segundo fichero. */
        $doc = $this->docs[self::KIND_OTHER];
        ?>
        <section class="ml-participate-card">
            <h3><?= h($texts['title']); ?></h3>
            <p>La dirección <strong><?= h($email); ?></strong> <?= h($texts['intro']); ?>
                La dirección queda asociada a tus documentos: tendrás que usar siempre la misma.
                <a href="<?= h($texts['back']); ?>">Usar otra dirección</a></p>
            <form id="getcodedocs" name="getcodedocs" method="POST"
                action="<?= h($texts['action']); ?>" enctype="multipart/form-data">
                <?= setTokenHTML(); ?>
                <?php if ($surveyid !== null) { ?>
                    <input type="hidden" name="surveyid" value="<?= (int) $surveyid; ?>">
                <?php } ?>
                <input type="hidden" name="email" value="<?= h($email); ?>">
                <fieldset class="ml-participate-kind">
                    <legend>Mi régimen es
                        <?= $this->infoButton(
                            "Régimen",
                            "Clases Pasivas: funcionarios/as de carrera que ingresaron " .
                                "antes de 2011.\n\n" .
                                "Resto: funcionarios/as de carrera que ingresaron a partir de " .
                                "2011 e interinos/as.\n\n" .
                                "Según el régimen se pide un documento distinto."
                        ); ?>
                    </legend>
                    <?php foreach ($this->docs as $kind => $d) { ?>
                        <label><input type="radio" name="tipo" value="<?= $kind; ?>" required
                                data-name="<?= h($d['name']); ?>" data-url="<?= h($d['url']); ?>"
                                data-info="<?= h($d['info']); ?>"
                                <?= $kind === self::KIND_OTHER ? "checked" : ""; ?>>
                            <?= h($d['label']); ?></label>
                    <?php } ?>
                </fieldset>
                <div class="ml-participate-doc">
                    <label for="extracto">Extracto de formación</label>
                    <?= $this->infoButton(
                        "Extracto de formación",
                        "Se descarga en el portal de la Comunidad de Madrid.\n\n" .
                            "Adjunta el PDF tal como lo descargaste (firmado digitalmente)."
                    ); ?>
                    <small>Obtener <a href="<?= self::URL_EXTRACTO; ?>" target="_blank"
                            rel="noopener noreferrer">aquí</a></small>
                </div>
                <div class="ml-participate-row">
                    <input type="file" name="extracto" id="extracto" required
                        accept="application/pdf,.pdf">
                </div>
                <div class="ml-participate-doc">
                    <label for="documento" id="documento-name"><?= h($doc['name']); ?></label>
                    <?= $this->infoButton($doc['name'], $doc['info'], "documento-info"); ?>
                    <small>Obtener <a href="<?= h($doc['url']); ?>" id="documento-url"
                            target="_blank" rel="noopener noreferrer">aquí</a></small>
                </div>
                <div class="ml-participate-row">
                    <input type="file" name="documento" id="documento" required
                        accept="application/pdf,.pdf">
                </div>
                <label class="ml-participate-consent"><input type="checkbox" name="acepto"
                        value="1" id="acepto" required>
                    Acepto que los documentos se usen solo para verificar que soy docente
                    de la enseñanza pública. No se almacenan.</label>
                <div class="ml-participate-row">
                    <?= altchaWidgetHTML(); ?>
                    <button type="submit" class="button-3 ml-participate-btn"
                        name="<?= self::ACTION_DOCS; ?>" value="<?= self::ACTION_DOCS; ?>">
                        Enviar documentos</button>
                </div>
            </form>
        </section>
        <script>
            (function() {
                var form = document.getElementById("getcodedocs");
                var file = document.getElementById("documento");
                var info = document.getElementById("documento-info");

                /* El segundo documento depende del régimen elegido. */
                function showDocument(clear) {
                    var kind = form.querySelector('input[name="tipo"]:checked');
                    if (!kind)
                        return;
                    document.getElementById("documento-name").textContent = kind.dataset.name;
                    document.getElementById("documento-url").href = kind.dataset.url;
                    info.dataset.title = kind.dataset.name;
                    info.setAttribute("aria-label", "Más información: " + kind.dataset.name);
                    info.dataset.info = kind.dataset.info;
                    /* El fichero elegido era el del otro régimen. */
                    if (clear)
                        file.value = "";
                }
                form.querySelectorAll('input[name="tipo"]').forEach(function(radio) {
                    radio.addEventListener("change", function() {
                        showDocument(true);
                    });
                });
                /* Al recargar, el navegador puede conservar el régimen marcado. */
                showDocument(false);
                window.addEventListener("pageshow", function() {
                    showDocument(false);
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
    private function infoButton(string $title, string $info, string $id = ""): string
    {
        return '<button type="button" class="ml-info"' . ($id === "" ? "" : ' id="' . h($id) . '"') .
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
     * Handles the submit of the documents form: checks the two PDFs (getDni) and
     * looks for the participant with that DNI and email address, inserting it in
     * Participants table if it doesn't exist.
     * The hash of the DNI (dnihashed) identifies the participant. The hash of the email
     * address is stored with it (participant), and both are unique: the same documents
     * can't be used from several addresses, nor the same address with the documents of
     * several people.
     *
     * $then is called with the participant id and whether it has just been created,
     * still holding the locks of the DNI and the email address.
     *
     * @return mixed What $then returns, or null (the reason is already shown) if the
     * documents aren't valid or the DNI or the address belong to another participant.
     */
    protected function registerWithDocs($db, $email, callable $then)
    {
        $dni = $this->getDni();
        if ($dni === null)
            return null;
        $hashdni = hash('sha256', $dni);
        $hashemail = hash('sha256', $email);

        if (!lockParticipant($db, $hashdni)) {
            echo ("<p><strong>Hay otra petición en curso con el DNI del extracto. " .
                "Inténtalo de nuevo en unos segundos.</strong></p>");
            return null;
        }
        try {
            if (!$this->lockEmail($db, $hashemail))
                return null;
            try {
                $created = false;
                $participantid = $this->findOrCreateParticipant($db, $email, $hashdni, $hashemail, $created);
                return $participantid === null ? null : $then($participantid, $created);
            } finally {
                unlockParticipant($db, $hashemail);
            }
        } finally {
            unlockParticipant($db, $hashdni);
        }
    }

    /**
     * If the DNI and email are not in Parciciants table the method generates a ECDSA key pair,
     * encrypts private key using the email and stores the hashed email address
     * (participant), the hashed DNI (dnihashed) and the key pair in Participants table.
     */
    private function insertParticipant($db, $email, $hashdni, $hashemail)
    {
        $keypair = generateKeyPair();

        openssl_pkey_export($keypair, $privatekey, $email);
        $public_key_details = openssl_pkey_get_details($keypair);
        $publickey = $public_key_details['key'];
        $query = $db->prepare("INSERT into {Participants} (participant, dnihashed, privatekey, publickey) " .
            "values (:part, :dni, :priv, :pub)");
        $query->bindParam(":part", $hashemail, PDO::PARAM_STR);
        $query->bindParam(":dni", $hashdni, PDO::PARAM_STR);
        $query->bindParam(":priv", $privatekey, PDO::PARAM_STR);
        $query->bindParam(":pub", $publickey, PDO::PARAM_STR);
        $query->execute();
        return $db->lastInsertId();
    }

    /* Devuelve la ruta temporal de un PDF subido, o null (con el motivo ya
       mostrado) si no ha llegado bien. */
    private function getUpload(string $field, string $docname): ?string
    {
        $file = $_FILES[$field] ?? null;
        if (
            !is_array($file) || !is_int($file['error'] ?? null) ||
            $file['error'] == UPLOAD_ERR_NO_FILE
        ) {
            echo ("<p><strong>Hay que adjuntar el {$docname} en PDF.</strong></p>");
            return null;
        }
        if ($file['error'] == UPLOAD_ERR_INI_SIZE || $file['error'] == UPLOAD_ERR_FORM_SIZE) {
            echo ("<p><strong>El fichero del {$docname} está vacío o es demasiado grande.</strong></p>");
            return null;
        }
        if ($file['error'] != UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            echo ("<p><strong>Error al recibir el {$docname}.</strong></p>");
            logMessage(LOGGER_ERROR, "Upload error {$file['error']} getting the PDF.");
            return null;
        }
        return $file['tmp_name'];
    }

    /* Comprueba la firma del extracto de formación y el documento subido
       según el régimen elegido (certificado de MUFACE firmado o
       informe de vida laboral), y que los dos son de la misma persona.
       Devuelve el DNI leído del extracto, o null (con el motivo ya mostrado)
       si no vale. Los ficheros se quedan en el temporal de la subida: no se
       guardan. */
    private function getDni(): ?string
    {
        $kind = $_REQUEST['tipo'] ?? null;
        if ($kind !== self::KIND_PASSIVE && $kind !== self::KIND_OTHER) {
            echo ("<p><strong>Hay que indicar si perteneces a Clases Pasivas o al resto.</strong></p>");
            return null;
        }
        $docname = $kind === self::KIND_PASSIVE ? "certificado de afiliación a MUFACE" :
            "informe de vida laboral";
        $extracto = $this->getUpload('extracto', "extracto de formación");
        if ($extracto === null)
            return null;
        $document = $this->getUpload('documento', $docname);
        if ($document === null)
            return null;
        /* Para que el mensaje de error diga de qué fichero habla. */
        $checking = "extracto de formación";
        try {
            checkExtractoSignature($extracto);
            $dni = getPdfDni($extracto);
            $checking = $docname;
            if ($kind === self::KIND_PASSIVE) {
                checkMufaceSignature($document);
                $documentdni = getMufaceDni($document);
            } else
                $documentdni = getVidaLaboralDni($document);
        } catch (PdfSignException $e) {
            echo ("<p><strong>" . ucfirst($checking) . ": " . h($e->getMessage()) . "</strong></p>");
            return null;
        }
        if (!hash_equals($dni, $documentdni)) {
            echo ("<p><strong>El DNI del {$docname} no coincide con el del extracto de " .
                "formación.</strong></p>");
            logMessage(LOGGER_WARN, "PDF rejected: the DNI is not the one in the training record.");
            return null;
        }
        return $dni;
    }

    /* Id de la participante registrada con esa dirección, o null si no hay. */
    protected function getParticipantId($db, $hashemail): ?int
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
    private function findOrCreateParticipant($db, $email, $hashdni, $hashemail, &$created = false)
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
            /* Las filas anteriores a los documentos no tienen DNI y la tabla
               es inmutable: no se les puede asociar uno, y su clave privada
               está cifrada con el correo. */
            if ($row['dnihashed'] === null) {
                echo ("<p><strong>La dirección de correo indicada ya se usó antes de que se " .
                    "pidieran los documentos y no se puede asociar a ellos. Hay que usar otra " .
                    "dirección.</strong></p>");
                return null;
            }
            if ($row['dnihashed'] !== $hashdni) {
                $this->emailInUse();
                return null;
            }
            if ($row['participant'] !== $hashemail) {
                echo ("<p><strong>Los documentos adjuntados ya se han usado con otra dirección " .
                    "de correo. Hay que usar siempre la misma dirección.</strong></p>");
                return null;
            }
            $participantid = $row['participantid'];
        }
        if ($participantid == -1) {
            try {
                $participantid = $this->insertParticipant($db, $email, $hashdni, $hashemail);
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
        echo ("<p><strong>La dirección de correo indicada ya se ha usado con los documentos " .
            "de otra persona.</strong></p>");
    }

    protected function checkDomain($db, $email)
    {
        $query = $db->prepare("SELECT alloweddomains FROM {SystemConfig} LIMIT 1");
        $query->execute();
        if ($query->rowCount() == 0) {
            echo ("<p><strong>El sistema no está configurado aún. No se puede participar.</strong></p>");
            return false;
        }
        $domainstring = $query->fetch()['alloweddomains'] ?? "";
        $query->closeCursor();
        $domains = array_filter(explode(" ", strtolower(trim($domainstring))));
        /* Sin dominios configurados puede participar cualquiera. */
        if (empty($domains))
            return true;
        $emaildomain = substr(strrchr($email, "@"), 1);
        foreach ($domains as $key => $domain) {
            if ($emaildomain == $domain)
                return true;
        }
        echo ("<p><strong>La dirección de correo proporcionada no es de un dominio autorizado.</strong></p>");
        return false;
    }
}
