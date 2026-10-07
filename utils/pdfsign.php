<?php
/**
 * Functions for checking the PDFs asked for participating and getting the
 * holder's DNI from them: the digitally signed training record (extracto de
 * formación) and either the digitally signed MUFACE membership certificate
 * (Clases Pasivas) or the Social Security work history report, informe de
 * vida laboral (the rest: career civil servants who joined from 2011 on and
 * interim ones).
 *
 * Needs the openssl and pdftotext (poppler-utils) commands.
 */
require_once 'include/config.php';
require_once 'utils/logger.php';

/* Límite del PDF subido: estos documentos ocupan unos cientos de KB. */
const PDF_MAX_SIZE = 5 * 1024 * 1024;
const PDF_CMD_TIMEOUT = 20;
const PDF_TITLE = "EXTRACTO INDIVIDUAL DE RECONOCIMIENTO DE ACTIVIDADES";
const PDF_MUFACE_TITLE = "CERTIFICADO DE AFILIACIÓN A MUFACE";
const PDF_VIDA_LABORAL_TITLE = "INFORME DE VIDA LABORAL";
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
 * Each one is [signed bytes, DER signature].
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
        /* Los ceros de relleno del final no molestan al analizar el DER. */
        $signatures[] = [substr ($pdf, 0, $len1) . substr ($pdf, $start2), hex2bin ($hex)];
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
 * @param array $signerids serialNumber (NIF) of the certificates allowed to sign.
 * @param string $issuer Who issues the document, for the error message.
 *
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkPdfSignature (string $path, string $cafile, array $signerids, string $issuer){
    $signatures = pdfWholeFileSignatures (pdfReadFile ($path));
    if (empty ($signatures))
        throw new PdfSignException ("El PDF no está firmado digitalmente o se ha modificado " .
            "después de firmarlo.");

    if (!is_readable ($cafile))
        throw new Exception ("CA file {$cafile} is not readable");

    $reason = "";
    foreach ($signatures as [$content, $signature]){
        $contentfile = tempnam (sys_get_temp_dir (), "mlc");
        $sigfile = tempnam (sys_get_temp_dir (), "mls");
        $signerfile = tempnam (sys_get_temp_dir (), "mlx");
        try {
            file_put_contents ($contentfile, $content);
            file_put_contents ($sigfile, $signature);
            /* -no_check_time: el extracto vale varios años, más que el
               certificado del sello con el que se firmó, y la vigencia del
               certificado de MUFACE se comprueba con su fecha de expedición.
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
            $id = $signer === false ? null : ($signer["subject"]["serialNumber"] ?? null);
            if (is_string ($id) && in_array ($id, $signerids, true))
                return;
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
 * Returns the rows of the table of a work history report (text with layout).
 * Each one is ["employer" => account code and name, "active" => bool].
 */
function pdfVidaLaboralRows (string $text): array {
    $rows = [];
    $current = null;
    $date = '\d\d\.\d\d\.\d{4}';
    foreach (preg_split ('/\R/u', $text) as $line){
        /* RÉGIMEN, cuenta de cotización, EMPRESA, FECHA ALTA, FECHA DE EFECTO
           y FECHA DE BAJA, que es --- mientras se sigue de alta. */
        if (preg_match ('/^\s*\S.*?\s(\d{9,12}|-{5,})\s+(\S.*?)\s+' . $date . '\s+' . $date .
                '\s+(' . $date . '|-{3})\s/u', $line, $m)){
            $rows[] = ["employer" => $m[1] . " " . $m[2], "active" => $m[3] == "---"];
            $current = count ($rows) - 1;
        }
        /* El nombre de la empresa puede seguir en la línea de debajo. */
        else if ($current !== null && trim ($line) != "" && !preg_match ('/' . $date . '/', $line))
            $rows[$current]["employer"] .= " " . trim ($line);
        else
            $current = null;
    }
    return $rows;
}

/**
 * Checks that a PDF is a recent work history report (informe de vida
 * laboral) in which the holder is currently working for one of the public
 * employers in pdf_public_employers (config.php), and gets the holder's
 * DNI/NIE from it.
 *
 * This report is not digitally signed: it can only be checked at the Social
 * Security site with its CEA code, so its contents are trusted as they come.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid report or has no DNI.
 */
function getVidaLaboralDni (string $path): string {
    pdfReadFile ($path);
    $layout = pdfText ($path, true);
    $text = preg_replace ('/\s+/u', ' ', $layout) ?? "";

    if (stripos ($text, PDF_VIDA_LABORAL_TITLE) === false ||
            stripos ($text, "Tesorería General de la Seguridad Social") === false ||
            !preg_match ('/al día (\d{1,2}) de (\p{L}+) de (\d{4})/iu', $text, $date)){
        logMessage (LOGGER_WARN, "PDF rejected: it is not a work history report.");
        throw new PdfSignException ("El PDF no es un informe de vida laboral.");
    }
    /* El DNI viene con un cero delante: 012345678Z. */
    preg_match_all ('/(?:D\.N\.I\.|N\.I\.E\.)\s*0?' . PDF_DNI . '/iu', $text, $ids);
    $found = array_unique (array_map ('strtoupper', $ids[1]));
    if (count ($found) != 1 || !pdfValidDni (reset ($found))){
        logMessage (LOGGER_WARN, "Work history report rejected: no single valid DNI in it.");
        throw new PdfSignException ("No se ha podido leer el DNI en el informe de vida laboral.");
    }
    /* Una situación de alta solo dice algo si el informe es reciente. */
    pdfCheckIssueDate ($date[1], $date[2], $date[3]);

    $employers = Config::PARAMS["pdf_public_employers"] ??
        ["COMUNIDAD DE MADRID CONSEJERIA DE EDUCACI", "COMUNIDAD MADRID A.TERRITORIALES"];
    foreach (pdfVidaLaboralRows ($layout) as $row){
        if (!$row["active"])
            continue;
        $employer = strtoupper (preg_replace ('/\s+/u', ' ', $row["employer"]) ?? "");
        foreach ($employers as $name){
            if (trim ($name) != "" && strpos ($employer, strtoupper (trim ($name))) !== false)
                return reset ($found);
        }
    }
    logMessage (LOGGER_WARN, "Work history report rejected: not working for a public employer.");
    throw new PdfSignException ("En el informe de vida laboral no consta una situación de alta " .
        "en vigor en la enseñanza pública.");
}
