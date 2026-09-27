<?php
require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'views/response_survey.php';
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
        if (isset (Config::PARAMS['ml_stresstest']) && Config::PARAMS['ml_stresstest']
             && $_REQUEST["t"])
            $this->istest = true;

        clearSessionVariables ();
        

        $code = url_base64_decode ($key);

        try {
            $db = dbConn ();
            
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
            //This block should be removed in non alpha versions
            /*if ($email == "prueba@mierda.cow" && $code = "123456"){
                $_SESSION['privkey'] = "no";
                $_SESSION['surveyid'] = $surveyid;
                $_SESSION['participantid'] = self::TESTID;
                $this->showSurvey ($db, $surveyid);
                return;
            }*/

            $hashmail = hash ('sha256', $this->email);
            $participants = $db->prepare ("SELECT participantid, privatekey " .
                "FROM {Participants} WHERE participant = :part");
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
            /*$codes = $db->prepare ("SELECT passwd FROM {Participation} WHERE " .
                "participantid = :pid AND surveyid = :sid");
            $codes->bindParam (":pid", $participantid, PDO::PARAM_INT);
            $codes->bindParam (":sid", $this->surveyid, PDO::PARAM_INT);
            $codes->execute ();
            if ($codes->rowCount () == 0){
                ?>
                <p><strong>La dirección de correo indicada no ha solicitado código para
                    participar</strong></p>
                <?php
                return;
            }
            $codecrypted = ($codes->fetch())['passwd'];
            $codes->closeCursor ();
            if (!password_verify ($code, $codecrypted)){
                $this->securityError ();
                return;
            }*/
            $key = openssl_pkey_get_private ($privatekeycryp, $this->email);
            if ($key === false){
                ?>
                <strong><p>La dirección de correo indicada tiene un problema de seguridad.</p>
                <p>Contacte con la administración del sitio.</p></strong>
                <?php
                return;
            }
            openssl_pkey_export($key, $priv);
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
}
