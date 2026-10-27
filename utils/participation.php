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
    
    $query = $db->prepare ("SELECT 1 FROM {Voted} WHERE participantid = :pid AND surveyid = :sid");
    $query->bindParam (":pid", $participantid, PDO::PARAM_INT);
    $query->bindParam (":sid", $surveyid, PDO::PARAM_INT);
    $query->execute ();
    if ($query->rowCount () > 0)
        $result = true;
    $query->closeCursor ();
    return $result;
}

/**
 * Checks if a participant has confirmed an unsubscription request.
 * 
 * @param PDO $db The PDO database object.
 * @param int $participantid
 * 
 * @return bool True if confirmed. False otherway
 */
function checkUnsubscribe (PDO $db, int $participantid):bool {
    $query = $db->prepare("SELECT 1 FROM {Unsubscriptions} WHERE
        participantid = :pid AND confirmed > 0");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->execute();
    $ret = ($query->rowCount() > 0);
    $query->closeCursor();
    return $ret;
}

/**
 * Looks for a participant by the hash of its email address.
 *
 * @param PDO $db PDO database.
 * @param string $hashemail participantHash of the email address.
 *
 * @return int|null The participant id, or null if the address isn't registered.
 */
function getParticipantId (PDO $db, string $hashemail): ?int {
    $query = $db->prepare ("SELECT participantid FROM {Participants}
        WHERE participant = :participant LIMIT 1");
    $query->bindParam (":participant", $hashemail, PDO::PARAM_STR);
    $query->execute ();
    $participantid = $query->fetchColumn ();
    $query->closeCursor ();
    return $participantid === false ? null : (int) $participantid;
}

/**
 * Returns the id of the participant with that email address, registering it in
 * Participants table if it isn't yet. Must be called holding lockParticipant.
 *
 * @param PDO $db PDO database.
 * @param string $hashemail participantHash of the email address.
 *
 * @return int The participant id.
 */
function registerParticipant (PDO $db, string $hashemail): int {
    $participantid = getParticipantId ($db, $hashemail);
    if ($participantid !== null)
        return $participantid;
    $query = $db->prepare ("INSERT into {Participants} (participant) values (:participant)");
    $query->bindParam (":participant", $hashemail, PDO::PARAM_STR);
    $query->execute ();
    return (int) $db->lastInsertId ();
}

/* Serializa las peticiones de una misma dirección: sin esto, dos
   peticiones simultáneas creaban cada una su fila en Participants o se
   saltaban el límite de una por hora. */
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
