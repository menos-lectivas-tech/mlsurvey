<?php
/**
 * Intermediate server of the Cliente @firma ("servidor intermedio"): the
 * StorageService and RetrieveService of afirma-signature-storage and
 * afirma-signature-retriever, written in PHP (views/afirma_storage.php and
 * views/afirma_retrieve.php).
 *
 * In desktop browsers autoscript.js talks to AutoFirma through a local
 * WebSocket and doesn't need them. In Android and iOS (and very old browsers)
 * the page and the signing app exchange the request and the signature through
 * them: one side stores a message (put) under a random id and the other gets
 * it (get), which deletes it. The messages are encrypted by autoscript.js and
 * the app with a key that never reaches the server.
 */
require_once 'utils/logger.php';

const AFIRMA_PROXY_DIR = "mlsurvey-afirma";
/* Lo que tarda la otra parte en recoger el mensaje: arrancar la app, elegir el certificado... */
const AFIRMA_PROXY_EXPIRES = 300;
/* La declaración firmada, cifrada y en base64, ocupa menos de 1 MB. */
const AFIRMA_PROXY_MAX_SIZE = 8 * 1024 * 1024;

const AFIRMA_ERR_MISSING_OPERATION = "ERR-00:=No se ha indicado código de operación";
const AFIRMA_ERR_UNSUPPORTED_OPERATION = "ERR-01:=Código de operación no soportado";
const AFIRMA_ERR_MISSING_ID = "ERR-05:=No se ha proporcionado un identificador para los datos";
const AFIRMA_ERR_INVALID_ID = "ERR-06:=El identificador para los datos es inválido";
const AFIRMA_ERR_INVALID_DATA = "ERR-07:=Los datos solicitados o enviados son inválidos";
const AFIRMA_ERR_MISSING_VERSION = "ERR-20:=No se ha indicado la versión de la sintaxis de la operación";

/**
 * Sends the answer as plain text, discarding the page that index.php has
 * started, and ends the request.
 */
function afirmaProxyAnswer (string $text): never {
    while (ob_get_level () > 0)
        ob_end_clean ();
    header ("Content-Type: text/plain; charset=utf-8");
    header ("Cache-Control: no-store");
    header ("X-Content-Type-Options: nosniff");
    echo $text;
    exit;
}

/**
 * Parameters of the request: the query string for GET and, as the Java
 * servlet does, the raw body for POST (the app doesn't always send the
 * Content-Type of a form).
 */
function afirmaProxyParams (): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        return array_filter ($_GET, 'is_string');
    $body = file_get_contents ('php://input', false, null, 0, AFIRMA_PROXY_MAX_SIZE + 1);
    if ($body === false || strlen ($body) > AFIRMA_PROXY_MAX_SIZE)
        afirmaProxyAnswer (AFIRMA_ERR_INVALID_DATA);
    $params = [];
    foreach (explode ("&", $body) as $param){
        $pos = strpos ($param, "=");
        if ($pos !== false)
            $params[substr ($param, 0, $pos)] = substr ($param, $pos + 1);
    }
    /* Los de la URL, si los hay, valen igual (salvo los datos, que van en el cuerpo). */
    return $params + array_filter ($_GET, 'is_string');
}

/** File of a message, or null if the id isn't valid. */
function afirmaProxyFile (?string $id): ?string {
    if ($id === null || !preg_match ('/^[A-Za-z0-9_-]{1,64}$/', $id))
        return null;
    return sys_get_temp_dir () . "/" . AFIRMA_PROXY_DIR . "/" . $id;
}

/** Deletes the messages nobody has got in time. */
function afirmaProxyCleanup (): void {
    foreach (glob (sys_get_temp_dir () . "/" . AFIRMA_PROXY_DIR . "/*") ?: [] as $file){
        $mtime = @filemtime ($file);
        if ($mtime !== false && time () - $mtime > AFIRMA_PROXY_EXPIRES)
            @unlink ($file);
    }
}

/** StorageService: op=check (GET) or op=put with v, id and dat. */
function afirmaProxyStore (): never {
    $params = afirmaProxyParams ();
    $op = $params['op'] ?? null;
    if ($op === "check" && $_SERVER['REQUEST_METHOD'] !== 'POST')
        afirmaProxyAnswer ("OK");
    if ($op === null)
        afirmaProxyAnswer (AFIRMA_ERR_MISSING_OPERATION);
    if (!isset ($params['v']))
        afirmaProxyAnswer (AFIRMA_ERR_MISSING_VERSION);
    if (strcasecmp ($op, "put") != 0)
        afirmaProxyAnswer (AFIRMA_ERR_UNSUPPORTED_OPERATION);
    if (!isset ($params['id']))
        afirmaProxyAnswer (AFIRMA_ERR_MISSING_ID);
    $file = afirmaProxyFile ($params['id']);
    if ($file === null)
        afirmaProxyAnswer (AFIRMA_ERR_INVALID_ID);
    afirmaProxyCleanup ();
    /* Sin datos se guarda el error: así le llega a quien los espera. */
    $data = ($params['dat'] ?? "") === "" ? AFIRMA_ERR_INVALID_DATA : urldecode ($params['dat']);
    $dir = dirname ($file);
    if (!is_dir ($dir) && !@mkdir ($dir, 0700) && !is_dir ($dir)){
        logMessage (LOGGER_ERROR, "Can't create the @firma storage directory {$dir}");
        afirmaProxyAnswer ("ERR-10:=Error en la comunicación con la web");
    }
    if (file_put_contents ($file, $data, LOCK_EX) === false){
        logMessage (LOGGER_ERROR, "Can't write the @firma message {$file}");
        afirmaProxyAnswer ("ERR-10:=Error en la comunicación con la web");
    }
    afirmaProxyAnswer ("OK");
}

/** RetrieveService: op=check or op=get with v and id. The message is deleted once got. */
function afirmaProxyRetrieve (): never {
    $params = afirmaProxyParams ();
    $op = $params['op'] ?? null;
    if ($op === null)
        afirmaProxyAnswer (AFIRMA_ERR_MISSING_OPERATION . "\n");
    if ($op === "check")
        afirmaProxyAnswer ("OK\n");
    if (!isset ($params['v']))
        afirmaProxyAnswer (AFIRMA_ERR_MISSING_VERSION . "\n");
    if (strcasecmp ($op, "get") != 0)
        afirmaProxyAnswer (AFIRMA_ERR_UNSUPPORTED_OPERATION . "\n");
    if (!isset ($params['id']))
        afirmaProxyAnswer (AFIRMA_ERR_MISSING_ID . "\n");
    $file = afirmaProxyFile ($params['id']);
    afirmaProxyCleanup ();
    /* Como el servlet: el mismo error si no existe o ha caducado. */
    $data = $file === null || !is_file ($file) ? false : @file_get_contents ($file);
    if ($data === false)
        afirmaProxyAnswer (AFIRMA_ERR_INVALID_ID . " ('" . preg_replace ('/[^A-Za-z0-9_-]/', '', $params['id']) . "')\n");
    @unlink ($file);
    afirmaProxyAnswer ($data . "\n");
}
