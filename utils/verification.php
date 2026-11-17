<?php
/**
 * Verification of the participants' accounts: when registering, a link with a
 * random code is sent to the email address, and the account can't vote until
 * the link is opened. The email address is only used for this.
 * Only the SHA-256 of the code is stored, in Verifications, with no reference
 * to the address: the link carries only the code.
 */
require_once 'utils/dbutils.php';
require_once 'utils/logger.php';

/* Minutos que vale el enlace de verificación. */
const VERIFY_MINUTES = 30;
/* Minutos que tienen que pasar entre un envío del enlace y el siguiente. */
const VERIFY_WAIT_MINUTES = 5;

/**
 * true if the account of the participant has been verified.
 */
function isVerified(PDO $db, int $participantid): bool
{
    $query = $db->prepare("SELECT 1 FROM {Verifications} WHERE participantid = :pid AND verified = 1");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->execute();
    $verified = $query->rowCount() > 0;
    $query->closeCursor();
    return $verified;
}

/**
 * true if the account is not verified and its last link has expired: the row
 * can be deleted so the DNI and the address can be registered again.
 */
function isExpiredUnverified(PDO $db, int $participantid): bool
{
    $query = $db->prepare("SELECT 1 FROM {Verifications} WHERE participantid = :pid " .
        "AND verified = 0 AND requestdate < DATE_SUB(NOW(), INTERVAL " . VERIFY_MINUTES . " MINUTE)");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->execute();
    $expired = $query->rowCount() > 0;
    $query->closeCursor();
    return $expired;
}

/**
 * true if a verification link was sent to the participant less than
 * VERIFY_WAIT_MINUTES ago.
 */
function verificationRecentlySent(PDO $db, int $participantid): bool
{
    $query = $db->prepare("SELECT 1 FROM {Verifications} WHERE participantid = :pid " .
        "AND requestdate > DATE_SUB(NOW(), INTERVAL " . VERIFY_WAIT_MINUTES . " MINUTE)");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->execute();
    $recent = $query->rowCount() > 0;
    $query->closeCursor();
    return $recent;
}

/**
 * Seconds left until another verification link can be sent to the participant
 * (0 if it can be sent now).
 */
function secondsUntilResend(PDO $db, int $participantid): int
{
    $query = $db->prepare("SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), " .
        "DATE_ADD(requestdate, INTERVAL " . VERIFY_WAIT_MINUTES . " MINUTE))) " .
        "FROM {Verifications} WHERE participantid = :pid");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->execute();
    $seconds = $query->fetchColumn();
    $query->closeCursor();
    return $seconds === false ? 0 : (int) $seconds;
}

/**
 * Generates a new verification code for a participant not verified yet, and
 * stores its hash replacing the previous one, which stops working.
 *
 * @return string|null The code (raw bytes), or null if another one was sent less
 * than VERIFY_WAIT_MINUTES ago or the account is already verified.
 */
function newVerificationCode(PDO $db, int $participantid): ?string
{
    if (isVerified($db, $participantid) || verificationRecentlySent($db, $participantid))
        return null;

    $code = random_bytes(32);
    $key = hash('sha256', $code);
    $query = $db->prepare("INSERT INTO {Verifications} (participantid, verifykey, requestdate, verified) " .
        "VALUES (:pid, :vkey, NOW(), 0) " .
        "ON DUPLICATE KEY UPDATE verifykey = VALUES(verifykey), requestdate = NOW()");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->bindParam(":vkey", $key, PDO::PARAM_STR);
    $query->execute();
    return $code;
}

/**
 * Verifies the account whose link has the code, if it hasn't expired.
 *
 * @return bool true if an account has been verified.
 */
function verifyCode(PDO $db, #[\SensitiveParameter] string $code): bool
{
    $key = hash('sha256', $code);
    $query = $db->prepare("UPDATE {Verifications} SET verified = 1, verifykey = NULL " .
        "WHERE verifykey = :vkey AND verified = 0 " .
        "AND requestdate > DATE_SUB(NOW(), INTERVAL " . VERIFY_MINUTES . " MINUTE)");
    $query->bindParam(":vkey", $key, PDO::PARAM_STR);
    $query->execute();
    return $query->rowCount() > 0;
}

/**
 * Deletes the participants with that DNI or address whose account wasn't verified
 * before the link expired, so they can be registered again (with a typo in the
 * address the link never arrives). Must be called holding the locks of both.
 * An account not verified can't have voted, so nothing else refers to it.
 */
function purgeExpiredUnverified(PDO $db, string $hashdni, string $hashemail): void
{
    $query = $db->prepare("SELECT p.participantid FROM {Participants} p " .
        "JOIN {Verifications} v ON v.participantid = p.participantid " .
        "WHERE (p.participant = :part OR p.dnihashed = :dni) AND v.verified = 0 " .
        "AND v.requestdate < DATE_SUB(NOW(), INTERVAL " . VERIFY_MINUTES . " MINUTE)");
    $query->bindParam(":part", $hashemail, PDO::PARAM_STR);
    $query->bindParam(":dni", $hashdni, PDO::PARAM_STR);
    $query->execute();
    $ids = $query->fetchAll(PDO::FETCH_COLUMN);
    $query->closeCursor();
    foreach ($ids as $id)
        deleteParticipant($db, (int) $id);
}

/**
 * Deletes a participant (its Verifications row goes with it, ON DELETE CASCADE).
 */
function deleteParticipant(PDO $db, int $participantid): void
{
    logMessage(LOGGER_INFO, "Deleting unverified participant {$participantid}.");
    $query = $db->prepare("DELETE FROM {Participants} WHERE participantid = :pid");
    $query->bindParam(":pid", $participantid, PDO::PARAM_INT);
    $query->execute();
}
