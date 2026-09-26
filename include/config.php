<?php
require_once "config/config.php";
require_once 'utils/dbutils.php';
class Config {
    public const PARAMS = CONFIG;
    private const MANDATORY = [
        "db_host",
        "db_name",
        "db_user",
        "db_pass",
        "email_method"
    ];
    public static string $timezone = "";
    public static string $mainheader = "";
    public static string $maincontent = "";
    public static string $icon = "";
    public static string $alloweddomains = "";
    public static bool $haveconfig = false;
    public static string $sitename = "";
    public static string $contact = "";
    public static function getSystemConfig (){
        $db =dbConn ();

        $query = $db->query ("SELECT * FROM {SystemConfig} LIMIT 1");
        if ($query->rowCount () == 1){
            $row = $query->fetch();
            self::$timezone = $row["timezone"] == null?"":$row["timezone"];
            if (!empty (self::$timezone))
                date_default_timezone_set (self::$timezone);
            self::$mainheader = $row["mainheader"] == null?"":$row["mainheader"];
            self::$maincontent = $row["maincontent"]== null?"":$row["maincontent"];
            self::$icon = $row["icon"]== null?"":$row["icon"];
            self::$alloweddomains = $row["alloweddomains"] == null ? "":$row["alloweddomains"];
            self::$sitename = $row["sitename"] == null ? "":$row["sitename"];
            self::$contact = $row["contact"] == null ? "":$row["contact"];
            self::$haveconfig = true;
        }
        $query->closeCursor ();
    }
}