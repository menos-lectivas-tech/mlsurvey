<?php
/**
 * This class handle the whole participation proccess.
 */
require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
//require_once 'views/response_survey.php';
require_once 'utils/participation.php';
require_once 'utils/token.php';
require_once 'utils/crypt.php';
require_once 'include/fileparams.php';
require_once 'utils/showsurvey.php';
require_once 'utils/host.php';

class Participate extends View {
    public const ACTION = "Enviar";
    public const PID = "pid";
    public const KEY = "auth";
    public bool $istest = false;
    //This const should be remove in non alpha versions.
    private const TESTID = 0;

    private const COOKIE_KEY ="lacookie";
    /* Formularios de participación abiertos en la sesión, y campo del
       formulario que dice cuál de ellos se envía. */
    private const PARTICIPATIONS = "participations";
    private const FORM_FIELD = "participation";
    private const PARTICIPATIONS_MAX = 20;
    private $key = "";
    private string $email = "";
    private int $surveyid = -1;

    /**
     * Sends a cookie with a 256bit random key used to encrypt the private key for
     * signing the responses on submit.
     */
    function doInit (){
        startSession ();
        /* Una sola clave para todas las pestañas: si cada enlace abierto
           generase la suya, los formularios abiertos antes ya no podrían
           descifrar su clave de firma. */
        $current = isset ($_COOKIE[self::COOKIE_KEY]) && is_string ($_COOKIE[self::COOKIE_KEY]) ?
            base64_decode ($_COOKIE[self::COOKIE_KEY], true) : false;
        $this->key = ($current !== false && strlen ($current) == 32) ? $current : random_bytes (32);
        /* Sin dominio: cookie solo para este host. HTTP_HOST puede llevar el
           puerto (localhost:8080) y el navegador rechaza ese dominio.
           Secure solo con HTTPS: servido por HTTP, el navegador descarta
           una cookie Secure y luego no se podría firmar la respuesta. */
        setcookie (self::COOKIE_KEY, base64_encode ($this->key), [
            'expires' => time () + 1800, //Half an hour
            'secure' => isHttps (),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    function getMenuGroup (){
        return ML_MENU_GROUP_SURVEYS;
    }

    public function loadStyles (){
        ?>
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <?php
    }

    /**
     * Depending on the request parameters, this method takes different actions.
     * - If they don't meet the requirements, the method shows the main page.
     * - If the are from an own submit then the responses are stored.
     * - If the request matches a valid record of the Patcicipation table (or the StressTest),
     * shows the responses form. Otherwise shows an error.
     * 
     */
    public function show (){
        startSession ();
        if (isset ($_REQUEST[self::ACTION])){
            $this->insertResponse ();
            return;
        }

        if (!isset ($_REQUEST[self::PID]) || !isset ($_REQUEST[self::KEY])){
            showMain ();
            return;
        }
        

        $pid = $_REQUEST[self::PID];
        $key = $_REQUEST[self::KEY];

        /**
         * Are we in a stress test?
         */
        if (isset (Config::PARAMS['ml_stresstest']) && Config::PARAMS['ml_stresstest']
             && !empty ($_REQUEST["t"]))
            $this->istest = true;


        try {
            $code = url_base64_decode ($key);
            $db = dbConn ();
            
            /*
            Gets the email address from the Participation table using the key in the request
            */
            if (!$this->getEmail ($db, $pid, $code) && !$this->istest){
                echo ("<p><em>La solicitud proporcionada no existe o ha caducado.</em></p>");
                return;
            }
            else if ($this->istest){
                if (!$this->getTestSurvey ($db, $pid, $code)){
                    echo ("<p><em>La solicitud proporcionada no existe o ha caducado.</em></p>");
                    return;
                }
                $formid = $this->saveParticipation ($this->surveyid, self::TESTID, null);
                $this->showSurvey ($db, $this->surveyid, $formid);
                return;
            }
            

            /*
            Gets the private key for singing the responses, then it's encrypting
            using the random key in the cookie. If the key can't be obtined using the
            email address the security is compromised
            */
            $hashmail = hash ('sha256', $this->email);

            $participants = $db->prepare ("SELECT participantid, privatekey " .
                "FROM {Participants} WHERE participant = :part ORDER BY participantid LIMIT 1");

            $participants->bindParam (":part", $hashmail, PDO::PARAM_STR);
            $participants->execute ();
            if ($participants->rowCount () == 0){
                $this->securityError ();
                return;
            }
            $participant = $participants->fetch ();
            $participantid = $participant["participantid"];
            $privatekeycryp = $participant['privatekey'];
            $participants->closeCursor ();
            if (hasParticipated ($db, $participantid, $this->surveyid)){
                ?>
                <p><strong>Ya se ha participado en la consulta desde la dirección de correo
                    indicada.
                </strong></p>
                <?php
                return;
            }
            
            $key = openssl_pkey_get_private ($privatekeycryp, $this->email);
            if ($key === false){
                ?>
                <strong><p>La dirección de correo indicada tiene un problema de seguridad.</p>
                <p>Contacte con la administración del sitio.</p></strong>
                <?php
                return;
            }
            openssl_pkey_export($key, $priv);

            $formid = $this->saveParticipation ($this->surveyid, $participantid,
                encrypt ($priv, $this->key));
            $this->showSurvey ($db, $this->surveyid, $formid);

        }
        catch (Exception $e){
            echo ("<p><strong>Error recuperando los datos para la participación</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} when getting data for response.");
            return;
        }
    }

    private function securityError (){
        ?>
        <p><strong>La dirección de correo o el código para participar no son 
            correctos</strong></p>
        <?php
    }


    /* Guarda lo que necesita el envío de un formulario y devuelve su id.
       Va en el propio formulario, no suelto en la sesión: con dos enlaces
       abiertos a la vez, las respuestas de uno acababan en la consulta
       del otro. */
    private function saveParticipation ($surveyid, $participantid, $privkey): string {
        if (!isset ($_SESSION[self::PARTICIPATIONS]) || !is_array ($_SESSION[self::PARTICIPATIONS]))
            $_SESSION[self::PARTICIPATIONS] = array ();
        $formid = bin2hex (random_bytes (16));
        $_SESSION[self::PARTICIPATIONS][$formid] = [
            'surveyid' => $surveyid,
            'participantid' => $participantid,
            'privkey' => $privkey,
        ];
        $_SESSION[self::PARTICIPATIONS] = array_slice ($_SESSION[self::PARTICIPATIONS],
            -self::PARTICIPATIONS_MAX, null, true);
        return $formid;
    }

    /* Los datos del formulario enviado, que dejan de valer: un envío por formulario. */
    private function takeParticipation (): ?array {
        $formid = $_REQUEST[self::FORM_FIELD] ?? null;
        if (!is_string ($formid) || !isset ($_SESSION[self::PARTICIPATIONS][$formid]))
            return null;
        $participation = $_SESSION[self::PARTICIPATIONS][$formid];
        unset ($_SESSION[self::PARTICIPATIONS][$formid]);
        return $participation;
    }

    private function showSurvey ($db, $surveyid, $formid){

        echo ('<form name="participate" id="participate" action="participate" method="POST">');
        echo ("<input type='hidden' name='" . self::FORM_FIELD . "' value='{$formid}'>");
        showTheSurvey ($db, $surveyid);
        ?>
        <p><input type="submit" class="button-3" name="<?= self::ACTION; ?>" 
            value="<?= self::ACTION ?>"></p>
        </form>
        <?php
    }

    /**
     * Insert the responses in a JSON string:
     * - If the question is a single choice one, stores the selected option number or -1 
     *   if no choice (optional question).
     * - If the question is a multiple choice, stores an array with the selected options or empty
     *   if not answered.
     * 
     * Example:
     * {1:2,2:{1:1,3:2},3:-1,4:{}}
     * - Question 1 is single choice, and the selected option is 2.
     * - Question 2 is multiple choice, and the selected options are 1 and 3.
     * - Question 3 is single and optional, and no option has been selected.
     * - Question 4 is multiple and optional, and no options have been selected.
     * 
     * This JSON string is signed with the participant's private key so it can be
     * validated with its public key.
     */
    private function insertResponse (){
        if (!checkToken ()){
            tokenError ();
            return;           
        }
        $participation = $this->takeParticipation ();
        if ($participation === null){
            $this->securityError ();
            return;
        }
        $surveyid = $participation['surveyid'];
        $participantid = $participation['participantid'];

        
        if ($participantid == self::TESTID){
            $privkey = false;
        }
        else {
            if (!isset ($_COOKIE[self::COOKIE_KEY]) || $participation['privkey'] === null){
                echo ("<p><strong>Error recuperando las cookies para firmar las respuestas.</strong></p>");
                return;
            }
            try {
                $privkey = decrypt ($participation['privkey'], base64_decode ($_COOKIE[self::COOKIE_KEY]));
            }
            catch (Exception $e){
                echo ("<p><strong>Error cryptográfico al firmar las respuestas.</strong></p>");
                logMessage (LOGGER_ERROR, "Signing response: {$e}");
                return;
            }
        }

        try {
            $responsearray = array (); 
            $db = dbConn ();
            $questions = $db->prepare ("SELECT questionid, multiple, optional " . 
                "FROM {Questions} WHERE surveyid = :sid ORDER BY questionid ASC");
            $questions->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $questions->execute ();
            $questionlist = $questions->fetchAll ();
            $questions->closeCursor ();
            foreach ($questionlist as $question){
                $questionid = $question['questionid'];
                $optional = $question['optional'];
                $multiple = $question['multiple'];
                /* Las respuestas se contrastan con las opciones que existen:
                   lo que llega del formulario no es de fiar. */
                $validoptions = $this->getOptionIds ($db, $surveyid, $questionid);
                if ($multiple == 0){
                    $selected = $_REQUEST["op-" . $questionid] ?? null;
                    if ($selected !== null){
                        if (!is_string ($selected) || !ctype_digit ($selected) ||
                            !in_array ((int) $selected, $validoptions, true)){
                            echo ("<p><strong>Error. Hay respuestas no válidas.</strong></p>");
                            return;
                        }
                        $responsearray[$questionid] = (int) $selected;
                    }
                    else if ($optional == 1)
                        $responsearray[$questionid] = -1;
                    else{
                        echo ("<p><strong>Error. Hay preguntas obligatioras no respondidas.</strong></p>");
                        return;
                    }
                }
                else {
                    $responsearray[$questionid] = array();
                    foreach ($validoptions as $optionid){
                        if (isset ($_REQUEST["op-" . $questionid . "-" . $optionid])){
                            $responsearray[$questionid][$optionid] = 1;
                        }
                    }
                    if ($optional == 0 && empty ($responsearray[$questionid])){
                        echo ("<p><strong>Error. Hay preguntas obligatioras no respondidas.</strong></p>");
                        return;
                    }
                }
            }
            $responsejson = json_encode ($responsearray);
            if ($responsejson === false){
                echo ("<p><strong>Error codificando respuestas.</strong></p>");
                $err = json_last_error_msg ();
                logMessage (LOGGER_ERROR, "Error {$err} in json_encode");
                return;
            }
            $responsesign = $this->signResponse ($db, $responsejson, $privkey, $participantid);
            $db->beginTransaction ();
            try {
                if (!$this->isActive ($db, $surveyid)){
                    $db->rollBack ();
                    echo ("<p><strong>La consulta ya no está abierta.</strong></p>");
                    return;
                }
                if ($participantid != self::TESTID){
                    /* Bloquea a la participante hasta el final de la transacción:
                       dos envíos simultáneos no pueden colarse ambos. */
                    $lock = $db->prepare ("SELECT participantid FROM {Participants} " .
                        "WHERE participantid = :pid FOR UPDATE");
                    $lock->bindParam (":pid", $participantid, PDO::PARAM_INT);
                    $lock->execute ();
                    $lock->closeCursor ();
                    if (hasParticipated ($db, $participantid, $surveyid)){
                        $db->rollBack ();
                        echo ("<p><strong>Ya se ha participado en la consulta desde la " .
                            "dirección de correo indicada.</strong></p>");
                        return;
                    }
                }
                $query = $db->prepare ("INSERT INTO {Responses} (surveyid, participantid, response, " .
                    " responsesign) values (:sid, :pid, :res, :ress)");
                $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
                $query->bindParam (":pid", $participantid, PDO::PARAM_INT);
                $query->bindParam (":res", $responsejson, PDO::PARAM_STR);
                $query->bindParam (":ress", $responsesign, PDO::PARAM_STR);
                $query->execute ();
                $db->commit ();
            }
            catch (Exception $e){
                if ($db->inTransaction ())
                    $db->rollBack ();
                throw $e;
            }
            ?>
            <p><strong>Respuestas guardadas.</strong></p>
            <p>Gracias por participar en la consulta.</p>
            <?php
        }   
        catch (Exception $e){
            echo ("<p><strong>Error guardando respuestas.</strong></p>");
            logMessage (LOGGER_ERROR, "{$e} inserting responses.");
            return;
        }
    }

    /**
     * Signs the response with the participants private key.
     * 
     * @param PDO $db PDO database object
     * @param string $response The response in JSON string.
     * @param string $key The participants private key
     * @param int $participantid
     * 
     * @return string The response signature.
     * 
     * @throws Exception An exception with the error.
     */
    private function signResponse ($db, $response, #[\SensitiveParameter] $key, $participantid){
        if ($key === false){
            return "no sign";
        }
        $signkey = openssl_pkey_get_private ($key);
        if (!openssl_sign ($response, $sign, $signkey, getSignAlgo ())){
            throw new Exception("Error in signing " . openssl_error_string());
            return "";
        }
        $query = $db->prepare ("SELECT publickey FROM {Participants} WHERE participantid = :pid");
        $query->bindParam (":pid", $participantid);
        $query->execute ();
        if ($query->rowCount () == 0){
            throw new Exception("Can't find participant for signing: {$participantid}");
            return "";
        }
        $row = $query->fetch ();
        $pubkey = openssl_pkey_get_public ($row['publickey']);
        if ($pubkey === false){
            throw new Exception("Error getting participant keypair: {$participantid}");
            return "";
        }
        $query->closeCursor ();
        $res = openssl_verify ($response, $sign, $pubkey, getSignAlgo ());
        if ($res == 0){
            throw new Exception("Bad signature signing response");
            return "";
        }
        else if ($res == -1 || $res == false){
            throw new Exception("Error signing response " . openssl_error_string());
            return "";
        }
        return base64_encode ($sign);
    }

    /**
     * Decrypts the participant email address stored in the Participation table and
     * stores it in $email private variable.
     * 
     * @param PDO $db PDO database object.
     * @param int $pid The participation id.
     * @param string $code The key for decrypting the email address.
     * 
     * @return bool
     */
    private function getEmail ($db, $pid, $code){
        $hcode = hash ('sha256', $code);
        /* El enlace caduca en una hora y solo sirve mientras la consulta está abierta. */
        $query = $db->prepare ("SELECT p.surveyid, p.participant FROM {Participation} p " .
            "JOIN {Surveys} s ON s.surveyid = p.surveyid " .
            "WHERE p.participationid = :pid AND p.participationkey = :pk " .
            "AND p.participationdate > DATE_SUB(NOW(), INTERVAL 1 HOUR) " .
            "AND s.startdate < NOW() AND s.enddate > NOW()");
        $query->bindParam (":pid", $pid, PDO::PARAM_INT);
        $query->bindParam (":pk", $hcode, PDO::PARAM_STR);
        $query->execute ();
        if ($query->rowCount () == 0)
            return false;
        $row = $query->fetch ();
        $this->email = decrypt (base64_decode ($row['participant']), $code);
        $this->surveyid = $row['surveyid'];
        return true;
    }


    private function getOptionIds ($db, $surveyid, $questionid): array {
        $options = $db->prepare ("SELECT optionid FROM {Options} " .
            "WHERE surveyid = :sid AND questionid = :qid");
        $options->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $options->bindParam (":qid", $questionid, PDO::PARAM_INT);
        $options->execute ();
        $ids = array_map ('intval', $options->fetchAll (PDO::FETCH_COLUMN));
        $options->closeCursor ();
        return $ids;
    }

    private function isActive ($db, $surveyid){
        $query = $db->prepare ("SELECT 1 FROM {Surveys} WHERE surveyid = :sid " .
            "AND startdate < NOW() AND enddate > NOW()");
        $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $query->execute ();
        $active = $query->rowCount () > 0;
        $query->closeCursor ();
        return $active;
    }

    /**
     * When on a stress test checks if the request is valid.
     * 
     * @param PDO $db PDO database object.
     * @param int $pid The participation id.
     * @param string $code The key for validating the request.
     * 
     * @return bool
     */
    private function getTestSurvey ($db, $pid, $code){
        $hcode = hash ('sha256', $code);
        $query = $db->prepare ("SELECT surveyid FROM {StressTest} " . 
            "WHERE participationid = :pid AND participationkey = :pk");
        $query->bindParam (":pid", $pid, PDO::PARAM_INT);
        $query->bindParam (":pk", $hcode, PDO::PARAM_STR);
        $query->execute ();
         if ($query->rowCount () == 0)
            return false;
        $row = $query->fetch ();
        $this->surveyid = $row['surveyid'];
        return true;
    }


    /**
     * Checks if survey has "show partial results" option selected.
     * 
     * @param PDO $db PDO database object.
     * @param int $surveyid The survey id.
     * 
     * @return bool
     */
    private function hasPartials ($db, $surveyid){
        $query = $db->prepare ("SELECT showpartial FROM {Surveys}
            WHERE surveyid = :sid LIMIT 1");
        $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $query->execute ();
        $survey = $query->fetch ();
        $ret = !empty ($survey["showpartial"]);
        $query->closeCursor ();
        return $ret;
    }

    /**
     * Returns the current partial result when configured.
     * 
     * @param PDO $db PDO database object.
     * @param int $surveyid The survey id.
     * 
     * @return string JSON with partial result.
     * 
     */
    private function getPartial ($db, $surveyid){
        $ret = null;
        $query = $db->prepare ("SELECT results FROM {Results} 
            WHERE surveyid = :sid");
        $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $query->execute ();
        if ($query->rowCount () == 0){
            $ret =  $this->buildPartial ($db, $surveyid);
        }
        else {
            $ret = json_decode ($query->fetch()["results"], true);
        }
        $query->closeCursor ();
        return $ret;
    }

    /**
     * Creates the partial result record when configured and no record is found.
     * 
     * @param PDO $db PDO database object.
     * @param int $surveyid The survey id.
     * 
     */
    private function buildPartial ($db, $surveyid){
        $partial = array();
        $partial["Total"] = 0;
        $partial["Responses"] = array ();
        $questions = $db->prepare ("SELECT questionid FROM {Questions} 
            WHERE surveyid = :sid");
        $questions->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $questions->execute ();
        while ($question = $questions->fetch ()){
            $questionid = $question["questionid"];
            $partial["Responses"][$questionid] = array ();
            $options = $db->prepare ("SELECT optionid FROM {Options} WHERE
                surveyid = :sid AND questionid = :qid");
            $options->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $options->bindParam (":qid", $questionid, PDO::PARAM_INT);
            $options->execute ();
            while ($option = $options->fetch ()){
                $partial["Responses"][$questionid][$option["optionid"]] = 0;
            }
            $options->closeCursor ();
        }
        $questions->closeCursor ();

        $result = json_encode ($partial);
        $query = $db->prepare ("INSERT INTO {Results} (surveyid, results, ispartial) values
            (:sid, :res, 1)");
        $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $query->bindParam (":res", $result, PDO::PARAM_STR);
        $query->execute ();
        return $partial;
    }

    /**
     * Updates current partial result when configured.
     * 
     * @param PDO $db PDO database object.
     * @param int $surveyid The survey id.
     * @param string $partials JSON with partial result.
     * 
     */
    private function updatePartials ($db, $surveyid, $partials){
        $partials["Total"]++;
        $res = json_encode ($partials);
        $query = $db->prepare ("UPDATE {Results} set results = :res, ispartial = 1
            WHERE surveyid = :sid");
        $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $query->bindParam (":res", $res, PDO::PARAM_STR);
        $query->execute ();
    }

}
