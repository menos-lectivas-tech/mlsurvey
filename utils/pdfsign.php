<?php
/**
 * Functions for checking the PDFs asked for participating and getting the
 * holder's DNI from them: the digitally signed training record (extracto de
 * formación) with the digitally signed MUFACE membership certificate (career
 * civil servants), or the digitally signed appointment, nombramiento (interim
 * ones), the service record or the teaching credential on their own.
 *
 * Needs the openssl, pdftotext and pdfinfo (poppler-utils) commands.
 */
require_once 'include/config.php';
require_once 'utils/logger.php';

/* Límite del PDF subido: estos documentos ocupan unos cientos de KB. */
const PDF_MAX_SIZE = 5 * 1024 * 1024;
const PDF_CMD_TIMEOUT = 20;
const PDF_TITLE = "EXTRACTO INDIVIDUAL DE RECONOCIMIENTO DE ACTIVIDADES";
const PDF_MUFACE_TITLE = "CERTIFICADO DE AFILIACIÓN A MUFACE";
const PDF_DNI = '([0-9XYZ][0-9]{7}[A-Z])\b';
const PDF_MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
    "agosto", "septiembre", "octubre", "noviembre", "diciembre"];

/**
 * The message is meant to be shown to the participant.
 */
class PdfSignException extends Exception {}

/**
 * Runs a command without a shell.
 *
 * @param array $cmd Command and arguments.
 *
 * @return array [exit code, stdout, stderr]
 */
function pdfRunCommand (array $cmd): array {
    /* max_execution_time no cuenta el tiempo de un proceso externo. */
    $cmd = array_merge (["timeout", (string) PDF_CMD_TIMEOUT], $cmd);
    /* stderr va a un fichero: leyendo dos tuberías en serie el proceso se
       puede quedar bloqueado con la segunda llena. */
    $errfile = tmpfile ();
    $proc = proc_open ($cmd, [0 => ["file", "/dev/null", "r"], 1 => ["pipe", "w"], 2 => $errfile], $pipes);
    if ($proc === false)
        throw new Exception ("Can't run {$cmd[2]}");
    $out = stream_get_contents ($pipes[1]);
    fclose ($pipes[1]);
    $code = proc_close ($proc);
    rewind ($errfile);
    $err = stream_get_contents ($errfile);
    fclose ($errfile);
    return [$code, $out, $err];
}

/**
 * Returns the signatures of a PDF that cover the whole file.
 * Each one is [signed bytes, DER signature, signing date or null].
 */
function pdfWholeFileSignatures (string $pdf): array {
    $signatures = [];
    $size = strlen ($pdf);
    if (!preg_match_all ('/\/ByteRange\s*\[\s*(\d{1,10})\s+(\d{1,10})\s+(\d{1,10})\s+(\d{1,10})\s*\]/',
            $pdf, $matches, PREG_SET_ORDER))
        return $signatures;
    foreach (array_slice ($matches, 0, 8) as $m){
        [$start, $len1, $start2, $len2] = array_map ('intval', array_slice ($m, 1));
        /* Lo firmado tiene que ser el fichero entero menos el hueco de la
           propia firma: si quedase algo fuera (por ejemplo una revisión
           añadida después de firmar) se podría cambiar el contenido sin
           romper la firma. */
        if ($start != 0 || $len1 == 0 || $start2 <= $len1 || $start2 + $len2 != $size)
            continue;
        $hole = substr ($pdf, $len1, $start2 - $len1);
        $hex = substr ($hole, 1, -1);
        if ($hole[0] != '<' || substr ($hole, -1) != '>' || strlen ($hex) % 2 != 0 || !ctype_xdigit ($hex))
            continue;
        /* La fecha de firma es la /M del diccionario de la firma, que queda
           dentro de lo firmado: desde el "obj" que lo abre hasta el "endobj". */
        $from = max (0, $len1 - 4096);
        $open = strrpos (substr ($pdf, $from, $len1 - $from), " obj");
        $close = strpos ($pdf, "endobj", $start2);
        $dict = ($open === false ? "" : substr ($pdf, $from + $open, $len1 - $from - $open)) .
            ($close === false ? "" : substr ($pdf, $start2, min ($close - $start2, 4096)));
        $signed = null;
        if (preg_match ('/\/M\s*\(D:(\d{4})(\d\d)(\d\d)/', $dict, $m) &&
                checkdate ((int) $m[2], (int) $m[3], (int) $m[1]))
            $signed = (new DateTimeImmutable ("today"))->setDate ((int) $m[1], (int) $m[2], (int) $m[3]);
        /* Los ceros de relleno del final no molestan al analizar el DER. */
        $signatures[] = [substr ($pdf, 0, $len1) . substr ($pdf, $start2), hex2bin ($hex), $signed];
    }
    return $signatures;
}

