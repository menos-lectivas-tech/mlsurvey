<?php
/**
 * This class shows the results for a survey
 */
require_once 'utils/dbutils.php';
require_once 'include/fileparams.php';
class Results extends View {

    /* Ranuras de la paleta categórica definida en css/results.css.
       El orden importa: está validado para que dos opciones contiguas
       sigan distinguiéndose con daltonismo. */
    private const RESULTS_COLORS = [
        "s1",
        "s2",
        "s3",
        "s4",
        "s5",
        "s6",
        "s7",
        "s8",
    ];

    function getMenuGroup (){
        return ML_MENU_GROUP_ENDED_SURVEYS;
    }

    public function loadStyles (){
        ?>
        <link href="css/results.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <?php
    }

    public function show (){
        if (!isset ($_REQUEST["queryid"])){
            showMain ();
            return;
        }
        $surveyid = $_REQUEST["queryid"];
        try {
            $db = dbConn ();
            $surveys = $db->prepare ("SELECT surveyname, surveydesc, surveyfile
                FROM {Surveys} WHERE surveyid = :sid");
            $surveys->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $surveys->execute ();
            if ($surveys->rowCount () == 0){
                echo ("<p><strong>No se encuentra la consulta seleccionada.</strong></p>");
                logMessage (LOGGER_ERROR, "Can't find surveyid {$surveyid}");
                return;
            }
            $survey = $surveys->fetch ();
            $surveyname = $survey['surveyname'];
            $surveydesc = $survey["surveydesc"];
            $surveyfile = $survey["surveyfile"];
            $surveys->closeCursor ();

            $results = $db->prepare ("SELECT results, ispartial 
                FROM {Results} WHERE surveyid = :sid");
            $results->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $results->execute ();
            if ($results->rowCount () == 0){
                echo ("<p><strong>Aún no hay resultados para  la consulta {$surveyname}.</strong></p>");
                return;
            }
            $row = $results->fetch ();
            $result = $row['results'];
            $partial = empty ($row["ispartial"]) ? " " : " parciales ";
            $results->closeCursor ();
            $resultsarray = json_decode ($result, true);
            if ($resultsarray == null){
                echo ("<p><strong>Error leyendo los resultados para la consulta {$surveyname}</strong></p>");
                logMessage (LOGGER_ERROR, "Malformed results JSON for survey {$surveyid}");
                return;
            }
            $thefile = FileParams::FILE_DIR . $surveyid . "/" . $surveyfile;
            ?>
            <h2>Mostrando resultados<?= $partial; ?>para la consulta <?= $surveyname;?>.</h2>
            <?= empty ($surveydesc)?"": "<div>{$surveydesc}</div>";?>
            <?= empty ($surveyfile)?"":"Documentación adjunta: <a href='{$surveyfile}'";?>
            <p><em>En esta consulta han participado <?= $resultsarray["Total"]; ?> personas.</em></p>
            <?php
            $questions = $db->prepare ("SELECT * FROM {Questions} WHERE surveyid = :sid");
            $questions->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $questions->execute ();
            while ($question = $questions->fetch ()) {
                $questionid = $question['questionid'];
                $mulid = "m-" . $questionid;
                $optid = "o-" . $questionid;
                ?>
                <div class="question">
                    <h3>Pregunta <?= $questionid; ?></h3>
                    <p><strong><em><?= $question["questiondesc"]; ?></em></strong></p>
                    <p>
                        <label for="<?= $mulid; ?>">Multiple</label>
                        <input disabled type="checkbox" value="Multiple" id="<?= $mulid; ?>"
                        <?= $question['multiple'] != 0 ? "checked":""; ?>>
                        <label for="<?= $optid; ?>">Opcional</label>
                        <input disabled type="checkbox" value="Opcional" id="<?= $optid ?>"
                        <?= $question['optional'] != 0 ? "checked":""; ?>>
                    </p>
                    <div class="option">
                    <?php
                    $totalquestion = 0;
                    foreach ($resultsarray["Responses"][$questionid] as $value) {
                        $totalquestion += $value;
                    }
                    $options = $db->prepare ("SELECT * FROM {Options} WHERE " . 
                        "surveyid = :sid AND questionid = :qid");
                    $options->bindParam (":sid", $surveyid, PDO::PARAM_INT);
                    $options->bindParam (":qid", $questionid, PDO::PARAM_INT);
                    $options->execute ();
                    while ($option = $options->fetch ()){
                        $optionid = $option['optionid'];
                        $optionres = $resultsarray["Responses"][$questionid][$optionid];
                        $pctres = $optionres/$totalquestion;
                        $elid = "opt-" . $questionid . "-" . $optionid;
                        /* Las opciones empiezan en 1: se desplaza para que la
                           primera reciba la ranura inicial de la paleta. */
                        $slots = count (self::RESULTS_COLORS);
                        $elclass = self::RESULTS_COLORS[($optionid + $slots - 1) % $slots];
                        ?>
                        <p>
                        <label for="<?= $elid; ?>"><strong><?= $option['optiondesc'] ?></strong>: <?= 
                         $optionres?> votos</label>
                         <progress class="<?= $elclass ?>" id="<?= $elid; ?>" 
                         value="<?= $pctres; ?>" max="1"> <?= $pctres; ?>% </progress>
                        </p>
                    <?php
                    }
                    $options->closeCursor ();
                    ?>
                    </div>
                </div>
                <?php
            }
            $questions->closeCursor ();
        }
        catch (Exception $e){
            echo ("<p><strong>Error obtiendo los resultados de la consulta.</strong></p>");
            logMessage (LOGGER_ERROR, "{$e} Getting survey results.");
            return;
        }
    }
}
