<?php
/**
 * Functions for checking the PDF asked for registering: the self-declaration
 * as a teacher of the public education of the Comunidad de Madrid
 * (docs/modelo_declaracion.pdf). It is a PDF form in which the participant
 * fills in their DNI/NIE and their educamadrid email address, and that they
 * sign with their own digital certificate (FNMT, DNIe...).
 *
 * The signature must cover the whole file and be made with a personal
 * certificate of a trusted authority, and the DNI of the certificate must be
 * the one written in the form.
 *
 * Needs the openssl, pdftotext (poppler-utils) and qpdf commands.
 */
require_once 'include/config.php';
require_once 'utils/logger.php';
require_once 'utils/certrevocation.php';

/* Límite del PDF subido: la declaración firmada ocupa unos cientos de KB. */
const PDF_MAX_SIZE = 5 * 1024 * 1024;
const PDF_CMD_TIMEOUT = 20;
/* Título del modelo de declaración, sin tildes: con la firma solo valdría
   cualquier documento firmado por la persona. */
const PDF_DECLARACION_TITLE = "autodeclaracion como docente en la educacion publica de la comunidad de madrid";
/* Nombres de los campos del formulario del modelo. */
const PDF_FIELD_DNI = "DNI";
const PDF_FIELD_EMAIL = "email";

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
           añadida después de firmar, con otro DNI o correo en el
           formulario) se podría cambiar el contenido sin romper la firma. */
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
 * file and is not broken, and that the signer's certificate is issued by one
 * of the trusted authorities and is in force today.
 *
 * @param string $path PDF file.
 * @param string $cafile PEM file with the trusted certification authorities.
 *
 * @return OpenSSLCertificate The signer's certificate.
 * @throws PdfSignException if the file is not a properly signed PDF.
 */
function pdfSignerCertificate (string $path, string $cafile): OpenSSLCertificate {
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
            /* Sin -no_check_time: el certificado de la persona tiene que
               estar en vigor hoy, que es cuando se registra.
               -no-CApath: solo valen las autoridades de $cafile, no las del
               sistema. */
            [$code, , $err] = pdfRunCommand (["openssl", "cms", "-verify", "-binary",
                "-inform", "DER", "-in", $sigfile, "-content", $contentfile,
                "-CAfile", $cafile, "-no-CApath", "-no-CAstore", "-purpose", "any",
                "-signer", $signerfile, "-out", "/dev/null"]);
            if ($code != 0){
                $reason = trim ($err);
                continue;
            }
            $cert = openssl_x509_read ((string) file_get_contents ($signerfile));
            if ($cert !== false)
                return $cert;
            $reason = "Signer certificate can't be read.";
        }
        finally {
            @unlink ($contentfile);
            @unlink ($sigfile);
            @unlink ($signerfile);
        }
    }
    logMessage (LOGGER_WARN, "PDF signature rejected. {$reason}");
    throw new PdfSignException ("La firma digital del PDF no es válida, el certificado está " .
        "caducado o no es de una autoridad de certificación admitida.");
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
 * Gets the holder's DNI/NIE from the subject of a personal certificate: the
 * serialNumber is "IDCES-12345678Z" in the FNMT certificates and "12345678Z"
 * in the DNIe and the older ones.
 *
 * @param array $info What openssl_x509_parse returns.
 *
 * @return string|null The DNI/NIE in upper case, or null if there isn't a
 *     single valid one (electronic seals, component certificates...).
 */
function certSubjectDni (array $info): ?string {
    $found = [];
    foreach ((array) ($info["subject"]["serialNumber"] ?? []) as $serial){
        if (preg_match ('/^(?:IDCES-)?([0-9XYZ][0-9]{7}[A-Z])$/i', trim ($serial), $m) &&
                pdfValidDni (strtoupper ($m[1])))
            $found[] = strtoupper ($m[1]);
    }
    $found = array_unique ($found);
    return count ($found) == 1 ? $found[0] : null;
}

/**
 * Returns the text of a PDF in lower case, without accents and with the
 * whitespace collapsed, for looking for sentences in it: pdftotext may give
 * the accents as combining characters.
 */
function pdfText (string $path): string {
    [$code, $text, $err] = pdfRunCommand (["pdftotext", "-enc", "UTF-8", $path, "-"]);
    if ($code != 0)
        throw new Exception ("pdftotext failed ({$code}): " . trim ($err));
    $text = preg_replace (['/\p{Mn}+/u', '/\s+/u'], ['', ' '], $text) ?? "";
    return strtolower (strtr ($text, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u",
        "Á" => "a", "É" => "e", "Í" => "i", "Ó" => "o", "Ú" => "u", "ı" => "i"]));
}

