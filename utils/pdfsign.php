<?php
/**
 * Functions for checking the PDFs asked for participating and getting the
 * holder's DNI from them: the appointment, nombramiento (interim ones), the
 * service record, the teaching credential or the payslip (nómina).
 *
 * The appointment, the service record and the credential are sometimes signed
 * digitally and sometimes by hand, so their signature is not checked: only
 * their content, with the dates read from the text. The payslip is not signed.
 *
 * The documents can be text or a photo or scan of the paper inside the PDF:
 * if the text does not say what the document is, the pages are read with OCR.
 *
 * Needs the pdftotext, pdfinfo and pdftoppm (poppler-utils) commands, and
 * tesseract with the Spanish data (tesseract-ocr-spa) for the documents that
 * are images.
 */
require_once 'include/config.php';
require_once 'utils/logger.php';

/* Límite del PDF subido: estos documentos ocupan unos cientos de KB. */
const PDF_MAX_SIZE = 5 * 1024 * 1024;
const PDF_CMD_TIMEOUT = 20;
/* El OCR solo mira las primeras páginas: los datos están al principio y cada
   página tarda unos segundos. */
const PDF_OCR_PAGES = 3;
const PDF_OCR_DPI = 300;
/* Los documentos no se leen por su formato exacto, que cambia de un modelo a
   otro, entre la versión en texto y la escaneada y con los errores del OCR,
   sino por palabras clave, etiquetas y valores cercanos. Todas las
   expresiones se aplican al texto normalizado (pdfNormalize): en mayúsculas,
   sin tildes y con los espacios colapsados. */
const PDF_NOMBRAMIENTO_MARK = '/NOMBRA(?:MIENTO|DO|DA|R)\b.*\bPOSESION\b|\bPOSESION\b.*\bNOMBRA(?:MIENTO|DO|DA|R)\b/';
const PDF_DNI = '([0-9XYZ][0-9]{7}[A-Z])\b';
const PDF_MONTHS = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio",
    "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
/* Un DNI/NIE suelto: con o sin puntos de miles, con la letra pegada o tras
   un espacio o un guion, o sin ella. No vale una cifra de un número más
   largo (el N.R.P., la afiliación a la Seguridad Social, un importe). */
const PDF_DNI_LOOSE = '(?<![0-9A-Z\/,.])([XYZ]?(?:[0-9]{1,3}(?:\.[0-9]{3}){2}|[0-9]{5,9}))(?![0-9]|[,.\/][0-9])' .
    '(?:[ -]?([A-Z])(?![A-Z])|(?![A-Z]))';
/* Etiquetas del documento de identidad. El OCR puede leer la I como L o 1. */
const PDF_ID_LABELS = '/(?<![A-Z])(?:D\.? ?N\.? ?[IL1]|N\.? ?[IL1]\.? ?[FE]|(?:N[OUº°]?(?:MERO)?\.? DE )?DOCUMENTO ' .
    '(?:NACIONAL )?DE IDENTIDAD|N[OUº°]?(?:MERO)?\.? DE DOCUMENTO|IDENTIFICACION)(?![A-Z])/';

/**
 * The message is meant to be shown to the participant.
 */
class PdfSignException extends Exception {}

/**
 * Runs a command without a shell.
 *
 * @param array $cmd Command and arguments.
 * @param string|null $input What to write to its stdin, or null for nothing.
 *
 * @return array [exit code, stdout, stderr]
 */