/**
 * Reads an uploaded file checking that it looks like a PDF.
 *
 * @throws PdfSignException if it is not a PDF or it is too big.
 */
function pdfReadFile (string $path): string {
    $size = filesize ($path);
    if ($size === false || $size == 0 || $size > PDF_MAX_SIZE)
        throw new PdfSignException ("El fichero está vacío o es demasiado grande.");
    $pdf = file_get_contents ($path);
    if ($pdf === false || strncmp ($pdf, "%PDF-", 5) != 0)
        throw new PdfSignException ("El fichero no es un PDF.");
    return $pdf;
}

/**
 * Checks that a PDF is digitally signed, that the signature covers the whole
 * file and is not broken, and that the signer is one of the trusted ones.
 *
 * @param string $path PDF file.
 * @param string $cafile PEM file with the trusted certification authorities.
 * @param array $signerids Values of $signerfield allowed: by default the
 *     serialNumber (NIF) of the certificates allowed to sign.
 * @param string $issuer Who issues the document, for the error message.
 * @param string $signerfield Field of the signer's subject that must be one
 *     of $signerids.
 *
 * @return array The validity of the signer's certificate, "from" and "to" in
 *     format "YYMMddhhmmssZ", and "signed", the signing date
 *     (DateTimeImmutable) or null if the PDF does not say it.
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkPdfSignature (string $path, string $cafile, array $signerids, string $issuer,
        string $signerfield = "serialNumber")
    : array {
    $signatures = pdfWholeFileSignatures (pdfReadFile ($path));
    if (empty ($signatures))
        throw new PdfSignException ("El PDF no está firmado digitalmente o se ha modificado " .
            "después de firmarlo.");

    if (!is_readable ($cafile))
        throw new Exception ("CA file {$cafile} is not readable");

    $reason = "";
    $validity = array ();
    foreach ($signatures as [$content, $signature, $signed]){
        $contentfile = tempnam (sys_get_temp_dir (), "mlc");
        $sigfile = tempnam (sys_get_temp_dir (), "mls");
        $signerfile = tempnam (sys_get_temp_dir (), "mlx");
        try {
            file_put_contents ($contentfile, $content);
            file_put_contents ($sigfile, $signature);
            /* -no_check_time: el extracto vale varios años, más que el
               certificado del sello con el que se firmó, y la vigencia del
               certificado de MUFACE y del nombramiento se comprueba con su
               fecha.
               -no-CApath: solo valen las autoridades de $cafile, no las del
               sistema. */
            [$code, , $err] = pdfRunCommand (["openssl", "cms", "-verify", "-binary",
                "-inform", "DER", "-in", $sigfile, "-content", $contentfile,
                "-CAfile", $cafile, "-no-CApath", "-no-CAstore", "-purpose", "any",
                "-no_check_time", "-signer", $signerfile, "-out", "/dev/null"]);
            if ($code != 0){
                $reason = trim ($err);
                continue;
            }
            $signer = openssl_x509_parse ((string) file_get_contents ($signerfile));
            $validity["from"] = $signer['validFrom'];
            $validity["to"] = $signer['validTo'];
            $validity["signed"] = $signed;
            $id = $signer === false ? null : ($signer["subject"][$signerfield] ?? null);
            if (is_string ($id) && in_array ($id, $signerids, true))
                return $validity;
            $reason = "Signer not allowed: " . ($signer === false ? "?" : $signer["name"]);
        }
        finally {
            @unlink ($contentfile);
            @unlink ($sigfile);
            @unlink ($signerfile);
        }
    }
    logMessage (LOGGER_WARN, "PDF signature rejected. {$reason}");
    throw new PdfSignException ("La firma digital del PDF no es válida o no es de {$issuer}.");
}

