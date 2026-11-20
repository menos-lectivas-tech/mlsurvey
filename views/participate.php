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
require_once 'utils/clientcert.php';
require_once 'utils/verification.php';

class Participate extends View {
    public const ACTION = "Enviar";
    public const PID = "pid";
    public const KEY = "auth";
    public const SID = "sid";
    public bool $istest = false;
    //This const should be remove in non alpha versions.
    private const TESTID = 0;

    /* Formularios de participación abiertos en la sesión, y campo del
       formulario que dice cuál de ellos se envía. */
    private const PARTICIPATIONS = "participations";
    private const FORM_FIELD = "participation";
    private const PARTICIPATIONS_MAX = 20;
    private int $surveyid = -1;

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
        <?php
    }

    /**
     * Depending on the request parameters, this method takes different actions.
     * - If it's the submit of the survey form, stores the vote.
     * - In a stress test, the request must match a record of the StressTest table.
     * - Otherwise (participate?sid=N), identifies the participant by the DNI of the
     *   client certificate: they must be registered with it, their account verified
     *   and they must not have voted yet. Then shows the survey form.
     */
    public function show (){
        startSession ();
        if (isset ($_REQUEST[self::ACTION])){
            $this->insertResponse ();
            return;
        }

        /* Pruebas de carga: el enlace lleva el código de la tabla StressTest. */
        if (!empty (Config::PARAMS['ml_stresstest']) && !empty ($_REQUEST["t"])
                && isset ($_REQUEST[self::PID]) && isset ($_REQUEST[self::KEY])){
            $this->istest = true;
            $this->showTest ($_REQUEST[self::PID], $_REQUEST[self::KEY]);
            return;
        }

        $surveyid = $_GET[self::SID] ?? null;
        if (!is_string ($surveyid) || !ctype_digit ($surveyid)){
            showMain ();
            return;
        }
        $surveyid = (int) $surveyid;
        /* Se vota en la URL que pide el certificado electrónico. */
        if (mustGoToCertHost ()){
            echo ("<p><strong>Para votar tienes que identificarte con tu certificado " .
                "electrónico.</strong></p>");
            echo ("<p><a class='button-3' href='" . h (certURL ("participate?sid={$surveyid}")) .
                "'>Continuar</a></p>");
            return;
        }

        $dni = $this->getVoterDni ($surveyid);
        if ($dni === null)
            return;

        try {
            $db = dbConn ();
            if (!$this->isActive ($db, $surveyid)){
                echo ("<p><strong>La consulta no existe o no está abierta.</strong></p>");
                return;
            }
            $participantid = $this->participantByDni ($db, $dni);
            if ($participantid === null){
                echo ("<p><strong>No hay ninguna cuenta registrada con el DNI de tu " .
                    "certificado electrónico.</strong></p>");
                echo ("<p>Para participar en las consultas tienes que registrarte antes: " .
                    "<a href='register'>Regístrate</a>.</p>");
                return;
            }
            if (!isVerified ($db, $participantid)){
                echo ("<p><strong>Tu cuenta aún no está verificada.</strong></p>");
                echo ("<p>Abre el enlace que te enviamos por correo al registrarte (mira también " .
                    "en la carpeta de correo no deseado). Si no te ha llegado o ha caducado, " .
                    "puedes pedir otro en <a href='register'>Regístrate</a>.</p>");
                return;
            }
            if (hasParticipated ($db, $participantid, $surveyid)){
                echo ("<p><strong>Ya has votado en esta consulta.</strong></p>");
                return;
            }
            if (checkUnsubscribe ($db, $participantid)){
                echo "<p><strong>No puedes participar en la consulta porque has confirmado
                    la solicitud de baja del registro de participantes.</strong></p>";
                echo "<p>Si quieres volver a participar puedes cancelar la baja en
                    <a href='register'>Regístrate</a>.</p>";
                return;
            }
            $formid = $this->saveParticipation ($surveyid, $participantid);
            $this->showSurvey ($db, $surveyid, $formid);
        }
        catch (Exception $e){
            echo ("<p><strong>Error recuperando los datos para la participación</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} when getting data for response.");
            return;
        }
    }

    /* Pruebas de carga: muestra la consulta si el enlace es de la tabla StressTest. */
    private function showTest ($pid, $key){
        try {
            $db = dbConn ();
            if (!is_string ($key) || !$this->getTestSurvey ($db, $pid, url_base64_decode ($key))){
                echo ("<p><em>La solicitud proporcionada no existe o ha caducado.</em></p>");
                return;
            }
            $formid = $this->saveParticipation ($this->surveyid, self::TESTID);
            $this->showSurvey ($db, $this->surveyid, $formid);
        }
        catch (Exception $e){
            echo ("<p><strong>Error recuperando los datos para la participación</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} when getting data for a stress test.");
        }
    }

    /**
     * DNI of the person who votes: the one of the client certificate. Without
     * client_cert_enabled (only for development) it is asked in a form, so
     * anybody can vote as anybody.
     *
     * @return string|null The DNI, or null (with the reason or the form already shown).
     */
    private function getVoterDni (int $surveyid): ?string {
        if (clientCertEnabled ()){
            try {
                return getClientCertDni ();
            }
            catch (ClientCertException $e){
                echo ("<p><strong>Para votar tienes que identificarte con un certificado " .
                    "electrónico válido.</strong></p>");
                echo ("<p>" . h ($e->getMessage ()) . "</p>");
                return null;
            }
        }
        $dni = isset ($_GET['dni']) && is_string ($_GET['dni']) ? strtoupper (trim ($_GET['dni'])) : "";
        if ($dni !== "" && pdfValidDni ($dni))
            return $dni;
        if ($dni !== "")
            echo ("<p><strong>El DNI no es válido.</strong></p>");
        ?>
        <section class="ml-participate-card">
            <h3>Participar en la consulta</h3>
            <p><strong>Modo de desarrollo:</strong> el certificado electrónico está desactivado
                (<code>client_cert_enabled</code>), así que se pide el DNI. No lo uses en producción.</p>
            <form method="GET" action="participate">
                <input type="hidden" name="<?= self::SID; ?>" value="<?= $surveyid; ?>">
                <label for="dni">DNI/NIE</label>
                <input type="text" name="dni" id="dni" required>
                <button type="submit" class="button-3">Continuar</button>
            </form>
        </section>
        <?php
        return null;
    }

    /* Id de la participante registrada con ese DNI, o null si no hay. */
    private function participantByDni ($db, #[\SensitiveParameter] string $dni): ?int {
        $hashdni = participantHash ($dni);
        $query = $db->prepare ("SELECT participantid FROM {Participants} WHERE dnihashed = :dni LIMIT 1");
        $query->bindParam (":dni", $hashdni, PDO::PARAM_STR);
        $query->execute ();
        $id = $query->fetchColumn ();
        $query->closeCursor ();
        return $id === false ? null : (int) $id;
    }

    private function securityError (){
        ?>
        <p><strong>No se ha podido comprobar tu identidad. Vuelve a entrar en la consulta e identifícate con tu certificado electrónico.</strong></p>
        <?php
    }


    /* Guarda lo que necesita el envío de un formulario y devuelve su id.
       Va en el propio formulario, no suelto en la sesión: con dos enlaces
       abiertos a la vez, las respuestas de uno acababan en la consulta
       del otro. */
    private function saveParticipation ($surveyid, $participantid): string {
        if (!isset ($_SESSION[self::PARTICIPATIONS]) || !is_array ($_SESSION[self::PARTICIPATIONS]))
            $_SESSION[self::PARTICIPATIONS] = array ();
        $formid = bin2hex (random_bytes (16));
        $_SESSION[self::PARTICIPATIONS][$formid] = [
            'surveyid' => $surveyid,
            'participantid' => $participantid,
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
     * The response is stored with no reference to the participant: the Voted
     * table only records that the participant has voted in the survey.
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
        /* Quien envía tiene que seguir siendo quien abrió el formulario. */
        if ($participantid != self::TESTID && clientCertEnabled ()){
            try {
                $sameperson = $this->participantByDni (dbConn (), getClientCertDni ()) === (int) $participantid;
            }
            catch (ClientCertException $e){
                $sameperson = false;
            }
            if (!$sameperson){
                $this->securityError ();
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
                        echo ("<p><strong>Ya has votado en esta consulta.</strong></p>");
                        return;
                    }
                    $voted = $db->prepare ("INSERT INTO {Voted} (surveyid, participantid) " .
                        "values (:sid, :pid)");
                    $voted->bindParam (":sid", $surveyid, PDO::PARAM_INT);
                    $voted->bindParam (":pid", $participantid, PDO::PARAM_INT);
                    $voted->execute ();
                }
                /* La respuesta no lleva nada de quien la envía. */
                $query = $db->prepare ("INSERT INTO {Responses} (surveyid, response) " .
                    "values (:sid, :res)");
                $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
                $query->bindParam (":res", $responsejson, PDO::PARAM_STR);
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