function pdfRunCommand (array $cmd, ?string $input = null): array {
    /* max_execution_time no cuenta el tiempo de un proceso externo. */
    $cmd = array_merge (["timeout", (string) PDF_CMD_TIMEOUT], $cmd);
    /* stderr va a un fichero: leyendo dos tuberías en serie el proceso se
       puede quedar bloqueado con la segunda llena. */
    $errfile = tmpfile ();
    $stdin = $input === null ? ["file", "/dev/null", "r"] : ["pipe", "r"];
    $proc = proc_open ($cmd, [0 => $stdin, 1 => ["pipe", "w"], 2 => $errfile], $pipes);
    if ($proc === false)
        throw new Exception ("Can't run {$cmd[2]}");
    if ($input !== null){
        /* Los comandos a los que se pasa algo (tesseract) leen toda la
           entrada antes de escribir nada, así que no se bloquea. Si el
           comando no existe o falla, se ve en el código de salida. */
        @fwrite ($pipes[0], $input);
        fclose ($pipes[0]);
    }
    $out = stream_get_contents ($pipes[1]);
    fclose ($pipes[1]);
    $code = proc_close ($proc);
    rewind ($errfile);
    $err = stream_get_contents ($errfile);
    fclose ($errfile);
    return [$code, $out, $err];
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
 * Checks the control letter of a DNI/NIE.
 */
function pdfValidDni (string $dni): bool {
    if (!preg_match ('/^[0-9XYZ][0-9]{7}[A-Z]$/', $dni))
        return false;
    $number = (int) (strtr ($dni[0], "XYZ", "012") . substr ($dni, 1, 7));
    return "TRWAGMYFPDXBNJZSQVHLCKE"[$number % 23] == $dni[8];
}

/**
 * Adds its control letter to a DNI/NIE written without it.
 */
function pdfDniWithLetter (string $digits): string {
    $number = (int) (strtr ($digits[0], "XYZ", "012") . substr ($digits, 1));
    return $digits . "TRWAGMYFPDXBNJZSQVHLCKE"[$number % 23];
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
 * Returns the text of the first pages of a PDF read with OCR, for the
 * documents that are a photo or a scan. The images go from pdftoppm to
 * tesseract through memory: nothing is written to disk.
 *
 * @return string The text, or "" if it could not be read (logged).
 */
function pdfOcrText (string $path): string {
    [$code, $info, $err] = pdfRunCommand (["pdfinfo", $path]);
    if ($code != 0 || !preg_match ('/^Pages:\s*(\d+)/m', $info, $m)){
        logMessage (LOGGER_ERROR, "pdfinfo failed ({$code}): " . trim ($err));
        return "";
    }
    $text = "";
    for ($page = 1; $page <= min ((int) $m[1], PDF_OCR_PAGES); $page++){
        [$code, $image, $err] = pdfRunCommand (["pdftoppm", "-f", (string) $page, "-l", (string) $page,
            "-singlefile", "-r", (string) PDF_OCR_DPI, "-gray", "-png", $path]);
        if ($code != 0 || $image === ""){
            logMessage (LOGGER_ERROR, "pdftoppm failed ({$code}): " . trim ($err));
            return "";
        }
        [$code, $out, $err] = pdfRunCommand (["tesseract", "stdin", "stdout", "-l", "spa"], $image);
        if ($code != 0){
            /* 127: no está instalado. */
            logMessage (LOGGER_ERROR, "tesseract failed ({$code}): " . trim ($err));
            return "";
        }
        $text .= $out . "\n";
    }
    return $text;
}

/**
 * Normalizes a text for matching it without depending on its format: upper
 * case, no accents and the whitespace collapsed into single spaces.
 */
function pdfNormalize (string $text): string {
    $text = strtr ($text, [
        "á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n", "ç" => "c",
        "à" => "a", "è" => "e", "ì" => "i", "ò" => "o", "ù" => "u",
        "Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U", "Ü" => "U", "Ñ" => "N", "Ç" => "C",
        "À" => "A", "È" => "E", "Ì" => "I", "Ò" => "O", "Ù" => "U",
        /* Comillas, guiones y espacios tipográficos. */
        "\u{2010}" => "-", "\u{2011}" => "-", "\u{2012}" => "-", "\u{2013}" => "-", "\u{2014}" => "-",
        "\u{00A0}" => " ", "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
    ]);
    return trim (strtoupper (preg_replace ('/\s+/u', ' ', $text) ?? $text));
}

/**
 * Returns the normalized text (pdfNormalize) of a document: the first one
 * where $mark is found of pdftotext's, with and without the physical layout
 * (each one splits the tables and columns in its own way), and OCR's, for
 * the documents that are an image. If $mark is in none, the first one.
 *
 * @param string $mark Regular expression that only the text of the expected
 *     document matches.
 * @param bool $layout Try first the text with the physical layout, the
 *     better one for reading tables.
 */
function pdfDocumentText (string $path, string $mark, bool $layout = false): string {
    $first = null;
    foreach ([$layout, !$layout] as $mode){
        $text = pdfNormalize (pdfText ($path, $mode));
        $first ??= $text;
        if (preg_match ($mark, $text))
            return $text;
    }
    $ocr = pdfNormalize (pdfOcrText ($path));
    return preg_match ($mark, $ocr) ? $ocr : $first;
}

/**
 * Number of a month from its name, in Spanish, written in full or not
 * ("SEPT", "SETIEMBRE"), in any case and with or without accents.
 *
 * @return int|null 1 to 12, or null if it is not a month.
 */
function pdfMonthNumber (string $name): ?int {
    $name = strtolower (pdfNormalize ($name));
    if (strlen ($name) < 3)
        return null;
    $prefix = substr ($name, 0, 3) === "set" ? "sep" : substr ($name, 0, 3);
    /* Abreviado ("sept"), o entero con alguna letra mal leída por el OCR. */
    foreach (PDF_MONTHS as $i => $month)
        if (substr ($month, 0, 3) === $prefix && (strlen ($name) <= 4 ||
                levenshtein ($name, $month) <= 2 || ($i == 8 && $name === "setiembre")))
            return $i + 1;
    return null;
}

/**
 * Valid DNI/NIE from the parts of a PDF_DNI_LOOSE match.
 *
 * @param string $number The number, maybe with dots, the leading zeros
 *     dropped or one more.
 * @param string $letter The control letter, or "" if there is not one.
 * @param bool $noletter Accept a number without its letter, adding it.
 *
 * @return string|null The DNI/NIE with its letter, or null if not valid.
 */
function pdfDniFromParts (string $number, string $letter, bool $noletter): ?string {
    $number = str_replace (".", "", $number);
    $prefix = ctype_digit ($number[0]) ? "" : $number[0];
    $digits = ltrim (substr ($number, strlen ($prefix)), "0");
    $length = $prefix === "" ? 8 : 7;
    if (strlen ($digits) > $length || strlen ($digits) < $length - 3)
        return null;
    $digits = str_pad ($digits, $length, "0", STR_PAD_LEFT);
    if ($letter !== ""){
        $dni = $prefix . $digits . $letter;
        if (pdfValidDni ($dni))
            return $dni;
    }
    /* Sin letra solo con todas sus cifras: 050121065, 50121065. */
    if ($noletter && strlen (ltrim ($number, "0XYZ")) >= $length - 1 &&
            strlen ($number) - strlen ($prefix) >= $length)
        return pdfDniWithLetter ($prefix . $digits);
    return null;
}

/**
 * Valid DNIs/NIEs in a text, in order.
 *
 * @param bool $noletter Accept numbers without their letter too.
 *
 * @return array The DNIs/NIEs, with their letter.
 */
function pdfDnisIn (string $text, bool $noletter = false): array {
    preg_match_all ('/' . PDF_DNI_LOOSE . '/', $text, $matches, PREG_SET_ORDER);
    $dnis = [];
    foreach ($matches as $m){
        $dni = pdfDniFromParts ($m[1], $m[2] ?? "", $noletter);
        if ($dni !== null)
            $dnis[] = $dni;
    }
    return $dnis;
}

/**
 * The holder's DNI/NIE in a document: the first valid one after the labels
 * of the first tier that has some, and if there is none after any label, the
 * only valid one with its letter in the whole text. The label and its value
 * may be apart, with other headings or values between them, as it happens
 * in the tables.
 *
 * @param string $text Normalized text (pdfNormalize).
 * @param array $labels Regular expressions of the labels, from the most to
 *     the least specific: each one is a tier. The generic ones of an ID
 *     (PDF_ID_LABELS) are always tried after them.
 * @param int $window How far after a label its value can be.
 *
 * @return string|null The DNI/NIE, or null if there is none or there are
 *     several different ones in the tier where they are found.
 */
function pdfFindDni (string $text, array $labels = [], int $window = 150): ?string {
    foreach (array_merge ($labels, [PDF_ID_LABELS]) as $label){
        preg_match_all ($label, $text, $matches, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[0] as [$match, $offset]){
            $dnis = pdfDnisIn (substr ($text, $offset + strlen ($match), $window), true);
            if (!empty ($dnis))
                $found[] = $dnis[0];
        }
        $found = array_unique ($found);
        if (count ($found) == 1)
            return reset ($found);
        if (count ($found) > 1){
            logMessage (LOGGER_WARN, "PDF: several DNIs after {$label}.");
            return null;
        }
    }
    $found = array_unique (pdfDnisIn ($text));
    return count ($found) == 1 ? reset ($found) : null;
}

/**
 * Dates in a text, in order: numeric ("1/9/2026", "01-09-2026",
 * "01.09.2026") and, unless $numeric, written ("1 de septiembre de 2026",
 * "1º de sept. del 2026", "1 septiembre 2026").
 *
 * @return array [[DateTimeImmutable, offset, length], ...], only the valid
 *     dates.
 */
function pdfFindDates (string $text, bool $numeric = false): array {
    $today = new DateTimeImmutable ("today");
    $dates = [];
    preg_match_all ('/(?<![0-9])([0-9]{1,2}) ?([\/.-]) ?([0-9]{1,2}) ?\2 ?([0-9]{4})(?![0-9])/', $text, $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $m)
        if (checkdate ((int) $m[3][0], (int) $m[1][0], (int) $m[4][0]))
            $dates[] = [$today->setDate ((int) $m[4][0], (int) $m[3][0], (int) $m[1][0]), $m[0][1],
                strlen ($m[0][0])];
    if (!$numeric){
        preg_match_all ('/(?<![0-9])([0-9]{1,2})(?:º|°|O)? (?:DE )?([A-Z]{3,10})\.?,? (?:DE |DEL )?([0-9]{4})' .
            '(?![0-9])/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $m){
            $month = pdfMonthNumber ($m[2][0]);
            if ($month !== null && checkdate ($month, (int) $m[1][0], (int) $m[3][0]))
                $dates[] = [$today->setDate ((int) $m[3][0], $month, (int) $m[1][0]), $m[0][1],
                    strlen ($m[0][0])];
        }
        usort ($dates, fn ($a, $b) => $a[1] <=> $b[1]);
    }
    return $dates;
}

/**
 * The dates that follow some labels: for each label the first date in the
 * $window characters after it.
 *
 * @param string $text Normalized text (pdfNormalize).
 * @param array $labels Regular expressions of the labels, from the most to
 *     the least specific: each one is a tier.
 *
 * @return array The dates (DateTimeImmutable) of the first tier that has
 *     some, or [] if none has.
 */
function pdfLabeledDates (string $text, array $labels, int $window = 120): array {
    foreach ($labels as $label){
        preg_match_all ($label, $text, $matches, PREG_OFFSET_CAPTURE);
        $found = [];
        foreach ($matches[0] as [$match, $offset]){
            $dates = pdfFindDates (substr ($text, $offset + strlen ($match), $window));
            if (!empty ($dates))
                $found[] = $dates[0][0];
        }
        if (!empty ($found))
            return $found;
    }
    return [];
}

/* Las etiquetas de la fecha de expedición o firma: "En MADRID, a 2 de
   septiembre de 2026", "Se expide la presente en LEGANES a ...", "Madrid, 2
   de septiembre de 2026", "Fecha: 02/09/2026". El sitio puede ser cualquier
   cosa (puntos, comas, cifras: "C. VILLALBA", "IES Nº 1, Madrid"). */
const PDF_ISSUE_LABELS = [
    '/\bEXPID[EOA]\b|\bEXPEDIDA\b/',
    '/\bEN [^0-9]{1,80}?,? A(?= [0-9])/',
    '/\b(?:MADRID|FECHA(?: DE EXPEDICION| DE EMISION)?:?)(?=[ ,:]{1,3}[0-9])/',
];


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
 * Checks that a document is dated in the current school year and not in the
 * future.
 *
 * @param DateTimeImmutable $date Date of the document.
 * @param string $docname Name of the document, for the error messages.
 *
 * @throws PdfSignException if the date is out of range.
 */
function pdfCheckThisCourse (DateTimeImmutable $date, string $docname){
    /* Un día de margen por si el servidor va en otra zona horaria. */
    if ($date < pdfCourseStart () || $date > (new DateTimeImmutable ("today"))->modify ("+1 day")){
        logMessage (LOGGER_WARN, "PDF rejected: dated {$date->format ('Y-m-d')}.");
        throw new PdfSignException (ucfirst ($docname) . " tiene que ser del curso actual.");
    }
}

/**
 * Checks that a document issued on $date is not older than pdf_max_age_days
 * (config.php).
 *
 * @throws PdfSignException if the document is too old or in the future.
 */
function pdfCheckIssueDate (DateTimeImmutable $date){
    $maxage = (int) (Config::PARAMS["pdf_max_age_days"] ?? 30);
    $today = new DateTimeImmutable ("today");
    /* Un día de margen por si el servidor va en otra zona horaria. */
    if ($date > $today->modify ("+1 day") || $date < $today->modify ("-{$maxage} days")){
        logMessage (LOGGER_WARN, "PDF rejected: issued on {$date->format ('Y-m-d')}.");
        throw new PdfSignException ("El documento tiene que haberse expedido en los últimos " .
            "{$maxage} días.");
    }
}

/**
 * The issue date of a document (PDF_ISSUE_LABELS): the latest one if it
 * says several.
 *
 * @param string $text Normalized text (pdfNormalize).
 * @param string $docname Name of the document, for the error message.
 *
 * @throws PdfSignException if it has no issue date.
 */
function pdfIssueDate (string $text, string $docname): DateTimeImmutable {
    $dates = pdfLabeledDates ($text, PDF_ISSUE_LABELS);
    if (empty ($dates))
        throw new PdfSignException ("No se ha podido leer la fecha de {$docname}.");
    return max ($dates);
}

/**
 * Checks that a PDF is an appointment (acuerdo de nombramiento) of the
 * current school year and gets the holder's DNI/NIE from it. Its signature
 * is not checked: it may be signed digitally or by hand.
 *
 * It may be the appointment of an interim teacher or to a post (director/a,
 * jefe/a de estudios, secretario/a...) with its taking of office, of any
 * model:
 * - Scanned: the body is an image and the text with the data may be only the
 *   PDF title, "<name> <DNI with a leading zero> <form> <kind> <yyyy mm dd>".
 * - Text, or read with OCR: it talks about an appointment (nombramiento,
 *   nombrar) and a taking of office (posesión), has the holder's DNI and the
 *   date it takes effect, after a label ("FECHA DE NOMBRAMIENTO",
 *   "EFECTOS ADMINISTRATIVOS", "TOMA DE POSESIÓN"...) or as its date.
 *
 * Posts are taken from July 1st on, so for them the course starts then.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid appointment or has no DNI.
 */
function getNombramientoDni (string $path): string {
    [$code, $info, $err] = pdfRunCommand (["pdfinfo", "-enc", "UTF-8", $path]);
    if ($code != 0)
        throw new Exception ("pdfinfo failed ({$code}): " . trim ($err));

    $start = pdfCourseStart ();
    $data = pdfNombramientoFromTitle ($info);
    if ($data === null){
        /* El cuerpo en texto, o en una foto si no lo está. */
        $body = pdfDocumentText ($path, PDF_NOMBRAMIENTO_MARK);
        $data = pdfNombramientoFromText ($body);
        /* Los cargos se toman a partir del 1 de julio (los directivos, ese
           día); los interinos, desde el 1 de septiembre. */
        if ($data !== null && !preg_match ('/\bINTERIN[OA]S?\b/', $body))
            $start = $start->modify ("-2 months");
    }
    if ($data === null){
        logMessage (LOGGER_WARN, "PDF rejected: it is not an appointment.");
        throw new PdfSignException ("El PDF no es un nombramiento.");
    }
    [$dni, $date] = $data;
    if ($dni === null){
        logMessage (LOGGER_WARN, "Appointment rejected: no valid DNI in it.");
        throw new PdfSignException ("No se ha podido leer el DNI en el nombramiento.");
    }
    if ($date === null)
        throw new PdfSignException ("No se ha podido leer la fecha del nombramiento.");

    /* El nombramiento tiene que ser del curso actual y estar en vigor hoy: ya
       ha empezado y, como termina como muy tarde el 31 de agosto, sigue en
       vigor si es de este curso. En el escaneado la fecha de fin solo está en
       la imagen. */
    if ($date < $start){
        logMessage (LOGGER_WARN, "Appointment rejected: dated {$date->format ('Y-m-d')}.");
        throw new PdfSignException ("El nombramiento tiene que ser del curso actual.");
    }
    /* Un día de margen por si el servidor va en otra zona horaria. */
    if ($date > (new DateTimeImmutable ("today"))->modify ("+1 day")){
        logMessage (LOGGER_WARN, "Appointment rejected: not in force until {$date->format ('Y-m-d')}.");
        throw new PdfSignException ("El nombramiento todavía no está en vigor.");
    }
    return $dni;
}

/**
 * Data of a scanned appointment, read from the PDF title (pdfinfo output):
 * a DNI/NIE, maybe with a leading zero and without its letter, and a date,
 * "yyyy mm dd" or as in pdfFindDates.
 *
 * @return array|null [DNI/NIE or null, DateTimeImmutable or null], or null
 *     if the title is not the one of an appointment.
 */
function pdfNombramientoFromTitle (string $info): ?array {
    if (!preg_match ('/^Title:(.*)$/mu', $info, $m))
        return null;
    $title = pdfNormalize (str_replace (["__tmp_img__", "_"], ["", " "], $m[1]));
    /* El DNI va antes de la fecha: lo que la sigue puede ser otro número
       ("..._61504171.pdf"). */
    $date = null;
    $end = strlen ($title);
    if (preg_match ('/(?<![0-9])([0-9]{4})[ .\/-]?([0-9]{2})[ .\/-]?([0-9]{2})(?![0-9])/', $title, $d,
            PREG_OFFSET_CAPTURE) && checkdate ((int) $d[2][0], (int) $d[3][0], (int) $d[1][0])){
        $date = (new DateTimeImmutable ("today"))->setDate ((int) $d[1][0], (int) $d[2][0], (int) $d[3][0]);
        $end = $d[0][1];
    }
    else if (!empty ($dates = pdfFindDates ($title))){
        $date = $dates[0][0];
        $end = $dates[0][1];
    }
    $dnis = array_unique (pdfDnisIn (substr ($title, 0, $end), true));
    if (count ($dnis) != 1 || $date === null)
        return null;
    return [reset ($dnis), $date];
}

/**
 * Data of an appointment whose body is text, or read with OCR.
 *
 * @param string $text Normalized text (pdfNormalize).
 *
 * @return array|null [DNI/NIE or null, DateTimeImmutable or null], or null
 *     if it is not an appointment.
 */
function pdfNombramientoFromText (string $text): ?array {
    if (!preg_match (PDF_NOMBRAMIENTO_MARK, $text))
        return null;
    /* El del funcionario, no otro que pueda salir en el documento: el NIF de
       sus datos o el D.N.I. de la diligencia de toma de posesión. */
    $dni = pdfFindDni ($text, [
        '/DATOS DEL (?:FUNCIONARIO|INTERESADO|DOCENTE)(?:\/A)?\b.{0,200}?(?:N\.? ?[IL1]\.? ?[FE]|D\.? ?N\.? ?[IL1])/',
        '/POSESION\b.{0,400}?(?:N\.? ?[IL1]\.? ?[FE]|D\.? ?N\.? ?[IL1])/',
    ], 60);
    /* La fecha en que surte efecto, o si no la dice, la del documento. Si
       dice varias, la última: la toma de posesión puede ser posterior al
       nombramiento. */
    $dates = pdfLabeledDates ($text, [
        '/\b(?:FECHA (?:DE )?NOMBRAMIENTO|EFECTOS ADMINISTRATIVOS(?: DE| DESDE)?|(?:TOMA|TOMADO|TOMARA) (?:DE )?POSESION|' .
            'FECHA (?:DE )?(?:TOMA DE )?POSESION|CON EFECTOS?(?: DE| DESDE)?|FECHA (?:DE )?EFECTOS?)\b/',
    ], 80);
    if (empty ($dates))
        $dates = pdfLabeledDates ($text, PDF_ISSUE_LABELS);
    return [$dni, empty ($dates) ? null : max ($dates)];
}

/**
 * Checks that a PDF is a service record (hoja de servicios) with a service of
 * the current school year, issued not longer than pdf_max_age_days ago, and
 * gets the holder's DNI/NIE from it. Its signature is not checked: it may be
 * signed digitally or by hand.
 *
 * It does not depend on its model (the one the schools print from PDOC, the
 * one of the DAT...): it is a "hoja de servicios" that certifies something,
 * with a DNI/NIE, with or without its letter, an issue date and the dates of
 * the services.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid service record.
 */
function getHojaServiciosNIF(string $path): string
{
    $hojaservicios = '/\bHOJA DE SERVICIOS\b/';
    $text = pdfDocumentText($path, $hojaservicios, true);
    if (!preg_match($hojaservicios, $text) || !preg_match('/\b(?:CERTIFIC|CONFORME|ACREDITA|EXPID)/', $text)) {
        logMessage(LOGGER_WARN, "PDF rejected: it is not a Hoja de Servicios.");
        throw new PdfSignException("El PDF no es una Hoja de Servicios.");
    }

    /* La de la DAT lleva el DNI sin letra y con un cero delante: 050121065. */
    $dni = pdfFindDni($text);
    if ($dni === null) {
        logMessage(LOGGER_WARN, "Hoja de Servicios rejected: no single valid NIF in it.");
        throw new PdfSignException("No se ha podido leer el NIF en la Hoja de Servicios.");
    }

    pdfCheckIssueDate(pdfIssueDate($text, "expedición de la Hoja de Servicios"));

    /* Tiene que haber algún servicio vigente. No se depende del formato de
       cada fila (el código de función puede llevar letras, 0597FI, y el OCR
       lo puede leer mal): solo de sus fechas, numéricas, con la de cese
       justo detrás de la de posesión. Vale si:
       - alguna fecha es de este curso o posterior (un servicio que ha
         empezado o terminado a partir del 1 de septiembre, o que termina
         más adelante), o
       - la última fecha del documento va sola: es la toma de posesión del
         último servicio, que no tiene cese y sigue vigente. */
    $dates = pdfFindDates($text, true);
    if (empty($dates)) {
        logMessage(LOGGER_WARN, "Hoja de Servicios rejected: no services found in it.");
        throw new PdfSignException("No se han podido leer los servicios de la Hoja de Servicios.");
    }
    $tomorrow = (new DateTimeImmutable("today"))->modify("+1 day");
    $current = false;
    foreach ($dates as [$date]) {
        if ($date >= pdfCourseStart()) {
            $current = true;
            break;
        }
    }
    $last = end($dates);
    $previous = count($dates) > 1 ? $dates[count($dates) - 2] : null;
    if (!$current && ($previous === null ||
            trim(substr($text, $previous[1] + $previous[2], $last[1] - $previous[1] - $previous[2]),
                " -") !== "")) {
        $current = $last[0] <= $tomorrow;
    }
    if (!$current) {
        logMessage(LOGGER_WARN, "Hoja de Servicios rejected: no service started or ended this course.");
        throw new PdfSignException("La Hoja de Servicios no tiene ningún servicio vigente en el curso actual.");
    }
    return $dni;
}

/**
 * Checks that a PDF is a teaching credential and gets the holder's DNI/NIE
 * from it. Its date ("En MADRID, a 2 de septiembre de 2026.") has to be of
 * the current school year. Its signature is not checked: it may be signed
 * digitally or by hand.
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid credential.
 */
function getCredencialNIF(string $path): string
{
    /* Si la credencial es una foto, el OCR lee las columnas por separado y
       las frases quedan partidas: basta con sus palabras clave. */
    $credencial = '/\bCREDENCIAL\b|\bACREDITA A SU\b.*\bFUNCIONES DOCENTES\b/';
    $text = pdfDocumentText($path, $credencial, true);
    if (!preg_match($credencial, $text) || !preg_match('/\bDOCENTE|\bPUESTO\b|\bCENTRO\b|\bFUNCION\b/', $text)) {
        logMessage(LOGGER_WARN, "PDF rejected: it is not a Credencial.");
        throw new PdfSignException("El PDF no es una Credencial.");
    }

    /* "D/Dª ..., con número de documento 71104698Z": no el de quien firma. */
    $dni = pdfFindDni($text, ['/\bN(?:UMERO|º|°|O)?\.? (?:DE )?DOCUMENTO\b|\bDOCUMENTO(?: DE IDENTIDAD)?:/'], 60);
    if ($dni === null) {
        logMessage(LOGGER_WARN, "Credencial rejected: no single valid NIF in it.");
        throw new PdfSignException("No se ha podido leer el NIF en la Credencial.");
    }

    pdfCheckThisCourse(pdfIssueDate($text, "la Credencial"), "la Credencial");
    return $dni;
}

/**
 * Checks that a PDF is a payslip (nómina) of a teacher of the Comunidad de
 * Madrid for a recent month and gets the holder's DNI/NIE from it. It is not
 * signed digitally, so only its content is checked.
 *
 * The month paid (after "PERÍODO DE LIQUIDACIÓN": "OCTUBRE de 2026",
 * "10/2026") must be the current month or the previous one, and either the
 * body must be a teaching one: maestros (infantil and primaria), profesores
 * (secundaria, formación profesional, escuelas oficiales de idiomas, música,
 * artes...) or catedráticos; or the link (VINCULACIÓN) must be a teaching
 * one ("FUNC. DOCENTE").
 *
 * @param string $path PDF file.
 *
 * @return string The DNI/NIE, in upper case.
 *
 * @throws PdfSignException if the PDF is not a valid payslip.
 */
function getNominaNIF(string $path): string
{
    /* La nómina de la Comunidad de Madrid, con su CIF, de Educación. */
    $nomina = '/\bLIQUIDACION\b/';
    $text = pdfDocumentText($path, $nomina, true);
    if (!preg_match($nomina, $text) || !preg_match('/\bS-?7800001-?E\b|\bCOMUNIDAD DE MADRID\b/', $text) ||
            !preg_match('/\bEDUCACION\b/', $text)) {
        logMessage(LOGGER_WARN, "PDF rejected: it is not a Nómina.");
        throw new PdfSignException("El PDF no es una nómina de la Consejería de Educación.");
    }

    /* El DNI va tras el nombre y puede llevar una cifra de más pegada a la
       letra, o faltarle los ceros: 78745028C0. */
    $dni = pdfFindDni($text, ['/\bD\.? ?N\.? ?[IL1]\.?\/C\.?O\.?D\.? IDENTIFICACION\b|\bCOD\.? IDENTIFICACION\b/'],
        200);
    if ($dni === null) {
        logMessage(LOGGER_WARN, "Nómina rejected: no single valid NIF in it.");
        throw new PdfSignException("No se ha podido leer el NIF en la nómina.");
    }

    /* Vale si el cuerpo es docente o si la vinculación es de función
       docente. Las cabeceras y los valores de la tabla pueden ir
       intercalados. */
    $teachingBody = preg_match('/\bCUERPO\b.{0,200}?\b(?:MAESTR[OA]S?|PROFESOR(?:ES|AS?)?|CATEDRATIC[OA]S?)\b/',
        $text, $body);
    $teachingLink = preg_match('/\bVINCULACION\b.{0,100}?\bFUNC(?:\.|IONARI[OA]S?|ION)? ?(?:INTERIN[OA] )?' .
        'DOCENTE\b/', $text);
    if (!$teachingBody && !$teachingLink) {
        logMessage(LOGGER_WARN, "Nómina rejected: not a teaching body.");
        throw new PdfSignException("La nómina no es de un cuerpo docente.");
    }

    /* El mes pagado, tras su etiqueta: "OCTUBRE de 2026", "OCT 2026",
       "10/2026". */
    $paid = null;
    preg_match_all('/\bPERIODO (?:DE )?LIQUIDACION\b/', $text, $labels, PREG_OFFSET_CAPTURE);
    foreach ($labels[0] as [$label, $offset]) {
        preg_match_all('/\b([A-Z]{3,10})\.?,? (?:DE |DEL )?([0-9]{4})(?![0-9])|(?<![0-9\/])([0-9]{1,2}) ?[\/-] ?' .
            '([0-9]{4})(?![0-9\/])/', substr($text, $offset + strlen($label), 150), $periods, PREG_SET_ORDER);
        foreach ($periods as $period) {
            $month = $period[1] !== "" ? pdfMonthNumber($period[1]) : (int) $period[3];
            if ($month !== null && $month >= 1 && $month <= 12) {
                $paid = (int) ($period[1] !== "" ? $period[2] : $period[4]) * 12 + $month - 1;
                break 2;
            }
        }
    }
    if ($paid === null)
        throw new PdfSignException("No se ha podido leer el período de liquidación de la nómina.");

    /* La nómina tiene que ser del mes en curso o del anterior: en noviembre
       valen la de octubre y la de noviembre. */
    $today = new DateTimeImmutable("today");
    $current = (int) $today->format("Y") * 12 + (int) $today->format("n") - 1;
    /* Un día de margen por si el servidor va en otra zona horaria: el último
       día del mes ya se acepta la nómina del mes siguiente. */
    $tomorrow = $today->modify("+1 day");
    $next = (int) $tomorrow->format("Y") * 12 + (int) $tomorrow->format("n") - 1;
    if ($paid < $current - 1 || $paid > $next) {
        logMessage(LOGGER_WARN, "Nómina rejected: period " . intdiv($paid, 12) . "-" . ($paid % 12 + 1) . ".");
        throw new PdfSignException("La nómina tiene que ser del mes en curso o del mes anterior.");
    }
    return $dni;
}
