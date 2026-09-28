<?php
require_once 'include/mlpdo.php';
require_once 'include/config.php';
require_once 'utils/logger.php';

/**
 * Devuelve la conexion a la base de datos.
 *
 * Pool de conexiones: PHP no comparte memoria entre peticiones, asi que el pool
 * lo forman los workers de Apache (mpm_prefork). Cada worker mantiene abierta
 * una conexion persistente (PDO::ATTR_PERSISTENT) que reutilizan todas las
 * peticiones que atiende, y el tamaño del pool es MaxRequestWorkers (ver
 * docker/apache-mlsurvey.conf). Asi un pico de peticiones no paga el coste de
 * abrir y autenticar una conexion nueva por cada llamada.
 *
 * Dentro de una misma peticion se devuelve siempre el mismo objeto, de modo que
 * varias llamadas a dbConn() no abren conexiones extra.
 */
function dbConn (){
    static $dbconn = null;
    static $appliedtz = null;
    if ($dbconn === null){
        $port = 3306;
        if (isset (Config::PARAMS["db_port"]))
            $port = Config::PARAMS["db_port"];

        $dbconn = new MLPDO ('mysql:dbname=' . Config::PARAMS["db_name"] .
            ';host=' . Config::PARAMS["db_host"] . ';port=' . $port,
            Config::PARAMS["db_user"], Config::PARAMS["db_pass"], array(
                MLPDO::ATTR_ERRMODE => MLPDO::ERRMODE_EXCEPTION,
                MLPDO::ATTR_PERSISTENT => true,
                // Si la base de datos no responde, fallar pronto en vez de acumular workers bloqueados.
                MLPDO::ATTR_TIMEOUT => 5,
            ));

        // Una conexion persistente puede venir de una peticion que murio a mitad de transaccion.
        if ($dbconn->inTransaction ()){
            $dbconn->rollBack ();
        }
        if (isset (Config::PARAMS["db_prefix"]))
            $dbconn->setPrefix (Config::PARAMS["db_prefix"]);
        else
            $dbconn->setPrefix ("");
    }

    // La zona horaria sale de la base de datos (Config::getSystemConfig), que a su
    // vez necesita esta conexion: todavia no se conoce cuando la conexion se crea.
    // Por eso se aplica aqui, fuera del bloque anterior, en cuanto cambia. Y como
    // la conexion es persistente, puede llegar con la zona de una peticion previa,
    // asi que tambien hay que devolverla a SYSTEM cuando no hay ninguna configurada.
    if ($appliedtz !== Config::$timezone){
        $appliedtz = Config::$timezone;
        try {
            if (!empty ($appliedtz))
                // Las propiedades estaticas no se interpolan en una cadena: hay que
                // concatenar. Ademas el valor viene de la base de datos, asi que se escapa.
                $dbconn->exec ("SET time_zone = " . $dbconn->quote ($appliedtz));
            else
                $dbconn->exec ("SET time_zone = SYSTEM");
        }
        catch (PDOException $e){
            // Una zona que MariaDB no conoce no puede dejar todo el sitio sin
            // base de datos (ni siquiera se podria entrar a corregirla).
            logMessage (LOGGER_ERROR, "Unknown time zone {$appliedtz} in the database, using SYSTEM: {$e->getMessage ()}");
            $dbconn->exec ("SET time_zone = SYSTEM");
        }
    }
    return $dbconn;
}

function getDBVersion (){
    $db = dbConn ();
    $q = $db->query ("SELECT versioncode FROM {Version}
        WHERE versionid =  1");
    $version = $q->fetch ()["versioncode"];
    $q->closeCursor ();
    return $version;
}