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

class Participate extends View {
    public const ACTION = "Enviar";
    public const PID = "pid";
    public const KEY = "auth";
    public bool $istest = false;
    //This const should be remove in non alpha versions.
    private const TESTID = 0;

    private const COOKIE_KEY ="lacookie";
    private $key = "";
    private string $email = "";
    private int $surveyid = -1;

    /**
     * Sends a cookie with a 256bit random key used to encrypt the private key for
     * signing the responses on submit.
     */
    function doInit (){
        startSession ();
        $this->key = random_bytes (32);
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'];
        setcookie (self::COOKIE_KEY, base64_encode ($this->key), time () + 1800, //Half an hour
            "", $host, true, true);
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
             && $_REQUEST["t"])
            $this->istest = true;

        clearSessionVariables ();
        

        $code = url_base64_decode ($key);

        try {
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
                $_SESSION['surveyid'] = $this->surveyid;
                $_SESSION['participantid'] = self::TESTID;
                $this->showSurvey ($db, $this->surveyid, self::TESTID);
            }
            

            /*
            Gets the private key for singing the responses, then it's encrypting
            using the random key in the cookie. If the key can't be obtined using the
            email address the security is compromised
            */
            $hashmail = hash ('sha256', $this->email);
            $participants = $db->prepare ("SELECT participantid, privatekey
                FROM {Participants} WHERE participant = :part");
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
            /*
            Encrypts the private key using the random key and stores it in a session
            varable.
            */
            $_SESSION['privkey'] = encrypt ($priv, $this->key);
            $_SESSION['surveyid'] = $this->surveyid;
            $_SESSION['participantid'] = $participantid;
            $this->showSurvey ($db, $this->surveyid, $participantid);
        }
        catch (Exception $e){
            echo ("<p><strong>Error recuperando los datos para la participación</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} when getting data for response.");
            clearSessionVariables ();
            return;
        }
    }

    private function securityError (){
        ?>
        <p><strong>La dirección de correo o el código para participar no son 
            correctos</strong></p>
        <?php
    }

    
    private function showSurvey ($db, $surveyid){
        echo ('<form name="participate" id="participate" action="participate" method="POST">');
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
        $surveyid = $_SESSION['surveyid'];
        $participantid = $_SESSION['participantid'];

        
        if ($participantid == self::TESTID){
            $privkey = false;
        }
        else {
            if (!isset ($_COOKIE[self::COOKIE_KEY])){
                echo ("<p><strong>Error recuperando las cookies para firmar las respuestas.</strong></p>");
                return;
            }
            try {
                $privkey = decrypt ($_SESSION['privkey'], base64_decode ($_COOKIE[self::COOKIE_KEY]));
            }
            catch (Exception $e){
                echo ("<p><strong>Error cryptográfico al firmar las respuestas.</strong></p>");
                logMessage (LOGGER_ERROR, "Signing response: {$e}");
                return;
            }
        }

        clearSessionVariables ();
        try {
            $responsearray = array (); 
            $db = dbConn ();
            $partials = null;
            $haspartials = false;
            if ($haspartials = $this->hasPartials ($db, $surveyid))
                $partials = $this->getPartial ($db, $surveyid);

            $questions = $db->prepare ("SELECT questionid, multiple, optional " . 
                "FROM {Questions} WHERE surveyid = :sid ORDER BY questionid ASC");
            $questions->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $questions->execute ();
            if ($questions->rowCount () > 0){
                while ($question = $questions->fetch ()){
                    $questionid = $question['questionid'];
                    $optional = $question['optional'];
                    $multiple = $question['multiple'];
                    if ($multiple == 0){
                        if (isset ($_REQUEST["op-" . $questionid])){
                            $selected = $_REQUEST["op-" . $questionid];
                            $responsearray[$questionid] = $selected;
                            if($haspartials)
                                $partials["Responses"][$questionid][$selected]++;
                        }
                        else if ($optional == 1)
                            $responsearray[$questionid] = -1;
                        else{
                            echo ("<p><strong>Error. Hay preguntas obligatioras no respondidas.</strong></p>");
                            $questions->closeCursor ();
                            return;
                        }
                    }
                    else {
                        $toptions = $_REQUEST["topt-" . $questionid];
                        $responsearray[$questionid] = array();
                        $responses = 0;
                        for ($i = 1; $i <= $toptions; $i++){
                            if (isset ($_REQUEST["op-" . $questionid . "-" . $i])){
                                $responsearray[$questionid][$i] = 1;
                            
                                if($haspartials)
                                    $partials["Responses"][$questionid][$i] += 1;
                            }
                        }
                        if ($optional == 0 && empty ($responsearray[$questionid])){
                            echo ("<p><strong>Error. Hay preguntas obligatioras no respondidas.</strong></p>");
                            $questions->closeCursor ();
                            return;
                        }
                    }
                }
            }
            $questions->closeCursor ();
            $responsejson = json_encode ($responsearray);
            if ($responsejson === false){
                echo ("<p><strong>Error codificando respuestas.</strong></p>");
                $err = json_last_error_msg ();
                logMessage (LOGGER_ERROR, "Error {$err} in json_encode");
                return;
            }
            $responsesign = $this->signResponse ($db, $responsejson, $privkey, $participantid);
            $query = $db->prepare ("INSERT INTO {Responses} (surveyid, participantid, response, " .
                " responsesign) values (:sid, :pid, :res, :ress)");
            $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $query->bindParam (":pid", $participantid, PDO::PARAM_INT);
            $query->bindParam (":res", $responsejson, PDO::PARAM_STR);
            $query->bindParam (":ress", $responsesign, PDO::PARAM_STR);
            $query->execute ();
            if ($haspartials)
                $this->updatePartials ($db, $surveyid, $partials);
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
        $query = $db->prepare ("SELECT surveyid, participant FROM {Participation} " . 
            "WHERE participationid = :pid AND participationkey = :pk");
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