/**
 * Checks the signature of a training record (pdf_ca_file and pdf_signer_ids
 * in config.php).
 *
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkExtractoSignature (string $path){
    checkPdfSignature ($path, Config::PARAMS["pdf_ca_file"] ?? "certs/extracto_ca.pem",
        Config::PARAMS["pdf_signer_ids"] ?? ["S7800001E"], "la entidad que emite los extractos");
}

/**
 * Checks the signature of a MUFACE membership certificate (muface_ca_file
 * and muface_signer_ids in config.php).
 *
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkMufaceSignature (string $path){
    checkPdfSignature ($path, Config::PARAMS["muface_ca_file"] ?? "certs/muface_ca.pem",
        Config::PARAMS["muface_signer_ids"] ?? ["Q2861001B"], "MUFACE");
}

/**
 * Checks the control letter of a DNI/NIE.
 */
function pdfValidDni (string $dni): bool {
    if (!preg_match ('/^[0-9XYZ][0-9]{7}[A-Z]$/', $dni))
        return false;
    $number = (int) (strtr ($dni[0], "XYZ", "012") . substr ($dni, 1, 7));
    return "TRWAGMYFPDXBNJZSQVHLCKE"[$number % 23] == $dni[8];
}

/**
 * Returns the text of a PDF.
 *
 * @param bool $layout Keep the physical layout: needed for reading tables.
 */
function pdfText (string $path, bool $layout = false): string {
    $cmd = array_merge (["pdftotext", "-enc", "UTF-8"], $layout ? ["-layout"] : [], [$path, "-"]);
    [$code, $text, $err] = pdfRunCommand ($cmd);
    if ($code != 0)
        throw new Exception ("pdftotext failed ({$code}): " . trim ($err));
    return $text;
}

/**
 * Checks that a PDF is a training record and gets the holder's DNI/NIE from
 * it. The signature must have been checked before with checkExtractoSignature:
 * the seal that signs the training records signs other documents too, so a
 * good signature is not enough.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a training record or has no DNI.
 */
function getPdfDni (string $path): string {
    $text = preg_replace ('/\s+/u', ' ', pdfText ($path)) ?? "";

    $dni = PDF_DNI;
    preg_match_all ('/N\.I\.F\.\s*' . $dni . '/iu', $text, $header);
    /* La frase en la que el registro de formación hace constar de quién es
       el extracto: con el título solo valdría cualquier documento sellado
       que lo mencionase. */
    preg_match_all ('/con n[úu]mero de identificaci[óo]n fiscal\s*' . $dni . '\s*,?\s*figura en el ' .
        'Registro General de Formaci[óo]n Permanente del profesorado/iu', $text, $body);
    if (stripos ($text, PDF_TITLE) === false || empty ($body[1])){
        logMessage (LOGGER_WARN, "Signed PDF rejected: it is not a training record.");
        throw new PdfSignException ("El PDF no es un extracto de formación.");
    }

    $found = array_unique (array_map ('strtoupper', array_merge ($header[1], $body[1])));
    /* Tiene que aparecer en la cabecera y en la frase, y ser siempre el mismo. */
    if (empty ($header[1]) || count ($found) != 1 || !pdfValidDni (reset ($found))){
        logMessage (LOGGER_WARN, "Training record rejected: no single valid DNI in it.");
        throw new PdfSignException ("No se ha podido leer el DNI en el extracto de formación.");
    }
    return reset ($found);
}

