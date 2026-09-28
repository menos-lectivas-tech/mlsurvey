<?php
require_once 'utils/html.php';
require_once "ifaces/view.php";
require_once "utils/user.php";
require_once "utils/dbutils.php";
require_once 'utils/logger.php';
require_once "utils/fileutils.php";
require_once "utils/host.php";
require_once "utils/crypt.php";

class StressTest extends View {

    private $canstress = false;
    private const TEST_GENERATE = "Generar";
    private const TEST_CLEAN = "Limpiar";
    private const TEST_DIR = "files/tmptest";
    private const TEST_FILE = self::TEST_DIR . "/testurls.txt";
    public function doInit (){
        if (isset (Config::PARAMS['ml_stresstest']) && Config::PARAMS['ml_stresstest']
             && isAdmin ())
            $this->canstress = true;
    }
    function getMenuGroup (){
        return ML_MENU_GROUP_ADMIN;
    }
    
    function isMulticol (){
        startSession ();
        if ($this->canstress)
            return true;

        return false;
    }

    function leftMenu (){
        include 'include/userleftcolumn.php';
    }
    public function loadStyles (){
        ?>
        <link href="css/button3.css" rel="stylesheet" />
        <?php
    }

    public function show  (){
        if (!$this->canstress){
            showMain ();
            return;
        }
        
        if (isset ($_REQUEST['testaction'])){
            if (!checkToken ()){
                tokenError ();
                return;
            }
            switch ($_REQUEST['testaction']) {
                case self::TEST_CLEAN:
                    if ($this->cleanTests ())
                        echo ("<p><strong>Datos de stress eliminados.</strong></p>");
                    return;
                    break;
                case self::TEST_GENERATE:
                    $this->genTest ();
                    return;
                    break;
            }
        }
        try {
            $db = dbConn ();
            $surveys = $db->query ("SELECT surveyid, surveyname 
                FROM {Surveys} WHERE
                startdate < NOW() and enddate > NOW()
                ORDER BY surveyname ASC");
        ?>
        <h1>Generar archivo de stress</h1>
        <form id="stresstest" name="stresstest" method="POST" 
            action="stress_test">
            <?= setTokenHTML (); ?>
            <p><label for="surveys">Consulta:</label>
                    <select id="surveys" name="surveyid">
                        <?php
                        while ($survey = $surveys->fetch ()){
                            ?>
                        <option value="<?= $survey["surveyid"] ?>"><?= h ($survey["surveyname"]) ?></option>
                        <?php
                        }
                        ?>
                    </select>
            </p>
            <p><label for="testcount">Número de tests:</label>
                <input type="number" id="testcount" name="testcount">
                <input type="submit" class="button-3" id="testaction" name="testaction"
                    value="<?= self::TEST_GENERATE; ?>" onclick="return validateCount();">
            </p>
            <p><div>Limpiar todas las pruebas de stress de la base de datos.</div>
                <input type="submit" class="button-3" id="testaction" name="testaction"
                    value="<?= self::TEST_CLEAN; ?>">
            </p>
        </form>
        <?php
        $surveys->closeCursor ();
        }
        catch (Exception $e){
            echo ("<p><strong>Error {$e} recuperando consultas.</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} recovering surveys for test.");
        }
    }

    public function addJavascript (){
        ?>
        <script>
            function validateCount (){
                const count = document.getElementById ("testcount");
                if (count.value == ""){
                    mlDialog.alert ("Debes introducir un número.");
                    count.focus ({preventScroll: false, focusVisible: true});
                    return false;
                }
                return true;
            }
        </script>
        <?php
    }

    private function cleanTests (){
        try {
            if (file_exists (self::TEST_FILE))
                unlink (self::TEST_FILE);
            $db = dbConn ();
            $db->exec ("DELETE FROM {StressTest}");
            //delDir (self::TEST_DIR);
            return true;
        }
        catch (Exception $e){
            $msg = "Error {$e} eliminando datos de stress";
            echo ("<p><strong>{$msg}</strong></p>");
            logMessage (LOGGER_ERROR, "{$msg} cleanin tests.");
            return false;
        }
    }

    private function genTest (){
        $this->cleanTests ();
        if (!file_exists (self::TEST_DIR))
            mkdir (self::TEST_DIR, 0700, true);
        $testcount = (int) $_REQUEST['testcount'];
        $surveyid = $_REQUEST["surveyid"];
        try {
            $file = fopen (self::TEST_FILE, "w");
            $db = dbConn ();
            $query = $db->prepare ("INSERT into {StressTest} (surveyid, participationkey) 
                values (:sid, :key)");
            for ($i = 0; $i < $testcount; $i++){
                $code = random_bytes (32);
                $key = hash ('sha256', $code);
                $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
                $query->bindParam (":key", $key, PDO::PARAM_STR);
                $query->execute ();
                $pid = $db->lastInsertId ();
                $auth = url_base64_encode ($code);
                $theurl = rtrim (getURL (), "/") . "/participate?t=1&pid={$pid}&auth={$auth}" . PHP_EOL;
                fwrite ($file, $theurl);
            }
            fclose ($file);
            $this->insertHTML ();
        }
        catch (Exception $e){
            $msg = "Error {$e} generando datos de stress";
            echo ("<p><strong>{$msg}</strong></p>");
            logMessage (LOGGER_ERROR, "{$msg} generating tests.");
        }
    }

    private function insertHTML2 (){
        ?>
        <script>
            function downloadFile(){
                window.open ("<?= self::TEST_FILE; ?>", "_self");
            }
        </script>
        <h2>Datos de test generados.</h2>
        <p><input type="button" class="button-3" onclick="downloadFile ();" value="Descargar"></p>
        <?php
    }
    private function insertHTML (){
        ?>
        <h2>Datos de test generados.</h2>
        <a href="<?= self::TEST_FILE ?>" download="testdata.txt" class="button-3">Descargar</a>
        <?php
    }
}