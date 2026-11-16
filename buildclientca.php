<?php
/**
 * Builds the file of CAs accepted for the client certificate asked when
 * registering (SSLCACertificateFile in Apache, ssl_client_certificate in
 * nginx) from the Spanish Trusted List (TSL), so that every qualified
 * certificate of a natural person is accepted: DNI electrónico, FNMT,
 * public employees, representatives, notaries, ACCV, Izenpe, Camerfirma,
 * Firmaprofesional, Uanataca...
 *
 * It takes the services of the TSL that are qualified certificate CAs
 * (CA/QC), in force (granted) and for electronic signatures (ForeSignatures):
 * the certificates of natural persons. Their certificates are usually
 * intermediate CAs; when they are roots (RootCA-QC, as the DNIe one), the
 * subordinate CAs linked from the service (AC DNIE 004, 005, 006...) are
 * downloaded too, since the browser may not send them. The web server needs
 * the chain up to the root, so the issuers of the CAs
 * issuers are looked for (checking that they really signed them) among the
 * other certificates of the TSL, the CAs of the system, the ones of the
 * optional file of extra CAs and, if not there, downloaded from their
 * Authority Information Access.
 *
 * The TSL changes when a provider gets or loses its qualification: run it
 * regularly (e.g. monthly from cron) and reload the web server.
 *
 * Usage: php buildclientca.php output.pem [TSL URL or file] [extra CAs PEM file]
 */

const TSL_URL = "https://tsl.digital.gob.es/TSL.xml";
const TSL_NS = "http://uri.etsi.org/02231/v2#";
const TSL_CAQC = "http://uri.etsi.org/TrstSvc/Svctype/CA/QC";
const TSL_GRANTED = "http://uri.etsi.org/TrstSvc/TrustedList/Svcstatus/granted";
const TSL_ESIG = "http://uri.etsi.org/TrstSvc/TrustedList/SvcInfoExt/ForeSignatures";
const TSL_ROOTCA = "http://uri.etsi.org/TrstSvc/TrustedList/SvcInfoExt/RootCA-QC";
/* Saltos como mucho desde la CA de la TSL hasta la raíz. */
const CHAIN_MAX_DEPTH = 4;

if (PHP_SAPI !== "cli")
    exit (1);
if ($argc < 2){
    fwrite (STDERR, "Sintaxis: php buildclientca.php fichero_salida.pem [URL o fichero de la TSL] " .
        "[PEM con CA raíz adicionales]\n");
    exit (1);
}
$output = $argv[1];
$source = ($argv[2] ?? "") !== "" ? $argv[2] : TSL_URL;
$extra = $argv[3] ?? null;
/* Posibles emisores: [sujeto, PEM] de los certificados de la TSL, del
   sistema y del fichero adicional. */
$candidates = [];

function fetch (string $url): string {
    $context = stream_context_create (["http" => ["timeout" => 30, "follow_location" => 1,
        "user_agent" => "mlsurvey-buildclientca"]]);
    $data = @file_get_contents ($url, false, $context);
    if ($data === false || $data === "")
        throw new Exception ("No se ha podido descargar {$url}");
    return $data;
}

/* PEM de un certificado en DER, PEM o base64 (el de la TSL). */
function toPem (string $data): ?string {
    if (str_contains ($data, "-----BEGIN CERTIFICATE-----")){
        $cert = @openssl_x509_read ($data);
    } else {
        $der = preg_match ('/^[A-Za-z0-9+\/=\s]+$/', $data) ? base64_decode ($data, true) : $data;
        if ($der === false)
            return null;
        $cert = @openssl_x509_read ("-----BEGIN CERTIFICATE-----\n" .
            chunk_split (base64_encode ($der), 64, "\n") . "-----END CERTIFICATE-----\n");
    }
    if ($cert === false || !openssl_x509_export ($cert, $pem))
        return null;
    return $pem;
}

/* Añade a los candidatos los certificados que se puedan leer. */
function addCandidates (array $pems){
    global $candidates;
    foreach ($pems as $pem)
        if ($pem !== null && ($info = openssl_x509_parse ($pem)) !== false)
            $candidates[] = [$info['subject'], $pem];
}