/**
 * Returns the first day of the current school year, which starts on
 * September 1st.
 */
function pdfCourseStart (): DateTimeImmutable {
    $today = new DateTimeImmutable ("today");
    $year = (int) $today->format ("Y") - ((int) $today->format ("n") < 9 ? 1 : 0);
    return $today->setDate ($year, 9, 1);
}

/**
 * Checks that a document was signed in the current school year and not in
 * the future.
 *
 * @param array $validity What checkPdfSignature returns.
 * @param string $docname Name of the document, for the error messages.
 *
 * @throws PdfSignException if the signing date is unknown or out of range.
 */
function pdfCheckSignedThisCourse (array $validity, string $docname){
    $signed = $validity["signed"] ?? null;
    if ($signed === null)
        throw new PdfSignException ("No se ha podido leer la fecha de firma de {$docname}.");
    /* Un día de margen por si el servidor va en otra zona horaria. */
    if ($signed < pdfCourseStart () || $signed > (new DateTimeImmutable ("today"))->modify ("+1 day")){
        logMessage (LOGGER_WARN, "PDF rejected: signed on {$signed->format ('Y-m-d')}.");
        throw new PdfSignException (ucfirst ($docname) . " tiene que ser del curso actual.");
    }
}

/**
 * Checks that a document issued on "5 de octubre de 2026" is not older than
 * pdf_max_age_days (config.php).
 *
 * @throws PdfSignException if the date is wrong or the document is too old.
 */
function pdfCheckIssueDate (string $day, string $month, string $year){
    $maxage = (int) (Config::PARAMS["pdf_max_age_days"] ?? 30);
    $monthnumber = array_search (strtolower ($month), PDF_MONTHS, true);
    $date = $monthnumber === false || !checkdate ($monthnumber + 1, (int) $day, (int) $year) ? null :
        (new DateTimeImmutable ("today"))->setDate ((int) $year, $monthnumber + 1, (int) $day);
    if ($date === null)
        throw new PdfSignException ("No se ha podido leer la fecha de expedición del documento.");
    $today = new DateTimeImmutable ("today");
    /* Un día de margen por si el servidor va en otra zona horaria. */
    if ($date > $today->modify ("+1 day") || $date < $today->modify ("-{$maxage} days")){
        logMessage (LOGGER_WARN, "PDF rejected: issued on {$date->format ('Y-m-d')}.");
        throw new PdfSignException ("El documento tiene que haberse expedido en los últimos " .
            "{$maxage} días.");
    }
}

/**
 * Checks that a PDF is a MUFACE membership certificate still in force and
 * gets the holder's DNI/NIE from it. The signature must have been checked
 * before with checkMufaceSignature: the seal that signs the certificates signs
 * other documents too, so a good signature is not enough.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid certificate or has no DNI.
 */
function getMufaceDni (string $path): string {
    $text = preg_replace ('/\s+/u', ' ', pdfText ($path)) ?? "";

    /* La frase en la que MUFACE certifica quién es mutualista: con el título
       solo valdría cualquier documento sellado que lo mencionase. Los
       beneficiarios (familiares) van en una tabla aparte. */
    preg_match_all ('/Que D\/D[ªa] [^,]{1,120}, con (?:DNI|NIE|NIF) ' . PDF_DNI .
        ', figura en el colectivo de esta Mutualidad/iu', $text, $body);
    if (stripos ($text, PDF_MUFACE_TITLE) === false || empty ($body[1])){
        logMessage (LOGGER_WARN, "Signed PDF rejected: it is not a MUFACE certificate.");
        throw new PdfSignException ("El PDF no es un certificado de afiliación a MUFACE.");
    }
    $found = array_unique (array_map ('strtoupper', $body[1]));
    if (count ($found) != 1 || !pdfValidDni (reset ($found))){
        logMessage (LOGGER_WARN, "MUFACE certificate rejected: no single valid DNI in it.");
        throw new PdfSignException ("No se ha podido leer el DNI en el certificado de MUFACE.");
    }

    /* El propio certificado dice que vale 30 días desde su expedición. */
    if (!preg_match ('/expido el presente certificado en [^.]{1,60}? a (\d{1,2}) de (\p{L}+) de (\d{4})/iu',
            $text, $date))
        throw new PdfSignException ("No se ha podido leer la fecha de expedición del documento.");
    pdfCheckIssueDate ($date[1], $date[2], $date[3]);
    return reset ($found);
}

