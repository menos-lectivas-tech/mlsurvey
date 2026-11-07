<?php
/**
 * Revocation check of the certificate that signs the declaration.
 *
 * First the OCSP responder of the certificate (Authority Information Access) is
 * asked; if it doesn't answer (e.g. the FNMT one returning "502 Proxy Error"),
 * the CRL of the certificate (CRL Distribution Points) is used instead. The FNMT
 * splits the CRL of "AC FNMT Usuarios" in thousands of partitions of ~80 KB, so
 * only the one of the certificate is downloaded, and it is cached for a while.
 * Both the OCSP answer and the CRL are checked against the trusted CAs; if
 * neither can be used the certificate is rejected.
 *
 * Needs the openssl command.
 */
require_once 'utils/logger.php';
require_once 'utils/pdfsign.php';

const CERTREV_OCSP_TIMEOUT = 5;
const CERTREV_CRL_TIMEOUT = 10;
const CERTREV_CRL_MAX_SIZE = 20 * 1024 * 1024;
/* Una CRL descargada se vuelve a pedir pasado este tiempo; si no se puede,
   se sigue usando mientras no pase su nextUpdate. */
const CERTREV_CRL_REFRESH = 3 * 3600;
/* Margen para la diferencia de hora con la autoridad. */
const CERTREV_CLOCK_SKEW = 300;

class CertRevocationException extends Exception {}

/**
 * Checks that the certificate isn't revoked.
 *
 * @param OpenSSLCertificate $cert Certificate, already checked with certVerifyOfficial.
 * @param OpenSSLCertificate $issuer Its issuer, as certVerifyOfficial returns it.
 * @param string $cafile PEM file with the trusted CAs.
 *
 * @throws CertRevocationException with a message for the participant if it is
 * revoked or its status can't be known.
 */
function certCheckRevocation (OpenSSLCertificate $cert, OpenSSLCertificate $issuer, string $cafile){
    $info = openssl_x509_parse ($cert);
    $certfile = certTempFile ($cert);
    $issuerfile = certTempFile ($issuer);
    try {
        $status = certOcspStatus ($info, $certfile, $issuerfile, $cafile);
        if ($status === null)
            $status = certCrlStatus ($info, $issuerfile);
    } finally {
        @unlink ($certfile);
        @unlink ($issuerfile);
    }
    if ($status === "good")
        return;
    if ($status === "revoked"){
        logMessage (LOGGER_WARN, "Certificate rejected: revoked.");
        throw new CertRevocationException ("El certificado digital está revocado.");
    }
    if ($status === "unknown"){
        logMessage (LOGGER_WARN, "Certificate rejected: unknown to its OCSP responder.");
        throw new CertRevocationException ("La autoridad de certificación no reconoce " .
            "el certificado digital.");
    }
    logMessage (LOGGER_ERROR, "Certificate rejected: neither OCSP nor CRL available.");
    throw new CertRevocationException ("No se ha podido comprobar si el certificado digital " .
        "está revocado: el servicio de la autoridad de certificación no responde. Inténtalo " .
        "más tarde.");
}

