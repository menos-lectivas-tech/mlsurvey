<?php

/**
 * This class shows the page for getting and sending participation URL.
 */

require_once 'utils/html.php';

require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'views/surveys.php';
require_once 'include/mlmailer.php';
require_once 'utils/participation.php';
require_once 'utils/token.php';
require_once 'utils/altcha.php';
require_once 'utils/showsurvey.php';
require_once 'utils/crypt.php';
require_once 'utils/pdfsign.php';

class GetCode extends View {

    private const ACTION = "Solicitar";
    private const KIND_PASSIVE = "pasivas";
    private const KIND_OTHER = "resto";
    /* Páginas en las que se descarga cada documento. */
    private const URL_EXTRACTO = "https://gestiona.comunidad.madrid/gifp_web";
    private const URL_MUFACE = "https://sede.muface.gob.es/sedeclave/public/servicio.htm?idServicio=11";
    private const URL_VIDA_LABORAL = "https://portal.seg-social.gob.es/wps/portal/importass/importass/Categorias/Vida+laboral+e+informes/Informes+sobre+tu+situacion+laboral/Informe+de+tu+vida+laboral";

    function getMenuGroup (){
        return ML_MENU_GROUP_SURVEYS;
    }

    /* Sin menú: el usuario se centra en solicitar el voto / votar. */
    function showNavigation (){
        return false;
    }
    
    public function loadStyles (){
        ?>
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <?= altchaStyleHTML (); ?>
        <?php
    }

    /**
     * For avoiding bots this page includes a CAPTCHA.
     * Thanks to ALTCHA: https://altcha.org/
     * We're using the simplest implementation of ALTCHA  with the ALTCHA widget.
     */
    public function addHead (){
        echo (altchaScriptHTML ());
    }

