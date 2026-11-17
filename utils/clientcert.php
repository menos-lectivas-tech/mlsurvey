<?php
/**
 * Gets the holder's DNI/NIE from the client certificate the participant
 * authenticated the TLS connection with, to check that the documents uploaded
 * when registering are theirs. It may be any qualified certificate of a
 * natural person: the DNI electrónico, the FNMT one, the ones of public
 * employees (AAPP profiles), of representatives, notaries, ACCV, Izenpe,
 * Camerfirma, Firmaprofesional, Uanataca... Which CAs are accepted is decided
 * by the web server: buildclientca.php makes the list from the Spanish
 * Trusted List (TSL).
 *
 * The certificate chain is verified by the web server (Apache mod_ssl with
 * SSLVerifyClient, or the TLS proxy in front of the application), which
 * passes the result and the certificate in two variables of $_SERVER:
 * client_cert_verify_var ("SUCCESS" if verified) and client_cert_var (the
 * certificate in PEM, or URL-encoded PEM as nginx's $ssl_client_escaped_cert).
 * By default the ones of Apache mod_ssl (SSLOptions +StdEnvVars +ExportCertData).
 */
require_once 'include/config.php';
require_once 'utils/logger.php';
require_once 'utils/pdfsign.php';
require_once 'utils/host.php';

/**
 * The message is meant to be shown to the participant.
 */
class ClientCertException extends Exception {}

/**
 * true if registering requires the client certificate (client_cert_enabled).
 */
function clientCertEnabled (): bool {
    return (bool) (Config::PARAMS["client_cert_enabled"] ?? true);
}

/**
 * The page $page in the URL that asks for the client certificate
 * (registration_url), where registering and voting are done. Just $page if the
 * certificate isn't asked or there is no such URL.
 */
function certURL (string $page): string {
    return clientCertEnabled () && getRegistrationURL () != "" ? getRegistrationURL () . $page : $page;
}

/**
 * true if the request needs the client certificate and hasn't reached the URL
 * that asks for it: the user has to go there.
 */
function mustGoToCertHost (): bool {
    return clientCertEnabled () && !isRegistrationHost ();
}

/**
 * Value of a server variable. With an internal redirect (the rewrite of
 * .htaccess) Apache may only keep it with the REDIRECT_ prefix.
 */
function clientCertServerVar (string $name): string {
    $value = $_SERVER[$name] ?? $_SERVER["REDIRECT_" . $name] ?? "";
    return is_string ($value) ? trim ($value) : "";
}

/**
 * Returns the DNI/NIE of the client certificate of the request (see
 * clientCertDni).
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws ClientCertException if there is no verified certificate or it has
 *     no valid DNI/NIE.
 */
