<?php
/**
 * Identification with a digital certificate (TLS client certificate).
 *
 * Apache (mod_ssl) asks the browser for the certificate and checks its chain
 * against SSLCACertificateFile; PHP gets the result in SSL_CLIENT_VERIFY and
 * the certificate itself in SSL_CLIENT_CERT (SSLOptions +StdEnvVars
 * +ExportCertData). The chain and the validity dates are checked again here
 * against clientcert_ca_file, and the holder's DNI/NIE is read from the
 * serialNumber of the subject: "IDCES-12345678Z" in the FNMT certificates,
 * "12345678Z" in the DNIe and the older ones.
 *
 * Revocation is checked here too (utils/certrevocation.php): OCSP and, if the
 * responder doesn't answer, the CRL of the certificate. Apache doesn't check it.
 */
require_once 'include/config.php';
require_once 'utils/logger.php';
require_once 'utils/pdfsign.php';
require_once 'utils/certrevocation.php';

/**
 * The message is meant to be shown to the participant.
 */
class ClientCertException extends Exception {}

/* true si la identificación con certificado digital está activada. */
function clientCertEnabled (): bool {
    return !empty (Config::PARAMS["clientcert_enabled"]);
}

/* Dirección de la página de identificación con certificado: puede estar en
   otro host, el que pide el certificado al navegador. */
function clientCertUrl (): string {
    return Config::PARAMS["clientcert_url"] ?? "cert_login";
}

/**
 * Gets the DNI/NIE of the holder of the client certificate of the request.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws ClientCertException if there's no valid certificate or it has no DNI.
 */
function getClientCertDni (): string {
    $verify = $_SERVER["SSL_CLIENT_VERIFY"] ?? "";
    $pem = $_SERVER["SSL_CLIENT_CERT"] ?? "";
    if ($verify === "" || $verify === "NONE" || $pem === "")
        throw new ClientCertException ("No se ha recibido ningún certificado digital. " .
            "Comprueba que lo tienes instalado en el navegador y elígelo cuando te lo pida.");
    if ($verify !== "SUCCESS"){
        logMessage (LOGGER_WARN, "Client certificate rejected by Apache: {$verify}.");
        throw new ClientCertException ("El certificado digital no es válido o no es de " .
            "una autoridad de certificación admitida.");
    }
    $cert = openssl_x509_read ($pem);
    if ($cert === false){
        logMessage (LOGGER_ERROR, "Client certificate can't be read.");
        throw new ClientCertException ("No se ha podido leer el certificado digital.");
    }
    $info = openssl_x509_parse ($cert);
    $now = time ();
    if ($info === false || ($info["validFrom_time_t"] ?? PHP_INT_MAX) > $now ||
            ($info["validTo_time_t"] ?? 0) < $now){
        logMessage (LOGGER_WARN, "Client certificate rejected: out of its validity period.");
        throw new ClientCertException ("El certificado digital está caducado o aún no es válido.");
    }
    /* Además de Apache: si el servidor se configura mal (SSLVerifyClient
       optional_no_ca, otras autoridades), aquí no se acepta. */
    $cafile = Config::PARAMS["clientcert_ca_file"] ?? "certs/clientcert_ca.pem";
    if (!is_readable ($cafile)){
        logMessage (LOGGER_ERROR, "Client certificate CA file {$cafile} can't be read.");
        throw new ClientCertException ("La identificación con certificado digital no está " .
            "bien configurada.");
    }
    if (openssl_x509_checkpurpose ($cert, X509_PURPOSE_SSL_CLIENT, [$cafile]) !== true){
        logMessage (LOGGER_WARN, "Client certificate rejected: not issued by a trusted CA.");
        throw new ClientCertException ("El certificado digital no es de una autoridad de " .
            "certificación admitida.");
    }
    if (Config::PARAMS["clientcert_revocation"] ?? true){
        try {
            certCheckRevocation ($cert, $cafile);
        } catch (CertRevocationException $e) {
            throw new ClientCertException ($e->getMessage ());
        }
    }
    $serials = $info["subject"]["serialNumber"] ?? [];
    $found = [];
    foreach ((array) $serials as $serial){
        if (preg_match ('/^(?:IDCES-)?([0-9XYZ][0-9]{7}[A-Z])$/i', trim ($serial), $m) &&
                pdfValidDni (strtoupper ($m[1])))
            $found[] = strtoupper ($m[1]);
    }
    $found = array_unique ($found);
    /* Los sellos y los certificados de componente no son de una persona. */
    if (count ($found) != 1){
        logMessage (LOGGER_WARN, "Client certificate rejected: no single valid DNI in it.");
        throw new ClientCertException ("El certificado digital no es de una persona física " .
            "o no se ha podido leer su DNI.");
    }
    return $found[0];
}
