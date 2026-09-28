<?php
require_once 'utils/html.php';
require_once "ifaces/view.php";
require_once "utils/dbutils.php";

class EndedSurveys extends View {

    function getMenuGroup (){
        return ML_MENU_GROUP_ENDED_SURVEYS;
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
            $query = $db->prepare ("SELECT surveyid, surveyname" .
                ", DATE_FORMAT(enddate,'%d/%m/%Y %T') as dend" .
                ", DATE_FORMAT(startdate,'%d/%m/%Y %T') as dstart ".
                "FROM {Surveys} WHERE " .
                "enddate < NOW() " .
                "ORDER BY enddate DESC");
            $query->execute ();
            if ($query->rowCount () == 0){
                echo ("<p><strong>No hay consultas finalizadas para consultar.</strong></p>");
            }
            else {
                $this->addEndedSurveys ($query);
            }
            $query->closeCursor ();
        }
        catch (Exception $e){
            echo ("<strong>Error recuperando consultas.</strong>");
            logMessage (LOGGER_ERROR, "Error {$e} loading ended surveys.");
        }
    }

    private function addEndedSurveys ($surveys){
        ?>
        <script type="text/javascript">
        function queryResults ($id){
            const query = document.getElementById ("queryid");
            query.value = $id;
            return true;
        }
        </script>
        <form id="resultsesurvey" name="resultsesurvey" method="GET" action="results">
        <input type="hidden" name="queryid" id="queryid">
        <div class="card-table-container">
            <table class="card-like-table ml-stack" id="surveystable">
                <thead><tr>
                    <td>Consulta</td><td>Fecha inicio</td><td>Fecha fin</td><td>Seleccionar</td>
                </tr></thead>
                <tbody>
                <?php
                while ($survey = $surveys->fetch ()){
                    $id = $survey['surveyid'];
                    ?>
                    <tr id="<?= "query_" . $id; ?>">
                        <td><span class="username" id="svr-<?= $id; ?>"><?= h ($survey['surveyname']) ?></span></td>
                        <td data-label="Fecha inicio"><?= $survey['dstart'] ?></td>
                        <td data-label="Fecha fin"><?= $survey['dend'] ?></td>
                        <td><input type="submit" class="button-3"
                            onclick="return queryResults (<?= $id; ?>);" value="Ver resultado"></td>
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