/**
 * Checks the signature of an appointment (nombramiento): it is signed with the
 * personal certificate of the public employee that signs it, so instead of
 * the signer's NIF the organizationIdentifier of the organization the
 * certificate belongs to is checked (nombramiento_ca_file and
 * nombramiento_signer_orgs in config.php).
 *
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkNombramientoSignature (string $path){
    checkPdfSignature ($path, Config::PARAMS["nombramiento_ca_file"] ?? "certs/nombramiento_ca.pem",
        Config::PARAMS["nombramiento_signer_orgs"] ?? ["VATES-S7800001E"], "la Comunidad de Madrid",
        "organizationIdentifier");
}

/**
 * Checks that a PDF is an appointment (acuerdo de nombramiento) of the
 * current school year and gets the holder's DNI/NIE from it. The signature
 * must have been checked before with checkNombramientoSignature.
 *
 * The body of the appointment is a scanned image: the only text with the data
 * is the PDF title, "<name> <DNI with a leading zero> <form> <kind> <yyyy mm dd>",
 * which is covered by the signature too.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid appointment or has no DNI.
 */
function getNombramientoDni (string $path): string {
    $text = preg_replace ('/\s+/u', ' ', pdfText ($path)) ?? "";
    [$code, $info, $err] = pdfRunCommand (["pdfinfo", "-enc", "UTF-8", $path]);
    if ($code != 0)
        throw new Exception ("pdfinfo failed ({$code}): " . trim ($err));

    if (stripos ($text, "gestiona.comunidad.madrid/csv") === false ||
            !preg_match ('/^Title:\s*(?:__tmp_img__)?\S.*?\s0?([0-9]{8}|[XYZ][0-9]{7})([A-Z]?)\s+\S+\s+\S+\s+' .
                '(\d{4}) (\d\d) (\d\d)\s*$/mu', $info, $m)){
        logMessage (LOGGER_WARN, "Signed PDF rejected: it is not an appointment.");
        throw new PdfSignException ("El PDF no es un nombramiento.");
    }
    /* El DNI viene sin letra: 047036495. */
    $dni = $m[1];
    if ($m[2] == ""){
        $number = (int) (strtr ($dni[0], "XYZ", "012") . substr ($dni, 1));
        $dni .= "TRWAGMYFPDXBNJZSQVHLCKE"[$number % 23];
    }
    else
        $dni .= $m[2];
    if (!pdfValidDni ($dni)){
        logMessage (LOGGER_WARN, "Appointment rejected: no valid DNI in it.");
        throw new PdfSignException ("No se ha podido leer el DNI en el nombramiento.");
    }

    /* El nombramiento tiene que ser del curso actual y estar en vigor hoy: ya
       ha empezado y, como termina como muy tarde el 31 de agosto, sigue en
       vigor si es de este curso. La fecha de fin que pone el acuerdo solo
       está en la imagen escaneada. */
    if (!checkdate ((int) $m[4], (int) $m[5], (int) $m[3]))
        throw new PdfSignException ("No se ha podido leer la fecha del nombramiento.");
    $date = (new DateTimeImmutable ("today"))->setDate ((int) $m[3], (int) $m[4], (int) $m[5]);
    if ($date < pdfCourseStart ()){
        logMessage (LOGGER_WARN, "Appointment rejected: dated {$date->format ('Y-m-d')}.");
        throw new PdfSignException ("El nombramiento tiene que ser del curso actual.");
    }
    if ($date > new DateTimeImmutable ("today")){
        logMessage (LOGGER_WARN, "Appointment rejected: not in force until {$date->format ('Y-m-d')}.");
        throw new PdfSignException ("El nombramiento todavía no está en vigor.");
    }
    return $dni;
}

