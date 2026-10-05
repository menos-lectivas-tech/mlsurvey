<?php
/**
 * Functions for checking the digital signature of the training record PDF
 * (extracto de formación) and getting the holder's DNI from it.
 *
 * Needs the openssl and pdftotext (poppler-utils) commands.
 */
require_once 'include/config.php';
require_once 'utils/logger.php';

/* Límite del PDF subido: un extracto ocupa unos cientos de KB. */
const PDF_MAX_SIZE = 5 * 1024 * 1024;
const PDF_CMD_TIMEOUT = 20;
const PDF_TITLE = "EXTRACTO INDIVIDUAL DE RECONOCIMIENTO DE ACTIVIDADES";

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
 * Checks that a PDF is digitally signed, that the signature covers the whole
 * file and is not broken, and that the signer is one of the trusted ones
 * (pdf_ca_file and pdf_signer_ids in config.php).
 *
 * @param string $path PDF file.
 *
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function checkPdfSignature (string $path){
    $size = filesize ($path);
    if ($size === false || $size == 0 || $size > PDF_MAX_SIZE)
        throw new PdfSignException ("El fichero está vacío o es demasiado grande.");
    $pdf = file_get_contents ($path);
    if ($pdf === false || strncmp ($pdf, "%PDF-", 5) != 0)
        throw new PdfSignException ("El fichero no es un PDF.");

    $signatures = pdfWholeFileSignatures ($pdf);
    if (empty ($signatures))
        throw new PdfSignException ("El PDF no está firmado digitalmente o se ha modificado " .
            "después de firmarlo.");

    $cafile = Config::PARAMS["pdf_ca_file"] ?? "certs/extracto_ca.pem";
    $signerids = Config::PARAMS["pdf_signer_ids"] ?? ["S7800001E"];
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
               certificado del sello con el que se firmó. -no-CApath: solo
               valen las autoridades de pdf_ca_file, no las del sistema. */
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
    throw new PdfSignException ("La firma digital del PDF no es válida o no es de la " .
        "entidad que emite los extractos.");
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
 * Checks that a PDF is a training record and gets the holder's DNI/NIE from
 * it. The signature must have been checked before with checkPdfSignature:
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
    [$code, $text, $err] = pdfRunCommand (["pdftotext", "-enc", "UTF-8", $path, "-"]);
    if ($code != 0)
        throw new Exception ("pdftotext failed ({$code}): " . trim ($err));
    $text = preg_replace ('/\s+/u', ' ', $text) ?? "";

    $dni = '([0-9XYZ][0-9]{7}[A-Z])\b';
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