/* Los certificados de un fichero PEM con varios. */
function pemsInFile (string $path): array {
    $data = @file_get_contents ($path);
    if ($data === false)
        return [];
    preg_match_all ('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $data, $m);
    return array_filter (array_map ('toPem', $m[0]));
}

function signedBy (string $pem, string $issuer): bool {
    $key = openssl_pkey_get_public ($issuer);
    return $key !== false && openssl_x509_verify ($pem, $key) === 1;
}

/* El emisor de un certificado: entre los candidatos o, si no está, de la URL
   caIssuers de su AIA. null si no se encuentra. */
function issuerOf (string $pem): ?string {
    global $candidates;
    $info = openssl_x509_parse ($pem);
    foreach ($candidates as [$subject, $issuer])
        if ($subject == $info['issuer'] && signedBy ($pem, $issuer))
            return $issuer;
    $aia = $info['extensions']['authorityInfoAccess'] ?? "";
    preg_match_all ('/CA Issuers - URI:(\S+)/', $aia, $urls);
    foreach ($urls[1] as $url){
        try {
            $issuer = toPem (fetch ($url));
        } catch (Exception $e) {
            continue;
        }
        /* Una URL .p7c con varios certificados no se lee: se prueba la siguiente. */
        if ($issuer !== null && signedBy ($pem, $issuer))
            return $issuer;
    }
    return null;
}

function isSelfSigned (string $pem): bool {
    $info = openssl_x509_parse ($pem);
    return $info['subject'] == $info['issuer'] && signedBy ($pem, $pem);
}

try {
    $xml = preg_match ('#^https?://#', $source) ? fetch ($source) : file_get_contents ($source);
    $doc = new DOMDocument ();
    if ($xml === false || !$doc->loadXML ($xml, LIBXML_NONET))
        throw new Exception ("La TSL no es un XML válido");
    $xpath = new DOMXPath ($doc);
    $xpath->registerNamespace ("t", TSL_NS);

    foreach ($xpath->query ("//t:X509Certificate") as $node)
        addCandidates ([toPem (trim ($node->textContent))]);
    $system = openssl_get_cert_locations ()['default_cert_file'] ?? "";
    foreach (array_unique ([$system, "/etc/ssl/certs/ca-certificates.crt"]) as $file)
        addCandidates (pemsInFile ($file));
    if ($extra !== null){
        $extrapems = pemsInFile ($extra);
        if (empty ($extrapems))
            throw new Exception ("No hay certificados en {$extra}");
        addCandidates ($extrapems);
    }

    $certs = [];   // huella => [nombre, PEM]
    $missing = [];
    $services = $xpath->query ("//t:TSPService/t:ServiceInformation[" .
        "t:ServiceTypeIdentifier = '" . TSL_CAQC . "' and t:ServiceStatus = '" . TSL_GRANTED . "' and " .
        ".//t:AdditionalServiceInformation/t:URI = '" . TSL_ESIG . "']");
    foreach ($services as $service){
        $name = trim ($xpath->evaluate ("string(t:ServiceName/t:Name[1])", $service));
        $pems = [];
        foreach ($xpath->query ("t:ServiceDigitalIdentity/t:DigitalId/t:X509Certificate", $service) as $node){
            $pem = toPem (trim ($node->textContent));
            if ($pem === null)
                fwrite (STDERR, "AVISO: certificado ilegible en «{$name}»\n");
            else
                $pems[] = $pem;
        }
        /* Las subordinadas de una raíz: las que estén enlazadas en el
           servicio, sean CA y estén firmadas por ella. */
        if ($xpath->evaluate ("count(.//t:AdditionalServiceInformation[t:URI = '" . TSL_ROOTCA . "'])",
                $service) > 0){
            $roots = $pems;
            $urls = [];
            foreach ($xpath->query (".//t:URI", $service) as $node)
                if (preg_match ('#^https?://\S+\.(?:crt|cer|der|pem)$#i', trim ($node->textContent)))
                    $urls[trim ($node->textContent)] = true;
            foreach (array_keys ($urls) as $url){
                try {
                    $sub = toPem (fetch ($url));
                } catch (Exception $e) {
                    fwrite (STDERR, "AVISO: {$e->getMessage()} («{$name}»)\n");
                    continue;
                }
                $info = $sub === null ? false : openssl_x509_parse ($sub);
                if ($info === false || !str_contains ($info['extensions']['basicConstraints'] ?? "", "CA:TRUE"))
                    continue;
                foreach ($roots as $root)
                    if ($sub !== $root && signedBy ($sub, $root)){
                        $pems[] = $sub;
                        break;
                    }
            }
        }
        foreach ($pems as $pem){
            /* La CA y sus emisores hasta la raíz. */
            for ($depth = 0; $pem !== null && $depth <= CHAIN_MAX_DEPTH; $depth++){
                $fingerprint = openssl_x509_fingerprint ($pem, "sha256");
                if (isset ($certs[$fingerprint]))
                    break;
                $info = openssl_x509_parse ($pem);
                $certs[$fingerprint] = [$info['name'], $pem];
                if (isSelfSigned ($pem))
                    break;
                $issuer = issuerOf ($pem);
                if ($issuer === null)
                    $missing[] = $info['name'];
                $pem = $issuer;
            }
        }
    }
    if (empty ($certs))
        throw new Exception ("No se ha encontrado ninguna CA en la TSL");

    $now = time ();
    $out = "# CA admitidas para el certificado digital del registro.\n" .
        "# Generado por buildclientca.php a partir de {$source} el " . date ("Y-m-d") . ".\n";
    $count = 0;
    foreach ($certs as [$name, $pem]){
        $info = openssl_x509_parse ($pem);
        if ($info['validTo_time_t'] < $now)
            continue;
        $out .= "\n# {$name}\n{$pem}";
        $count++;
    }
    $tmp = $output . ".tmp";
    if (file_put_contents ($tmp, $out) === false || !rename ($tmp, $output))
        throw new Exception ("No se ha podido escribir {$output}");
    echo "{$count} certificados de CA escritos en {$output}\n";
    foreach (array_unique ($missing) as $name)
        fwrite (STDERR, "AVISO: no se ha encontrado el emisor de {$name}: descarga su CA raíz de la " .
            "web del prestador y pásala en el tercer argumento.\n");
} catch (Exception $e) {
    fwrite (STDERR, "Error: {$e->getMessage()}\n");
    exit (1);
}