/**
 * Checks the signature of a document signed at the school, the service record
 * (hoja de servicios) or the teaching credential: like the appointment, it is
 * signed with a personal certificate, so the organizationIdentifier of the
 * organization the certificate belongs to is checked (centro_ca_file and
 * centro_signer_orgs in config.php).
 *
 * @return array What checkPdfSignature returns.
 *
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkCentroSignature(string $path): array
{
    return checkPdfSignature(
        $path,
        Config::PARAMS["centro_ca_file"] ?? "certs/muface_ca.pem",
        Config::PARAMS["centro_signer_orgs"] ?? ["VATES-S7800001E"],
        "la Comunidad de Madrid",
        "organizationIdentifier"
    );
}

function checkHojaServiciosSignature(string $path)
{
    $validity = checkCentroSignature($path);
    pdfCheckSignedThisCourse($validity, "la Hoja de Servicios");
    $maxage = (int) (Config::PARAMS["pdf_max_age_days"] ?? 30);
    if ($validity["signed"] < (new DateTimeImmutable("today"))->modify("-{$maxage} days"))
        throw new PdfSignException("El documento tiene que haberse expedido en los últimos " .
            "{$maxage} días.");
}
function getHojaServiciosNIF(string $path): string|false
{
    $text = preg_replace('/\s+/u', ' ', pdfText($path, true)) ?? "";
    $hojaservicios = "certifica que la presente hoja de servicios está conforme con los datos " .
        "que a día de hoy pueden consultarse en las bases de datos " . 
        "de Gestión del Profesorado de la Comunidad de Madrid";
    if (
        stristr($text, $hojaservicios)
        === false
    ) {
        logMessage(LOGGER_WARN, "Signed PDF rejected: it is not a Hoja de Servicios.");
        throw new PdfSignException("El PDF no es una Hoja de Servicios.");
    }

    preg_match_all("/D\.N\.I\.\s*" . PDF_DNI . "/iu", $text, $ids);
    $found = array_unique(array_map('strtoupper', $ids[1]));
    if (count($found) != 1 || !pdfValidDni(reset($found))) {
        logMessage(LOGGER_WARN, "Hoja de Servicios rejected: no single valid NIF in it.");
        throw new PdfSignException("No se ha podido leer el NIF en la Hoja de Servicios.");
    }
    return reset($found);
}

function checkCredencialSignature(string $path)
{
    $validity = checkCentroSignature($path);
    pdfCheckSignedThisCourse($validity, "la Credencial");
}

function getCredencialNIF(string $path): string|false
{
    $text = preg_replace('/\s+/u', ' ', pdfText($path, true)) ?? "";
    if (
        stristr($text, "Este documento acredita a su poseedor su intención de asumir funciones docentes")
        === false
    ) {
        logMessage(LOGGER_WARN, "Signed PDF rejected: it is not a Credencial.");
        throw new PdfSignException("El PDF no es una Credencial.");
    }

    preg_match_all("/con número de documento\s*" . PDF_DNI . "/iu", $text, $ids);
    $found = array_unique(array_map('strtoupper', $ids[1]));
    if (count($found) != 1 || !pdfValidDni(reset($found))) {
        logMessage(LOGGER_WARN, "Hoja de Servicios rejected: no single valid NIF in it.");
        throw new PdfSignException("No se ha podido leer el NIF en la Hoja de Servicios.");
    }
    return reset($found);
}