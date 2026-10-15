<?php
require_once "utils/dbutils.php";
require_once "utils/logger.php";
require_once "utils/crypt.php";
require_once 'vendor/autoload.php';

use Zoon\BounceHandler\BounceHandler;

function deleteParticipation($address)
{
    $db = dbConn();
    $hashemail = participantHash($address);
    $query = $db->prepare("SELECT participantid FROM {Participants} WHERE participant = :p
            LIMIT 1");
    $query->bindParam(":p", $hashemail, PDO::PARAM_STR);
    $query->execute();
    if ($query->rowCount() < 1)
        return;

    $id = $query->fetch()["participantid"];
    $query->closeCursor();
    logMessage(LOGGER_DEBUG, "Deleting participant {$id} as address does not exists");
    /*$query = $db->prepare("DELETE FROM {Participation} WHERE participant = :p");
    $query->bindParam(":p", $hashemail, PDO::PARAM_STR);
    $query->execute();*/

    $query = $db->prepare("DELETE FROM {Participants} WHERE participantid = :pid");
    $query->bindParam(":pid", $id, PDO::PARAM_INT);
    $query->execute();
}
$data = file_get_contents("php://stdin");
$handler = new BounceHandler();
$results = $handler->parse($data);
logMessage(LOGGER_DEBUG, "Reciving a bounce email");
foreach ($results as $result) {
    if (
        $result->emailType->value == "bounce"
        && $result->action->value == "failed"
    ) {
        $recipient = $result->recipient;
        deleteParticipation($recipient);
    }
}