function getClientCertDni (): string {
    $verifyvar = Config::PARAMS["client_cert_verify_var"] ?? "SSL_CLIENT_VERIFY";
    $certvar = Config::PARAMS["client_cert_var"] ?? "SSL_CLIENT_CERT";
    $verify = clientCertServerVar ($verifyvar);
    $pem = clientCertServerVar ($certvar);
    /* Sin proxy delante (variables de mod_ssl), por HTTP no llega nunca. */
    if ($verify === "" && !str_starts_with ($verifyvar, "HTTP_") && empty ($_SERVER['HTTPS'])){
        logMessage (LOGGER_WARN, "Registration over plain HTTP: no client certificate can be asked.");
        throw new ClientCertException ("La página no se ha abierto por HTTPS y así el navegador " .
            "no puede pedirte el certificado electrónico. Ábrela con https://.");
    }
    if ($verify === "" || $verify === "NONE" || $pem === "" || $pem === "(null)"){
        logMessage (LOGGER_INFO, "Registration without client certificate.");
        throw new ClientCertException ("No se ha recibido tu certificado electrónico. Tienes " .
            "que identificarte con él (DNI electrónico, certificado de la FNMT, " .
            "de empleado/a público/a...): cierra el navegador, vuelve a abrir la página y elige tu " .
            "certificado cuando te lo pida. Con el DNI electrónico, conecta antes el lector.");
    }
    if ($verify !== "SUCCESS"){
        logMessage (LOGGER_WARN, "Client certificate rejected by the server: {$verify}.");
        throw new ClientCertException ("Tu certificado electrónico no es válido, ha caducado o no " .
            "es un certificado cualificado de un prestador de confianza.");
    }
    /* El PEM no lleva %: si lo hay, viene URL-encoded. */
    if (str_contains ($pem, "%"))
        $pem = rawurldecode ($pem);
    $cert = openssl_x509_parse ($pem);
    if ($cert === false){
        logMessage (LOGGER_ERROR, "Can't parse the client certificate in {$certvar}.");
        throw new ClientCertException ("No se ha podido leer tu certificado digital.");
    }
    /* El servidor ya lo ha comprobado; por si acaso. */
    $now = time ();
    if ($now < ($cert['validFrom_time_t'] ?? PHP_INT_MAX) || $now > ($cert['validTo_time_t'] ?? 0)){
        logMessage (LOGGER_WARN, "Client certificate out of its validity period.");
        throw new ClientCertException ("Tu certificado digital está caducado o todavía no es válido.");
    }

    $dni = clientCertDni ($cert);
    if ($dni === null){
        logMessage (LOGGER_WARN, "Client certificate without a valid DNI/NIE.");
        throw new ClientCertException ("Tu certificado digital no es de una persona física con " .
            "DNI o NIE.");
    }
    return $dni;
}

/**
 * DNI/NIE of a certificate (as openssl_x509_parse returns it), or null if it
 * has no valid one or several different ones. It is looked for in:
 * - The serialNumber of the subject, alone ("12345678Z": DNIe, ACCV...) or
 *   with the ETSI EN 319 412-1 prefix ("IDCES-12345678Z": FNMT, Izenpe,
 *   Camerfirma...; also TINES-, PNOES-; IDESP- in some DNIe).
 * - The directoryName of the subjectAltName, where the old FNMT profile
 *   (1.3.6.1.4.1.5734.1.4) and the public employee one of the AAPP
 *   (2.16.724.1.3.5.7.<level>.4) put it.
 * - The end of the CN ("APELLIDOS NOMBRE - 12345678Z", "NOMBRE APELLIDOS -
 *   NIF 12345678Z", "... - DNI 12345678Z"), or its start in the representative
 *   ones ("12345678Z NOMBRE APELLIDOS (R: B12345678)").
 */
function clientCertDni (array $cert): ?string {
    $dni = PDF_DNI;
    $subject = $cert['subject'] ?? [];
    $found = [];
    foreach ((array) ($subject['serialNumber'] ?? []) as $serial)
        if (preg_match ("/^(?:(?:IDC|TIN|PNO)ES-|IDESP-)?{$dni}$/", strtoupper (trim ($serial)), $m))
            $found[] = $m[1];
    $san = $cert['extensions']['subjectAltName'] ?? "";
    if (is_string ($san) && preg_match_all ('/(?:^|[\s,:])(?:1\.3\.6\.1\.4\.1\.5734\.1\.4|' .
            '2\.16\.724\.1\.3\.5\.7\.\d+\.4)\s*=\s*' . $dni . '/i', $san, $m))
        array_push ($found, ...array_map ('strtoupper', $m[1]));
    foreach ((array) ($subject['CN'] ?? []) as $cn){
        $cn = strtoupper (trim ($cn));
        if (preg_match ("/\\s-\\s*(?:(?:NIF|DNI|NIE):?\\s*)?{$dni}\\s*$/", $cn, $m) ||
                preg_match ("/^{$dni}\\s.*\\(R:\\s*[^)]*\\)$/", $cn, $m))
            $found[] = $m[1];
    }
    $found = array_unique ($found);
    return count ($found) == 1 && pdfValidDni (reset ($found)) ? reset ($found) : null;
}
