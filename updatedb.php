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

$maxversion = 4;

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
