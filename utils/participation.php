<?php
require_once 'utils/dbutils.php';

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

function hasCode ($db, $participantid, $surveyid){
    $result = false;
    
    $query = $db->prepare ("SELECT 1 FROM {Participation} WHERE participantid = :pid AND surveyid = :sid");
    $query->bindParam (":pid", $participantid, PDO::PARAM_INT);
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->execute ();
    if ($query->rowCount () > 0)
        $result = true;
    $query->closeCursor ();
    return $result;
}

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

/* Serializa las peticiones de una misma dirección: sin esto, dos
   peticiones simultáneas creaban cada una su fila en Participants (y la
   tabla no admite borrados) o se saltaban el límite de una por hora. */
function lockParticipant ($db, $hashmail): bool {
    $name = "mlsurvey:" . substr ($hashmail, 0, 48);
    $query = $db->prepare ("SELECT GET_LOCK(:name, 10)");
    $query->bindParam (":name", $name, PDO::PARAM_STR);
    $query->execute ();
    $locked = $query->fetchColumn () == 1;
    $query->closeCursor ();
    if ($locked){
        /* La conexión es persistente: si la petición muere antes de
           soltarlo, el bloqueo seguiría vivo en ella. */
        register_shutdown_function ('unlockParticipant', $db, $hashmail);
    }
    return $locked;
}

function unlockParticipant ($db, $hashmail){
    $name = "mlsurvey:" . substr ($hashmail, 0, 48);
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