/* Certificado de los ficheros PEM $files que ha emitido $cert, o null. */
function certFindIssuer (OpenSSLCertificate $cert, array $info, array $files): ?OpenSSLCertificate {
    $pem = "";
    foreach ($files as $file)
        $pem .= (string) @file_get_contents ($file);
    preg_match_all ('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
    foreach ($m[0] as $block){
        $ca = openssl_x509_read ($block);
        if ($ca === false)
            continue;
        $cainfo = openssl_x509_parse ($ca);
        if (($cainfo["subject"] ?? null) == ($info["issuer"] ?? false) &&
                openssl_x509_verify ($cert, $ca) === 1)
            return $ca;
    }
    return null;
}

function certTempFile (OpenSSLCertificate $cert): string {
    $file = tempnam (sys_get_temp_dir (), "mlcert");
    if ($file === false || !openssl_x509_export_to_file ($cert, $file))
        throw new Exception ("Can't write a temporary certificate file");
    return $file;
}

/* URIs de una extensión tal como la da openssl_x509_parse. */
function certExtUris (array $info, string $ext, string $prefix = ""): array {
    preg_match_all ('/' . $prefix . 'URI:(\S+)/', $info["extensions"][$ext] ?? "", $m);
    return $m[1];
}

function certIsHttp (string $url): bool {
    return preg_match ('#^https?://#i', $url) === 1;
}

/**
 * Asks the OCSP responders of the certificate.
 *
 * @return string|null "good", "revoked", "unknown" or null if none answered.
 */
function certOcspStatus (array $info, string $certfile, string $issuerfile, string $cafile): ?string {
    foreach (certExtUris ($info, "authorityInfoAccess", 'OCSP - ') as $url){
        if (!certIsHttp ($url))
            continue;
        [$code, $out, $err] = pdfRunCommand (["openssl", "ocsp", "-issuer", $issuerfile,
            "-cert", $certfile, "-url", $url, "-no_nonce", "-CAfile", $cafile,
            /* Las CA de $cafile pueden ser intermedias, y el emisor puede no
               estar (el DNIe): ya se ha comprobado que encadena con ellas. */
            "-partial_chain", "-verify_other", $issuerfile,
            "-timeout", (string) CERTREV_OCSP_TIMEOUT]);
        if ($code === 0 && str_contains ($err, "Response verify OK") &&
                !str_contains ($out . $err, "Status times invalid") &&
                preg_match ('/^' . preg_quote ($certfile, '/') . ': (good|revoked|unknown)$/m',
                    $out, $m))
            return $m[1];
        logMessage (LOGGER_WARN, "Revocation: OCSP responder {$url} failed: " .
            trim (preg_replace ('/\s+/', ' ', $err . " " . $out)));
    }
    return null;
}

/**
 * Looks for the certificate in its CRL.
 *
 * @return string|null "good", "revoked" or null if no usable CRL.
 */
function certCrlStatus (array $info, string $issuerfile): ?string {
    $serial = certNormSerial ($info["serialNumberHex"] ?? "");
    $dps = certExtUris ($info, "crlDistributionPoints");
    if ($serial === "")
        return null;
    foreach ($dps as $url){
        if (!certIsHttp ($url))
            continue;
        $crl = certGetCrl ($url, $issuerfile);
        if ($crl === null)
            continue;
        /* Una CRL particionada solo sirve si es la partición del certificado. */
        if ($crl["idp"] && !array_intersect (array_map ('strtolower', $crl["idp"]),
                array_map ('strtolower', $dps))){
            logMessage (LOGGER_ERROR, "Revocation: the CRL of {$url} is for another partition.");
            continue;
        }
        return isset ($crl["serials"][$serial]) ? "revoked" : "good";
    }
    return null;
}

/* Número de serie en hexadecimal, en mayúsculas y sin ceros a la izquierda. */
function certNormSerial (string $hex): string {
    $hex = strtoupper (str_replace (":", "", trim ($hex)));
    if ($hex === "")
        return "";
    $hex = ltrim ($hex, "0");
    return $hex === "" ? "0" : $hex;
}

/* CRL de $url, de la caché o descargada. Null si no hay una válida. */
function certGetCrl (string $url, string $issuerfile): ?array {
    $dir = sys_get_temp_dir () . "/mlsurvey-crl";
    if (!is_dir ($dir))
        @mkdir ($dir, 0700, true);
    $cached = $dir . "/" . hash ("sha256", $url) . ".crl";
    $crl = is_file ($cached) ? certReadCrl ($cached, $issuerfile) : null;
    if ($crl !== null && filemtime ($cached) > time () - CERTREV_CRL_REFRESH)
        return $crl;

    $tmp = tempnam ($dir, "dl");
    $data = certDownload ($url);
    if ($tmp !== false && $data !== null && file_put_contents ($tmp, $data) !== false){
        $new = certReadCrl ($tmp, $issuerfile);
        if ($new !== null && rename ($tmp, $cached))
            return $new;
        if ($new === null)
            logMessage (LOGGER_WARN, "Revocation: the CRL downloaded from {$url} isn't valid.");
    }
    if ($tmp !== false)
        @unlink ($tmp);
    if ($crl !== null)
        logMessage (LOGGER_WARN, "Revocation: using the cached CRL of {$url}.");
    return $crl;
}

function certDownload (string $url): ?string {
    $ctx = stream_context_create (["http" => ["timeout" => CERTREV_CRL_TIMEOUT,
        "follow_location" => 1, "max_redirects" => 3, "ignore_errors" => true]]);
    $data = @file_get_contents ($url, false, $ctx, 0, CERTREV_CRL_MAX_SIZE + 1);
    /* Con redirecciones van seguidas las cabeceras de todas: vale la última. */
    $status = "";
    foreach ($http_response_header ?? [] as $h)
        if (str_starts_with ($h, "HTTP/"))
            $status = $h;
    if ($data === false || !preg_match ('#^HTTP/\S+ 200\b#', $status) ||
            strlen ($data) > CERTREV_CRL_MAX_SIZE){
        logMessage (LOGGER_WARN, "Revocation: can't download the CRL {$url} ({$status}).");
        return null;
    }
    return $data;
}

/**
 * Reads a CRL (DER or PEM) checking that $issuerfile signed it and that it is
 * current.
 *
 * @return array|null ["serials" => [serial => true], "idp" => [URIs]] or null.
 */
function certReadCrl (string $file, string $issuerfile): ?array {
    $head = (string) @file_get_contents ($file, false, null, 0, 64);
    $form = str_contains ($head, "-----BEGIN") ? "PEM" : "DER";
    /* Con solo el emisor en -CAfile, «verify OK» es que lo ha firmado él. */
    [$code, $out, $err] = pdfRunCommand (["openssl", "crl", "-inform", $form, "-in", $file,
        "-CAfile", $issuerfile, "-noout", "-lastupdate", "-nextupdate", "-text"]);
    if ($code !== 0 || !str_contains ($err, "verify OK"))
        return null;
    if (!preg_match ('/^lastUpdate=(.+)$/m', $out, $last) ||
            !preg_match ('/^nextUpdate=(.+)$/m', $out, $next))
        return null;
    $last = strtotime ($last[1]);
    $next = strtotime ($next[1]);
    $now = time ();
    if ($last === false || $next === false || $last > $now + CERTREV_CLOCK_SKEW || $next < $now)
        return null;
    /* Extensiones de la CRL: hasta la lista de revocados. */
    $parts = preg_split ('/^\s*(Revoked Certificates:|No Revoked Certificates\.)/m', $out, 2);
    $idp = [];
    if (preg_match ('/Issuing Distribution Point:.*?(?=X509v3|$)/s', $parts[0], $m)){
        preg_match_all ('/URI:(\S+)/', $m[0], $u);
        $idp = $u[1];
    }
    $serials = [];
    if (isset ($parts[1])){
        preg_match_all ('/Serial Number: ([0-9A-Fa-f]+)/', $parts[1], $s);
        foreach ($s[1] as $serial)
            $serials[certNormSerial ($serial)] = true;
    }
    return ["serials" => $serials, "idp" => $idp];
}