/**
 * Returns the values of the text fields of a PDF form, by full name.
 */
function pdfFormFields (string $path): array {
    [$code, $out, $err] = pdfRunCommand (["qpdf", "--json", "--json-key=acroform", $path]);
    /* 3: hay avisos, pero se ha podido leer. */
    if ($code != 0 && $code != 3)
        throw new PdfSignException ("No se ha podido leer el formulario del PDF.");
    $json = json_decode ($out, true);
    $fields = [];
    foreach ($json["acroform"]["fields"] ?? [] as $field){
        $name = $field["fullname"] ?? null;
        $value = $field["value"] ?? null;
        if (!is_string ($name) || !is_string ($value))
            continue;
        /* qpdf pone delante del texto "u:" (Unicode) o "b:" (bytes en hexadecimal). */
        if (str_starts_with ($value, "u:"))
            $value = substr ($value, 2);
        else if (str_starts_with ($value, "b:") && ctype_xdigit (substr ($value, 2)))
            $value = (string) hex2bin (substr ($value, 2));
        else
            continue;
        $fields[$name] = $value;
    }
    return $fields;
}

/**
 * Checks the signed self-declaration and gets the DNI/NIE and the email
 * address of the participant from it:
 * - it is signed (the whole file) with a certificate of a trusted authority
 *   (declaracion_ca_file in config.php) in force and not revoked
 *   (declaracion_revocation),
 * - it is the declaration model,
 * - the DNI of the form is the one of the signer's certificate, and
 * - the email address of the form is of an educamadrid domain
 *   (declaracion_email_domains).
 *
 * @param string $path PDF file.
 *
 * @return array ["dni" => DNI/NIE in upper case, "email" => address in lower case].
 *
 * @throws PdfSignException if the PDF is not valid.
 */
function checkDeclaracion (string $path): array {
    $cafile = Config::PARAMS["declaracion_ca_file"] ?? "certs/declaracion_ca.pem";
    $cert = pdfSignerCertificate ($path, $cafile);
    $info = openssl_x509_parse ($cert);
    $signerdni = $info === false ? null : certSubjectDni ($info);
    if ($signerdni === null){
        logMessage (LOGGER_WARN, "Declaration rejected: the signer has no single valid DNI.");
        throw new PdfSignException ("La declaración tiene que estar firmada con un certificado " .
            "de persona física.");
    }
    if (Config::PARAMS["declaracion_revocation"] ?? true){
        try {
            certCheckRevocation ($cert, $cafile);
        } catch (CertRevocationException $e) {
            throw new PdfSignException ($e->getMessage ());
        }
    }

    if (!str_contains (pdfText ($path), PDF_DECLARACION_TITLE)){
        logMessage (LOGGER_WARN, "Signed PDF rejected: it is not the declaration.");
        throw new PdfSignException ("El PDF no es el modelo de declaración.");
    }

    $fields = pdfFormFields ($path);
    /* Se admite el DNI escrito con espacios, puntos o guiones. */
    $dni = strtoupper (preg_replace ('/[\s.\-]+/u', '', $fields[PDF_FIELD_DNI] ?? "") ?? "");
    if (!pdfValidDni ($dni)){
        logMessage (LOGGER_WARN, "Declaration rejected: no valid DNI in the form.");
        throw new PdfSignException ("El número de documento (NIF o NIE) de la declaración no " .
            "es válido.");
    }
    if (!hash_equals ($signerdni, $dni)){
        logMessage (LOGGER_WARN, "Declaration rejected: the DNI is not the one of the signer.");
        throw new PdfSignException ("El número de documento de la declaración no coincide con " .
            "el del certificado con el que está firmada.");
    }

    $email = strtolower (trim ($fields[PDF_FIELD_EMAIL] ?? ""));
    if ($email == "" || filter_var ($email, FILTER_VALIDATE_EMAIL) === false){
        logMessage (LOGGER_WARN, "Declaration rejected: no valid email address in the form.");
        throw new PdfSignException ("La dirección de correo de la declaración no es válida.");
    }
    $domains = array_map ('strtolower', (array) (Config::PARAMS["declaracion_email_domains"] ??
        ["educa.madrid.org"]));
    $domain = substr (strrchr ($email, "@"), 1);
    if (!empty ($domains) && !in_array ($domain, $domains, true)){
        logMessage (LOGGER_WARN, "Declaration rejected: email address of domain {$domain}.");
        throw new PdfSignException ("La dirección de correo de la declaración tiene que ser de " .
            implode (" o ", $domains) . ".");
    }
    return ["dni" => $dni, "email" => $email];
}
