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

class GetCode extends View {

    private const ACTION = "Solicitar";

    function getMenuGroup (){
        return ML_MENU_GROUP_SURVEYS;
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
     * - If it's a submit from Surveys class with survey info, the form for asking email addres.
     * - If it's an own submit, sends the email and shows the result (success or failure).
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
        
        if (!isset ($_REQUEST[Surveys::SURVEY_RESPONSE]) || !isset($_REQUEST['responseid'])){
            showMain ();
            return;
        }
        $surveyid = $_REQUEST['responseid'];
        if (!is_string ($surveyid) || !ctype_digit ($surveyid)){
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return;
        }
        try {
            $db = dbConn ();
            if ($this->getSurveyName ($db, $surveyid) === null){
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                logMessage (LOGGER_ERROR, "Survey {$surveyid} does not exist.");
                return;
            }
            if (!showSurveyHeader ($db, $surveyid, true)){
                removeToken ();
                return;
            }
            ?>
            <section class="ml-participate-card">
                <h3>Participar en la consulta</h3>
                <p>Introduce tu dirección de correo y te enviaremos un enlace personal
                    para participar.</p>
                <p>Si la dirección es de un <em>dominio autorizado</em><sup>*</sup> recibirás un mensaje con un enlace.
                   Comprueba tu correo y pincha en el enlace para participar. El enlace recibido caduca en
                   una hora.</p>
                <p>Si quieres pensarte las respuestas antes de introducir tu dirección de correo, puedes
                   verlas pinchando en <em>Ver las preguntas de la consulta</em> debajo de este recuadro.</p>
                <form id="getcode" name="getcode" method="POST" action="get_code">
                    <?= setTokenHTML (); ?>
                    <!-- La consulta va en el propio formulario: en la sesión
                         la pisaría otra pestaña con otra consulta abierta. -->
                    <input type="hidden" name="surveyid" value="<?= (int) $surveyid; ?>">
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
     * If the email address is not in Parciciants table the method generates a ECDSA key pair,
     * encrypts private key using the email address and stores the hased email address and the
     * key pair in Participants table.
     */
    private function insertParticipant ($db, $email, $hashmail){
        $keypair = generateKeyPair ();

        openssl_pkey_export($keypair, $privatekey, $email);
        $public_key_details = openssl_pkey_get_details($keypair);
        $publickey = $public_key_details['key'];
        $query = $db->prepare ("INSERT into {Participants} (participant, privatekey, publickey) " .
            "values (:part, :priv, :pub)");
        $query->bindParam (":part", $hashmail, PDO::PARAM_STR);
        $query->bindParam (":priv", $privatekey, PDO::PARAM_STR);
        $query->bindParam (":pub", $publickey, PDO::PARAM_STR);
        $query->execute ();
        return $db->lastInsertId ();
    }

    /**
     * In first place, this method gets the email address from the requests and
     * calls insertParticipant if the address isn't in Participants table and is in the configured
     * allowed domains.
     * 
     * If the address was already in the Participants table ckecks if this email address
     * has participated in the survey. If it has, shows a message and ends.
     * 
     * If the email address hasn't participated in the survey, the method 
     * generates a random 256bit key, encrypts the email address with this key 
     * (as it is needed later) and stores the key (hashed), the encrypted email and the
     * survey ID in the Participation table. Then it sends this information to the email address
     * formatted as an URL.
     */
    private function generateCode (){
        /* Normalizada: el hash de la dirección identifica a la persona, y
           Nombre@Dominio.es y nombre@dominio.es son el mismo buzón. */
        $email = isset ($_REQUEST['email']) && is_string ($_REQUEST['email']) ?
            strtolower (trim ($_REQUEST['email'])) : "";
        $surveyid = $_REQUEST['surveyid'] ?? null;
        if (!is_string ($surveyid) || !ctype_digit ($surveyid)){
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
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

            $hashmail = hash ('sha256', $email);

            if (!lockParticipant ($db, $hashmail)){
                echo ("<p><strong>Hay otra petición en curso con esta dirección de correo. " .
                    "Inténtalo de nuevo en unos segundos.</strong></p>");
                return;

            }
            try {
                $reserved = $this->reserveCode ($db, $email, $hashmail, $surveyid);
            }
            finally {
                unlockParticipant ($db, $hashmail);
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


    /* Busca o crea a la participante y guarda la petición de código. Se
       llama con el bloqueo de la dirección cogido. Devuelve [pid, código],
       o null si no procede (ya ha participado o ya pidió uno hace poco). */
    private function reserveCode ($db, $email, $hashmail, $surveyid){
        $participants = $db->prepare ("SELECT participantid From {Participants} " .
            "WHERE participant = :participant ORDER BY participantid LIMIT 1");
        $participants->bindParam (":participant", $hashmail, PDO::PARAM_STR);
        $participants->execute ();
        $participantid = -1;
        if ($participants->rowCount () == 0){
            $participantid = $this->insertParticipant ($db, $email, $hashmail);
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
            echo ("<p><strong>La dirección de correo indicada ya ha participado en esta consulta.</strong></p>");
            return null;
        }

        /* participant va cifrado con un código aleatorio distinto en cada
           petición, así que no sirve para buscar las anteriores: el límite
           por hora se comprueba con esta etiqueta fija por correo y consulta. */
        $requesttag = hash ('sha256', $hashmail . ':' . $surveyid);
        if ($this->checkParticipation ($db, $requesttag, $surveyid)){
            return null;
        }

        $code = random_bytes (32);
        $passwd = hash ('sha256', $code);
        $encryptedmail = base64_encode (encrypt ($email, $code));
        $query = $db->prepare ("INSERT into {Participation} (participant, surveyid, participationkey, requesttag) " .
            "values (:id, :sid, :pwd, :tag)");
        $query->bindParam (":id", $encryptedmail, PDO::PARAM_STR);
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
                para esta consulta con la dirección de correo indicada </strong></p>");
            echo ("Podrás realizar una nueva petición en una hora.");
            $ret = true;
        }
        $query->closeCursor ();
        return $ret;
    }
}
