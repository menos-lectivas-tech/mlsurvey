<?php
require_once "utils/dbutils.php";
require_once "utils/logger.php";

function versionTableExists ():bool {
  $db = dbConn ();
  $currmode = $db->getAttribute (PDO::ATTR_ERRMODE);
  $db->setAttribute (PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
  $res = $db->query ("SELECT 1 FROM {Version} LIMIT 1");
  if ($res === false){
    if ($db->errorInfo ()[0] == "42S02"){
      $db->setAttribute (PDO::ATTR_ERRMODE, $currmode);
      return false;
    }
    $e = new Exception ($db->errorInfo ()[0] . ":" . $db->errorInfo ()[2]);
    $db->setAttribute (PDO::ATTR_ERRMODE, $currmode);
    throw $e;
  }
  $res->closeCursor ();
  $db->setAttribute (PDO::ATTR_ERRMODE, $currmode);
  return true;
}

function createVersionTable (){
  $db = dbConn ();
  $db->exec ("CREATE TABLE {Version} (versionid INT UNSIGNED NOT NULL PRIMARY KEY, versioncode INT UNSIGNED NOT NULL)");
  $db->exec ("INSERT INTO {Version} values (1, 1)");
}

$maxversion = 6;

try {
  if (!versionTableExists ()){
    logMessage (LOGGER_INFO, "Version table does not exist. Creating");
    createVersionTable ();
    echo ("Version table does not exist. Creating\n");
  }
  
  $db = dbConn ();
  $version = getDBVersion ();
  if ($version < 2){
    $db->exec ("ALTER TABLE {Participation} ADD COLUMN (requesttag CHAR(64) NULL)");
    $db->exec ("CREATE INDEX Participation_request_IDX USING BTREE ON {Participation}
      (surveyid, requesttag, participationdate)");
  }
  if ($version < 3){
    $db->exec ("ALTER TABLE {Participants} ADD COLUMN (dnihashed CHAR(64) NULL)");
    $db->exec ("CREATE UNIQUE INDEX Participants_dnihashed_IDX USING BTREE ON {Participants} (dnihashed)");
  }
  if ($version < 4){
    $db->exec("ALTER TABLE {SystemConfig} ADD COLUMN (modifiedby INT UNSIGNED NULL)");
    $db->exec ("ALTER TABLE {SystemConfig} ADD SYSTEM VERSIONING PARTITION BY SYSTEM_TIME");
  }
  if ($version < 5){
    /* Separa el voto de quien lo emite: Voted guarda quién ha votado en cada
       consulta y Responses deja de apuntar a la participante. La firma y las
       claves también se van: firmar con la clave de cada participante la
       identifica igual que su id. */
    $db->exec ("CREATE TABLE IF NOT EXISTS {Voted} (
      surveyid INT UNSIGNED NOT NULL,
      participantid INT UNSIGNED NOT NULL,
      CONSTRAINT {Voted}_PK PRIMARY KEY (surveyid,participantid),
      CONSTRAINT {Voted}_Participants_FK FOREIGN KEY (participantid)
        REFERENCES {Participants}(participantid) ON DELETE RESTRICT ON UPDATE RESTRICT)");
    /* Las sentencias DDL no se pueden deshacer: si la actualización se quedó
       a medias, al repetirla la columna puede no estar ya. */
    $linked = $db->query ("SHOW COLUMNS FROM {Responses} LIKE 'participantid'");
    $haslink = $linked->rowCount () > 0;
    $linked->closeCursor ();
    if ($haslink){
      /* La participante 0 es la de las pruebas de carga, que vota sin límite. */
      $db->exec ("INSERT IGNORE INTO {Voted} (surveyid, participantid)
        SELECT DISTINCT r.surveyid, r.participantid FROM {Responses} r
        JOIN {Participants} p ON p.participantid = r.participantid
        WHERE r.participantid <> 0");
    }
    $immutable = "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = " .
      "'Error: This table is immutable. Modifications are not allowed.'";
    $db->exec ("CREATE TRIGGER IF NOT EXISTS {Voted}_no_update BEFORE UPDATE ON {Voted} {$immutable}");
    $db->exec ("CREATE TRIGGER IF NOT EXISTS {Voted}_no_delete BEFORE DELETE ON {Voted} {$immutable}");
    $db->exec ("ALTER TABLE {Responses} DROP COLUMN IF EXISTS participantid, DROP COLUMN IF EXISTS responsesign");
    $db->exec ("ALTER TABLE {Participants} DROP COLUMN IF EXISTS privatekey, DROP COLUMN IF EXISTS publickey");
  }
  if ($version < 6){
    $db->exec("DROP TRIGGER Participants_no_delete");
  }
  if ($version < $maxversion){
    $query = $db->prepare ("UPDATE {Version} SET versioncode = :ver WHERE versionid = 1");
    $query->bindParam (":ver", $maxversion, PDO::PARAM_INT);
    $query->execute ();
  }
}
catch (Exception $e){
  echo ("Error updating datablase {$e}");
  logMessage (LOGGER_ERROR, "{$e} when updating database");
  return 1;
}
echo ("Database updated succesfully\n");
logMessage (LOGGER_INFO, "Database updated succesfully");
return 0;
