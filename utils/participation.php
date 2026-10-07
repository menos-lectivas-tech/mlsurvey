<?php
/**
 * Functions for check and manage participation.
 */
require_once 'utils/dbutils.php';

/**
 * Check if a participant has participated in a survey.
 * 
 * @param PDO $db PDO database object for the query.
 * @param int $participantid
 * @param int $surveyid
 * 
 * @return bool
 */
function hasParticipated ($db, $participantid, $surveyid){
    $result = false;
    
    $query = $db->prepare ("SELECT 1 FROM {Responses} WHERE participantid = :pid AND surveyid = :sid");
    $query->bindParam (":pid", $participantid, PDO::PARAM_INT);
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->execute ();
    if ($query->rowCount () > 0)
        $result = true;
    $query->closeCursor ();
    return $result;
}

/**
 * Checks if a participant has a valid code.
 * Does not work as Participation table has changed and does not have a participantid column
 * By now it's not posible to know if an email addres has a code, as all stored info is
 * encrypted.
 * 
 * @param PDO $db PDO database.
 * @param int $participantid
 * @param int $surveyid
 * 
 * @return bool
 */
function hasCode ($db, $participantid, $surveyid){
    $result = false;   
    $query = $db->prepare ("SELECT participationid, participationdate 
        FROM {Participation} WHERE participantid = :pid AND surveyid = :sid LIMIT 1");
    $query->bindParam (":pid", $participantid, PDO::PARAM_INT);
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->execute ();
    if ($query->rowCount () > 0){
        $row = $query->fetch ();
        $pid = $row["participationid"];
        $date = new DateTime($row["participationdate"]);
        $now = new DateTime ();
        if ($date->add (DateInterval::createFromDateString('1 hour')) > $now){
            $result = true;
        }
        $query->closeCursor ();
        if (!$result) {
            $delete = $db->prepare ("DELETE FROM {Participation} WHERE participationid = :pid");
            $delete->bindParam (":pid", $pid, PDO::PARAM_INT);
            $delete->execute ();
        }
    }
    return $result;
}

/**
 * Generates a ECDSA keypair.
 * 
 * @return pkey
 */
function generateKeyPair (){
    $curve_name = 'secp256k1';

// Create a new EC key resource
    return openssl_pkey_new([
        'curve_name' => $curve_name,
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
}


function getSignAlgo (){
    return OPENSSL_ALGO_SHA256;
}

/* Serializa las peticiones de una misma persona: sin esto, dos
   peticiones simultáneas creaban cada una su fila en Participants (y la
   tabla no admite borrados) o se saltaban el límite de una por hora. */
function lockParticipant ($db, $hash): bool {
    $name = "mlsurvey:" . substr ($hash, 0, 48);
    $query = $db->prepare ("SELECT GET_LOCK(:name, 10)");
    $query->bindParam (":name", $name, PDO::PARAM_STR);
    $query->execute ();
    $locked = $query->fetchColumn () == 1;
    $query->closeCursor ();
    if ($locked){
        /* La conexión es persistente: si la petición muere antes de
           soltarlo, el bloqueo seguiría vivo en ella. */
        register_shutdown_function ('unlockParticipant', $db, $hash);
    }
    return $locked;
}

function unlockParticipant ($db, $hash){
    $name = "mlsurvey:" . substr ($hash, 0, 48);
    try {
        /* Si ya estaba suelto no pasa nada: RELEASE_LOCK devuelve 0. */
        $query = $db->prepare ("SELECT RELEASE_LOCK(:name)");
        $query->bindParam (":name", $name, PDO::PARAM_STR);
        $query->execute ();
        $query->closeCursor ();
    }
    catch (Exception $e){
        logMessage (LOGGER_ERROR, "Error releasing participant lock: {$e->getMessage ()}");
    }
}