    /**
     * Depending on the request parameters the class takes different actions:
     * - If it's not a submit and the request does't have survey information the class shows
     * the main page.
     * - If it's a submit from Surveys class with survey info, the form for asking the
     * pension scheme (Clases Pasivas or the rest), the signed training record PDF, the
     * PDF that proves it and the email addres.
     * The DNI is not asked for: it is read from the training record.
     * - If it's an own submit, checks the PDF, sends the email and shows the result
     * (success or failure).
     */
    public function show (){
        if (isset ($_REQUEST[self::ACTION])){
            if (!checkToken ()){
                tokenError ();
                return;
            }
            if (!altchaCheck ()){
                altchaError ();
                return;
            }
            $this->generateCode ();
            return;
        }
        
        /* La página de una consulta se carga con GET (get_code?responseid=N)
           para que se pueda enlazar y recargar sin reenviar el formulario. */
        if (!isset ($_GET['responseid'])){
            showMain ();
            return;
        }
        $surveyid = $_GET['responseid'];
        if (!is_string ($surveyid) || !ctype_digit ($surveyid)){
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return;
        }
        try {
            $db = dbConn ();
            $surveyname = $this->getSurveyName ($db, $surveyid);
            if ($surveyname === null){
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                logMessage (LOGGER_ERROR, "Survey {$surveyid} does not exist.");
                return;
            }
            /* Sin formulario si no se puede participar: el envío se rechazaría igualmente. */
            if (!$this->isActive ($db, $surveyid)){
                echo ("<p><strong>La consulta <em>" . h ($surveyname) .
                    "</em> no está abierta.</strong></p>");
                return;
            }
            if (!showSurveyHeader ($db, $surveyid, true)){
                removeToken ();
                return;
            }
            $maxage = (int) (Config::PARAMS["pdf_max_age_days"] ?? 30);
            ?>
            <section class="ml-participate-card">
                <h3>Participar en la consulta</h3>
                <p>Adjunta tu <a href="<?= self::URL_EXTRACTO; ?>" target="_blank"
                    rel="noopener noreferrer"><em>extracto de formación</em></a> en PDF, tal como lo descargaste
                    (firmado digitalmente), indica si perteneces a Clases Pasivas o no
                    y adjunta también el documento que lo acredita. Introduce tu
                    dirección de correo: te enviaremos un enlace personal para participar.</p>
                <p>Los documentos solo se usan para comprobar que son válidos y que son de la
                   misma persona. Con el DNI que figura en el extracto se evita que una misma
                   persona participe dos veces. Los ficheros no se guardan.</p>
                <p>Si los documentos son válidos y la dirección es de un <em>dominio autorizado</em><sup>*</sup>
                   recibirás un mensaje con un enlace.
                   Comprueba tu correo y pincha en el enlace para participar. El enlace recibido caduca en
                   una hora.</p>
                <p>Si quieres pensarte las respuestas antes de enviar tus datos, puedes
                   verlas pinchando en <em>Ver las preguntas de la consulta</em> debajo de este recuadro.</p>
                <form id="getcode" name="getcode" method="POST" action="get_code"
                    enctype="multipart/form-data">
                    <?= setTokenHTML (); ?>
                    <!-- La consulta va en el propio formulario: en la sesión
                         la pisaría otra pestaña con otra consulta abierta. -->
                    <input type="hidden" name="surveyid" value="<?= (int) $surveyid; ?>">
                    <label for="extracto"><a href="<?= self::URL_EXTRACTO; ?>" target="_blank"
                        rel="noopener noreferrer">Extracto de formación</a> (PDF firmado)</label>
                    <div class="ml-participate-row">
                        <input type="file" name="extracto" id="extracto" required
                            accept="application/pdf,.pdf">
                    </div>
                    <fieldset class="ml-participate-kind">
                        <legend>Mi régimen es</legend>
                        <label><input type="radio" name="tipo" value="<?= self::KIND_PASSIVE; ?>" required>
                            Clases Pasivas: adjunto mi <a href="<?= self::URL_MUFACE; ?>" target="_blank"
                            rel="noopener noreferrer"><em>certificado de afiliación a MUFACE</em></a>
                            (firmado digitalmente, expedido en los últimos <?= $maxage; ?> días)</label>
                        <label><input type="radio" name="tipo" value="<?= self::KIND_OTHER; ?>" required checked>
                            Resto (funcionarios/as de carrera que ingresaron a partir de 2011
                            e interinos/as): adjunto mi <a href="<?= self::URL_VIDA_LABORAL; ?>" target="_blank"
                            rel="noopener noreferrer"><em>informe de vida laboral</em></a>
                            (expedido en los últimos <?= $maxage; ?> días)</label>
                    </fieldset>
                    <label for="documento">Certificado de MUFACE o vida laboral (PDF)</label>
                    <div class="ml-participate-row">
                        <input type="file" name="documento" id="documento" required
                            accept="application/pdf,.pdf">
                    </div>
                    <label class="ml-participate-consent"><input type="checkbox" name="acepto"
                        value="1" required>
                        Acepto que los documentos que adjunto se usen exclusivamente para
                        verificar que soy docente de la enseñanza pública. En ningún caso
                        se almacenarán dichos documentos en el servidor.</label>
                    <label for="email">Dirección de correo
                      <p><small><em>* Los dominios autorizados son:
                       <?= Config::$alloweddomains == ""? "cualquiera" : h (str_replace (" ", ", ",
                           Config::$alloweddomains));?>
                       </em></small></p>
                    </label>
                    <div class="ml-participate-row">
                        <input type="email" name="email" id="email" required
                            placeholder="nombre@dominio.es" autocomplete="email">
                        <?= altchaWidgetHTML (); ?>
                        <button type="submit" class="button-3 ml-participate-btn"
                            name="<?= self::ACTION; ?>" value="<?= self::ACTION; ?>">
                            Participar en la consulta</button>
                    </div>
                </form>
            </section>

            <details class="ml-survey-preview">
                <summary>Ver las preguntas de la consulta</summary>
                <?php showSurveyQuestions ($db, $surveyid, true); ?>
            </details>
            <?php
        }
        catch (Exception $e){
            removeToken ();
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} getting survey for code.");
        }
    }

    /**
     * If the DNI is not in Parciciants table the method generates a ECDSA key pair,
     * encrypts private key using the DNI and stores the hased DNI and the
     * key pair in Participants table.
     */
    private function insertParticipant ($db, $dni, $hashdni){
        $keypair = generateKeyPair ();

        openssl_pkey_export($keypair, $privatekey, $dni);
        $public_key_details = openssl_pkey_get_details($keypair);
        $publickey = $public_key_details['key'];
        $query = $db->prepare ("INSERT into {Participants} (participant, privatekey, publickey) " .
            "values (:part, :priv, :pub)");
        $query->bindParam (":part", $hashdni, PDO::PARAM_STR);
        $query->bindParam (":priv", $privatekey, PDO::PARAM_STR);
        $query->bindParam (":pub", $publickey, PDO::PARAM_STR);
        $query->execute ();
        return $db->lastInsertId ();
    }

    /**
     * In first place, this method gets the email address, the pension scheme and
     * the two PDFs from the request. The training record must be digitally signed: the
     * DNI is read from it.
     * The other PDF, for those in Clases Pasivas, is the MUFACE membership certificate: its
     * digital signature is checked too. For the rest (career civil servants who joined from
     * 2011 on and interim ones) it is the work history report (vida laboral): it must show that the holder is working for a public employer. The
     * DNI in this PDF must be the one in the training record.
     * The hash of the DNI identifies the participant: the email address is only used for
     * sending the link.
     * 
     * It calls insertParticipant if the DNI isn't in Participants table. If it was already
     * there ckecks if this DNI has participated in the survey. If it has, shows a message
     * and ends.
     * 
     * If the DNI hasn't participated in the survey, the method 
     * generates a random 256bit key, encrypts the DNI with this key 
     * (as it is needed later) and stores the key (hashed), the encrypted DNI and the
     * survey ID in the Participation table. Then it sends this information to the email address
     * formatted as an URL.
     */
    private function generateCode (){
        $email = isset ($_REQUEST['email']) && is_string ($_REQUEST['email']) ?
            strtolower (trim ($_REQUEST['email'])) : "";
        $surveyid = $_REQUEST['surveyid'] ?? null;
        if (!is_string ($surveyid) || !ctype_digit ($surveyid)){
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return;
        }
        /* El required del formulario se puede saltar: se comprueba también aquí. */
        if (($_REQUEST['acepto'] ?? null) !== "1"){
            echo ("<p><strong>Para participar hay que aceptar el uso de los documentos " .
                "para verificar que eres docente de la enseñanza pública.</strong></p>");
            return;
        }
        if ($email == "" || filter_var ($email, FILTER_VALIDATE_EMAIL) === false) {
            echo ("<p><strong>La dirección de correo es incorrecta.</strong></p>");
            return;
        }

        $surveyname = "";
        try {

            $db = dbConn ();
            /* El nombre va tal cual en el correo y escapado en la página. */
            $mailsurveyname = $this->getSurveyName ($db, $surveyid);
            if ($mailsurveyname === null){
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                return;
            }
            $surveyname = h ($mailsurveyname);
            if (!$this->isActive ($db, $surveyid)){
                echo ("<p><strong>La consulta <em>{$surveyname}</em> no está abierta.</strong></p>");
                return;
            }
            if (!$this->checkDomain ($db, $email)){
                return;
            }

            $dni = $this->getDni ();
            if ($dni === null)
                return;
            /* El hash del DNI identifica a la persona: el correo solo sirve
               para hacerle llegar el enlace. */
            $hashdni = hash ('sha256', $dni);

            if (!lockParticipant ($db, $hashdni)){
                echo ("<p><strong>Hay otra petición en curso con el DNI del extracto. " .
                    "Inténtalo de nuevo en unos segundos.</strong></p>");
                return;

            }
            try {
                $reserved = $this->reserveCode ($db, $dni, $hashdni, $surveyid);
            }
            finally {
                unlockParticipant ($db, $hashdni);
            }
            if ($reserved === null)
                return;
            [$pid, $code] = $reserved;
            $mailer = new MlMailer ();
            $mailer->configure ();
            $mailer->sendCode ($email, $pid, url_base64_encode ($code), $mailsurveyname);
            echo ("<p><strong>El código para participar en la consulta <em>{$surveyname}</em> " . 
                "ha sido enviado a la dirección indicada.</strong></p>");
        }
        catch (Exception $e){
            echo ("<p><strong>Error generando código para la consulta <em>{$surveyname}</em></strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} when generating code for survey");
        }
    }


    /* Devuelve la ruta temporal de un PDF subido, o null (con el motivo ya
       mostrado) si no ha llegado bien. */
    private function getUpload (string $field, string $docname): ?string {
        $file = $_FILES[$field] ?? null;
        if (!is_array ($file) || !is_int ($file['error'] ?? null) ||
                $file['error'] == UPLOAD_ERR_NO_FILE){
            echo ("<p><strong>Hay que adjuntar el {$docname} en PDF.</strong></p>");
            return null;
        }
        if ($file['error'] == UPLOAD_ERR_INI_SIZE || $file['error'] == UPLOAD_ERR_FORM_SIZE){
            echo ("<p><strong>El fichero del {$docname} está vacío o es demasiado grande.</strong></p>");
            return null;
        }
        if ($file['error'] != UPLOAD_ERR_OK || !is_uploaded_file ($file['tmp_name'])){
            echo ("<p><strong>Error al recibir el {$docname}.</strong></p>");
            logMessage (LOGGER_ERROR, "Upload error {$file['error']} getting the PDF.");
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
    private function getDni (): ?string {
        $kind = $_REQUEST['tipo'] ?? null;
        if ($kind !== self::KIND_PASSIVE && $kind !== self::KIND_OTHER){
            echo ("<p><strong>Hay que indicar si perteneces a Clases Pasivas o al resto.</strong></p>");
            return null;
        }
        $docname = $kind === self::KIND_PASSIVE ? "certificado de afiliación a MUFACE" :
            "informe de vida laboral";
        $extracto = $this->getUpload ('extracto', "extracto de formación");
        if ($extracto === null)
            return null;
        $document = $this->getUpload ('documento', $docname);
        if ($document === null)
            return null;
        /* Para que el mensaje de error diga de qué fichero habla. */
        $checking = "extracto de formación";
        try {
            checkExtractoSignature ($extracto);
            $dni = getPdfDni ($extracto);
            $checking = $docname;
            if ($kind === self::KIND_PASSIVE){
                checkMufaceSignature ($document);
                $documentdni = getMufaceDni ($document);
            }
            else
                $documentdni = getVidaLaboralDni ($document);
        }
        catch (PdfSignException $e){
            echo ("<p><strong>" . ucfirst ($checking) . ": " . h ($e->getMessage ()) . "</strong></p>");
            return null;
        }
        if (!hash_equals ($dni, $documentdni)){
            echo ("<p><strong>El DNI del {$docname} no coincide con el del extracto de " .
                "formación.</strong></p>");
            logMessage (LOGGER_WARN, "PDF rejected: the DNI is not the one in the training record.");
            return null;
        }
        return $dni;
    }


    /* Busca o crea a la participante y guarda la petición de código. Se
       llama con el bloqueo del DNI cogido. Devuelve [pid, código],
       o null si no procede (ya ha participado o ya pidió uno hace poco). */
    private function reserveCode ($db, $dni, $hashdni, $surveyid){
        $participants = $db->prepare ("SELECT participantid From {Participants} " .
            "WHERE participant = :participant ORDER BY participantid LIMIT 1");
        $participants->bindParam (":participant", $hashdni, PDO::PARAM_STR);
        $participants->execute ();
        $participantid = -1;
        if ($participants->rowCount () == 0){
            $participantid = $this->insertParticipant ($db, $dni, $hashdni);
        }
        else {
            $participant = $participants->fetch ();
            $participantid = $participant['participantid'];
        }
        $participants->closeCursor ();
        /*if (hasCode ($db, $participantid, $surveyid)){ //Echar un vistazo
            //Needs a time limit.
            echo ("<p><strong>La dirección de correo indicada ya ha solicitado un código para esta consulta</strong></p>");
            return null;
        }*/
        if (hasParticipated ($db, $participantid, $surveyid)){
            echo ("<p><strong>Ya se ha votado en esta consulta con el DNI del extracto.</strong></p>");
            return null;
        }

        /* participant va cifrado con un código aleatorio distinto en cada
           petición, así que no sirve para buscar las anteriores: el límite
           por hora se comprueba con esta etiqueta fija por DNI y consulta. */
        $requesttag = hash ('sha256', $hashdni . ':' . $surveyid);
        if ($this->checkParticipation ($db, $requesttag, $surveyid)){
            return null;
        }

        $code = random_bytes (32);
        $passwd = hash ('sha256', $code);
        $encrypteddni = base64_encode (encrypt ($dni, $code));
        $query = $db->prepare ("INSERT into {Participation} (participant, surveyid, participationkey, requesttag) " .
            "values (:id, :sid, :pwd, :tag)");
        $query->bindParam (":id", $encrypteddni, PDO::PARAM_STR);
        $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $query->bindParam (":pwd", $passwd, PDO::PARAM_STR);
        $query->bindParam (":tag", $requesttag, PDO::PARAM_STR);
        $query->execute ();
        return [$db->lastInsertId (), $code];
    }


    private function checkDomain ($db, $email){
        $query = $db->prepare ("SELECT alloweddomains FROM {SystemConfig} LIMIT 1");
        $query->execute ();
        if ($query->rowCount () == 0){
            echo ("<p><strong>El sistema no está configurado aún. No se puede participar.</strong></p>");
            return false;
        }
        $domainstring = $query->fetch()['alloweddomains'] ?? "";
        $query->closeCursor ();
        $domains = array_filter (explode (" ", strtolower (trim ($domainstring))));
        /* Sin dominios configurados puede participar cualquiera. */
        if (empty ($domains))
            return true;
        $emaildomain = substr (strrchr ($email, "@"), 1);
        foreach ($domains as $key => $domain) {
            if ($emaildomain == $domain)
                return true;
        }
        echo ("<p><strong>La dirección de correo proporcionada no es de un dominio autorizado.</strong></p>");
        return false;
    }


    private function getSurveyName ($db, $sid): ?string {
        $query = $db->prepare ("SELECT surveyname FROM {Surveys} WHERE surveyid = :sid");
        $query->bindParam (":sid", $sid, PDO::PARAM_INT);
        $query->execute ();
        $name = $query->fetchColumn ();
        $query->closeCursor ();
        return $name === false ? null : $name;
    }

    private function isActive ($db, $sid){
        $query = $db->prepare ("SELECT 1 FROM {Surveys} WHERE surveyid = :sid
            AND startdate < NOW() AND enddate > NOW()");
        $query->bindParam (":sid", $sid, PDO::PARAM_INT);
        $query->execute ();
        $active = $query->rowCount () > 0;
        $query->closeCursor ();
        return $active;
    }

    private function checkParticipation ($db, $requesttag, $sid){

        $ret = false;
        $query = $db->prepare ("SELECT 1 FROM {Participation} WHERE 
            requesttag = :tag AND surveyid = :sid
            AND participationdate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $query->bindParam (":tag", $requesttag, PDO::PARAM_STR);
        $query->bindParam (":sid", $sid, PDO::PARAM_INT);
        $query->execute ();
        if ($query->rowCount () > 0){
            echo ("<p><strong>Ya existe una peticion de participación 
                para esta consulta con el DNI del extracto </strong></p>");
            echo ("Podrás realizar una nueva petición en una hora.");
            $ret = true;
        }
        $query->closeCursor ();
        return $ret;
    }
}
