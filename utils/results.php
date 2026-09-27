<?php
require_once 'utils/dbutils.php';

/* Recuento vacío de una consulta. Las opciones se preparan a cero antes de
   contar para que las que nadie eligió también aparezcan en los resultados. */
function emptyResults ($db, $surveyid){
    $results = array ();
    $results["Total"] = 0;
    $results["Responses"] = array ();
    $questions = $db->prepare ("SELECT questionid FROM {Questions}
        WHERE surveyid = :sid");
    $questions->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $questions->execute ();
    while ($question = $questions->fetch ()){
        $questionid = $question["questionid"];
        $results["Responses"][$questionid] = array ();
        $options = $db->prepare ("SELECT optionid FROM {Options} WHERE
            surveyid = :sid AND questionid = :qid");
        $options->bindParam (":sid", $surveyid, PDO::PARAM_INT);
        $options->bindParam (":qid", $questionid, PDO::PARAM_INT);
        $options->execute ();
        while ($option = $options->fetch ()){
            $results["Responses"][$questionid][$option["optionid"]] = 0;
        }
        $options->closeCursor ();
    }
    $questions->closeCursor ();
    return $results;
}

/* Suma una respuesta, con el formato en que se guarda en Responses, al
   recuento. Las preguntas múltiples guardan un array de opciones marcadas;
   las simples, la opción elegida o -1 si se dejó en blanco. */
function addResponse (array &$results, array $response){
    $results["Total"]++;
    foreach ($response as $questionid => $answer){
        if (is_array ($answer)){
            foreach ($answer as $optionid => $selected){
                if (!empty ($selected) &&
                    isset ($results["Responses"][$questionid][$optionid]))
                    $results["Responses"][$questionid][$optionid]++;
            }
        }
        else if (isset ($results["Responses"][$questionid][$answer]))
            $results["Responses"][$questionid][$answer]++;
    }
}

/* Cuenta todas las Responses guardadas de la consulta. */
function countResponses ($db, $surveyid){
    $results = emptyResults ($db, $surveyid);
    $responses = $db->prepare ("SELECT responseid, response FROM {Responses}
        WHERE surveyid = :sid");
    $responses->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $responses->execute ();
    while ($response = $responses->fetch ()){
        $responsearray = json_decode ($response["response"], true);
        if (!is_array ($responsearray)){
            logMessage (LOGGER_ERROR, "Malformed response {$response["responseid"]} " .
                "in survey {$surveyid}");
            continue;
        }
        addResponse ($results, $responsearray);
    }
    $responses->closeCursor ();
    return $results;
}

/* Devuelve el recuento guardado de la consulta, o null si todavía no hay. */
function loadResults ($db, $surveyid){
    $query = $db->prepare ("SELECT results FROM {Results} WHERE surveyid = :sid");
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->execute ();
    $row = $query->fetch ();
    $query->closeCursor ();
    if ($row === false)
        return null;
    $results = json_decode ($row["results"], true);
    if (!is_array ($results))
        throw new Exception ("Malformed results JSON for survey {$surveyid}");
    return $results;
}

function saveResults ($db, $surveyid, array $results, bool $ispartial){
    $json = json_encode ($results);
    if ($json === false)
        throw new Exception ("Error encoding results: " . json_last_error_msg ());
    $partial = $ispartial ? 1 : 0;

    $query = $db->prepare ("SELECT surveyid FROM {Results} WHERE surveyid = :sid");
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->execute ();
    $exists = $query->rowCount () > 0;
    $query->closeCursor ();
    if ($exists){
        $query = $db->prepare ("UPDATE {Results} SET results = :res, ispartial = :part
            WHERE surveyid = :sid");
    }
    else {
        $query = $db->prepare ("INSERT INTO {Results} (surveyid, results, ispartial)
            values (:sid, :res, :part)");
    }
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->bindParam (":res", $json, PDO::PARAM_STR);
    $query->bindParam (":part", $partial, PDO::PARAM_INT);
    $query->execute ();
}
