<?php
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

    public function addHead (){
        echo (altchaScriptHTML ());
    }

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
        $_SESSION['surveyid'] = $_REQUEST['responseid'];
        unset($_REQUEST['responseid']);
        try {
            $db = dbConn ();
            $survey = $db->prepare ("SELECT surveyname FROM {Surveys} " .
                "WHERE surveyid = :id");
            $survey->bindParam (":id", $_SESSION['surveyid'], PDO::PARAM_INT);
            $survey->execute ();
            if ($survey->rowCount () == 0){
                removeToken ();
                echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
                logMessage (LOGGER_ERROR, "Survey {$_SESSION['surveyid']} does not exist.");
                return;
            }
            $row = $survey->fetch ();
            //echo ("<h2>Obteniendo código para la consulta <em>{$row['surveyname']}</em>.</h2>");
            $_SESSION['surveyname'] = $row['surveyname'];
            $survey->closeCursor ();
            if (!showSurveyHeader ($db, $_SESSION['surveyid'], true)){
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
                    <label for="email">Dirección de correo
                      <p><small><em>* Los dominios autorizados son:
                       <?= Config::$alloweddomains == ""? "cualquiera" : str_replace (" ", ", ",
                           Config::$alloweddomains);?>
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
                <?php showSurveyQuestions ($db, $_SESSION['surveyid'], true); ?>
            </details>
            <?php
        }
        catch (Exception $e){
            removeToken ();
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} getting survey for code.");
        }
    }

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
    private function generateCode (){
        $email = $_REQUEST['email'];
        $surveyid = $_SESSION['surveyid'];
        $surveyname = $_SESSION['surveyname'];
        clearSessionVariables ();
        if (is_null ($email) || $email == "") {
            echo ("<p><strong>La dirección de correo es incorrecta.</strong></p>");
            return;
        }
        
        try {
            
            $db = dbConn ();
            if (!$this->checkDomain ($db, $email)){
                return;
            }

            $hashmail = hash ('sha256', $email);
            $participants = $db->prepare ("SELECT participantid From {Participants} " .
                "WHERE participant = :participant");
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
                return;
            }*/
            if (hasParticipated ($db, $participantid, $surveyid)){
                echo ("<p><strong>La dirección de correo indicada ya ha participado en esta consulta.</strong></p>");
                return;
            }

            $code = random_bytes (32);
            
            if ($this->checkParticipation ($db, $participant, $surveyid)){
                return;
            }
            $passwd = hash ('sha256', $code);
            $participant = base64_encode (encrypt ($email, $code));
            $query = $db->prepare ("INSERT into {Participation} (participant, surveyid, participationkey) " .
                "values (:id, :sid, :pwd)");
            $query->bindParam (":id", $participant, PDO::PARAM_STR);
            $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $query->bindParam (":pwd", $passwd, PDO::PARAM_STR);
            $query->execute ();
            $pid = $db->lastInsertId ();
            $mailer = new MlMailer ();
            $mailer->configure ();
            $mailer->sendCode ($email, $pid, url_base64_encode ($code), $surveyname);
            echo ("<p><strong>El código para participar en la consulta <em>{$surveyname}</em> " . 
                "ha sido enviado a la dirección indicada.</strong></p>");
        }
        catch (Exception $e){
            echo ("<p><strong>Error generando código para la consulta <em>{$surveyname}</em></strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} when generating code for survey");
        }
    }

    private function checkDomain ($db, $email){
        $query = $db->prepare ("SELECT alloweddomains FROM {SystemConfig} LIMIT 1");
        $query->execute ();
        if ($query->rowCount () == 0){
            echo ("<p><strong>El sistema no está configurado aún. No se puede participar.</strong></p>");
            return false;
        }
        $domainstring = $query->fetch()['alloweddomains'];
        $query->closeCursor ();
        $domains = explode (" ", $domainstring);
        $emaildomain = explode ("@", $email)[1];
        foreach ($domains as $key => $domain) {
            if ($emaildomain == $domain)
                return true;
        }
        echo ("<p><strong>La dirección de correo proporcionada no es de un dominio autorizado.</strong></p>");
        return false;
    }

    private function checkParticipation ($db, $participant, $sid){
        $ret = false;
        $query = $db->prepare ("SELECT 1 FROM {Participation} WHERE 
            participant = :part AND surveyid = :sid
            AND participationdate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $query->bindParam (":part", $participant, PDO::PARAM_STR);
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
