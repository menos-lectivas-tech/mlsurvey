<?php
require_once 'utils/html.php';
require_once "ifaces/view.php";
require_once "utils/dbutils.php";

class Surveys extends View {
    public const SURVEY_RESPONSE = "response";
    public const SURVEY_PARTIALS = "partials";
    private const SURVEY_QUERY = "query";
    private const RESPONSE_VALUES = [
        'code' => "Obtener código",
        'response' => "Responder"
    ];

    function getMenuGroup (){
        return ML_MENU_GROUP_SURVEYS;
    }

    public function loadStyles (){
        ?>
        <link href="css/tablecard.css" rel="stylesheet" />
        <link href="css/button3.css" rel="stylesheet" />
        <?php
    }
    public function show (){
        try {
            $db = dbConn ();
            $query = $db->prepare ("SELECT surveyid, surveyname, showpartial,
                 DATE_FORMAT(enddate,'%d/%m/%Y %T') as dend,
                 DATE_FORMAT(startdate,'%d/%m/%Y %T') as dstart
                FROM {Surveys} WHERE 
                startdate < NOW() and enddate > NOW() 
                ORDER BY startdate DESC");
            $query->execute ();
            if ($query->rowCount () == 0){
                echo ("<strong>No hay consultas activas para realizar.</strong>");
            }
            else {
                $this->addActiveSurveys ($query);
            }
            $query->closeCursor ();
        }
        catch (Exception $e){
            echo ("<strong>Error recuperando consultas.</strong>");
            logMessage (LOGGER_ERROR, "Error {$e} loading surveys.");
        }
    }

    private function addActiveSurveys ($surveys){
        ?>
        <form id="responsesurvey" name="responsesurvey" method="POST" action="get_code">
        <script type="text/javascript">
        function getCode (surveyid){
            const survey = document.getElementById ("responseid");
            survey.value = surveyid;
            return true;
        }
        function showPartial (surveyid){
            const survey = document.getElementById ("queryid");
            survey.value = surveyid;
            const theform = document.getElementById ("responsesurvey");
            theform.action = "results";
            return true;
        }
        </script>
        <input type="hidden" id="responseid" name="responseid">
        <input type="hidden" name="queryid" id="queryid">
        <div class="card-table-container">
            <table class="card-like-table ml-stack" id="surveystable">
                <thead><tr>
                    <td>Consulta</td><td>Fecha inicio</td><td>Fecha fin</td><td>Seleccionar</td>
                <td>Parciales</td></tr></thead>
                <tbody>
                <?php
                while ($survey = $surveys->fetch ()){
                    $id = $survey['surveyid'];
                    ?>
                    <tr id="<?= "response_" . $id; ?>">
                        <td><span class="username" id="svr-<?= $id; ?>"><?= h ($survey['surveyname']) ?></span></td>
                        <td data-label="Fecha inicio"><?= $survey['dstart'] ?></td>
                        <td data-label="Fecha fin"><?= $survey['dend'] ?></td>
                        <td><input type="submit" class="button-3" onclick="return getCode (<?= $id; ?>);"
                            value="Ver y participar" name="<?= self::SURVEY_RESPONSE; ?>"></td>
                        <td><?php if (!empty ($survey["showpartial"])){ ?>
                            <input type="submit" class="button-3" onclick="return showPartial (<?= $id; ?>);"
                            value="Ver" name="<?= self::SURVEY_PARTIALS; ?>">
                            <?php
                        } ?>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
        </div>
        </form>
        <?php
    }
}
