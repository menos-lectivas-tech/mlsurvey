<?php
/*
 * Captcha ALTCHA: una prueba de trabajo que resuelve el navegador, sin
 * cookies de terceros ni servicios externos.
 *
 * El servidor genera un reto (un hash cuyo número original hay que
 * encontrar probando), lo firma con HMAC y lo guarda en la sesión. El
 * widget de js/altcha/ lo resuelve y devuelve la solución en el campo
 * «altcha» del formulario. Como el token anti-CSRF de utils/token.php,
 * cada reto sirve para un único envío.
 */
require_once 'utils/session.php';
require_once 'utils/logger.php';
require_once 'include/config.php';

/* Campo del formulario que rellena el widget. */
const ALTCHA_FIELD = 'altcha';
/* Único algoritmo que se acepta del cliente. */
const ALTCHA_ALGORITHM = 'SHA-256';
/* Coste de la prueba: el navegador prueba números hasta dar con el bueno. */
const ALTCHA_MAX_NUMBER = 200000;
/* Validez del reto, en segundos. */
const ALTCHA_EXPIRES = 900;
/* Variable de sesión donde se guarda el reto pendiente. */
const ALTCHA_SESSION = 'altcha';
/* Variable de sesión con la clave HMAC, si no hay ninguna configurada. */
const ALTCHA_SESSION_KEY = 'altchakey';

/* Textos del widget; sin esto se muestra en inglés. */
const ALTCHA_STRINGS = [
    "ariaLinkLabel" => "Visitar Altcha.org",
    "error" => "La verificación ha fallado. Inténtalo de nuevo.",
    "expired" => "La verificación ha caducado. Inténtalo de nuevo.",
    "footer" => 'Protegido por <a href="https://altcha.org/" target="_blank" ' .
        'aria-label="Visitar Altcha.org">ALTCHA</a>',
    "label" => "No soy un robot",
    "loading" => "Cargando...",
    "reload" => "Recargar",
    "verify" => "Verificar",
    "verificationRequired" => "Hay que completar la verificación.",
    "verified" => "Verificado",
    "verifying" => "Verificando...",
    "waitAlert" => "Verificando... espera un momento.",
];

/*
 * El captcha necesita Web Crypto, que el navegador sólo ofrece en
 * contextos seguros (HTTPS o localhost): en una instalación sin cifrar
 * hay que desactivarlo con altcha_enabled.
 */
function altchaEnabled (){
    if (!isset (Config::PARAMS['altcha_enabled']))
        return true;

    return (bool) Config::PARAMS['altcha_enabled'];
}

/*
 * Clave con la que se firman los retos. Si no hay ninguna configurada se
 * genera una por sesión: basta, porque el reto se comprueba siempre en la
 * misma sesión que lo generó.
 */
function altchaKey (){
    if (isset (Config::PARAMS['altcha_hmac_key']) && Config::PARAMS['altcha_hmac_key'] != "")
        return Config::PARAMS['altcha_hmac_key'];

    startSession ();
    if (!isset ($_SESSION[ALTCHA_SESSION_KEY]))
        $_SESSION[ALTCHA_SESSION_KEY] = bin2hex (random_bytes (32));

    return $_SESSION[ALTCHA_SESSION_KEY];
}

function altchaChallenge (){
    startSession ();
    $number = random_int (0, ALTCHA_MAX_NUMBER);
    /* La caducidad viaja en la sal, como indica el protocolo de ALTCHA. */
    $salt = bin2hex (random_bytes (12)) . '?expires=' . (time () + ALTCHA_EXPIRES);
    $challenge = hash ('sha256', $salt . $number);
    $_SESSION[ALTCHA_SESSION] = $challenge;

    return [
        "algorithm" => ALTCHA_ALGORITHM,
        "challenge" => $challenge,
        "maxnumber" => ALTCHA_MAX_NUMBER,
        "salt" => $salt,
        "signature" => hash_hmac ('sha256', $challenge, altchaKey ()),
    ];
}

function altchaScriptHTML (){
    if (!altchaEnabled ())
        return "";

    return "<script type='module' src='js/altcha/altcha.min.js'></script>" .
        "<script src='js/altcha-form.js' defer></script>";
}

function altchaStyleHTML (){
    if (!altchaEnabled ())
        return "";

    return "<link href='css/altcha.css' rel='stylesheet' />";
}

/*
 * La persona tiene que marcar el widget para resolver el reto; hasta que
 * no queda verificado, js/altcha-form.js mantiene deshabilitado el botón
 * de envío del formulario.
 */
function altchaWidgetHTML (){
    if (!altchaEnabled ())
        return "";

    $challenge = htmlspecialchars (json_encode (altchaChallenge ()), ENT_QUOTES);
    $strings = htmlspecialchars (json_encode (ALTCHA_STRINGS, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    $field = ALTCHA_FIELD;

    return "<altcha-widget name=\"{$field}\" " .
        "challengejson=\"{$challenge}\" strings=\"{$strings}\"></altcha-widget>";
}

/* La caducidad va en la sal como «...?expires=<marca de tiempo>». */
function altchaExpired ($salt){
    $params = explode ('?', $salt, 2);
    if (count ($params) < 2)
        return false;

    parse_str ($params[1], $values);
    if (!isset ($values['expires']))
        return false;

    return (int) $values['expires'] < time ();
}

function altchaCheck (){
    if (!altchaEnabled ())
        return true;

    startSession ();
    $challenge = isset ($_SESSION[ALTCHA_SESSION]) ? $_SESSION[ALTCHA_SESSION] : "";
    /* Un reto, un envío: se descarta aunque la comprobación falle. */
    unset ($_SESSION[ALTCHA_SESSION]);

    if ($challenge == ""){
        logMessage (LOGGER_WARN, "Altcha: no challenge in session.");
        return false;
    }

    if (!isset ($_REQUEST[ALTCHA_FIELD]) || !is_string ($_REQUEST[ALTCHA_FIELD])){
        logMessage (LOGGER_WARN, "Altcha: no solution in request.");
        return false;
    }

    $json = base64_decode ($_REQUEST[ALTCHA_FIELD], true);
    $solution = $json === false ? null : json_decode ($json, true);
    if (!is_array ($solution)){
        logMessage (LOGGER_WARN, "Altcha: malformed solution.");
        return false;
    }

    foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $field){
        if (!isset ($solution[$field]) || !is_scalar ($solution[$field])){
            logMessage (LOGGER_WARN, "Altcha: missing field {$field} in solution.");
            return false;
        }
    }

    if ($solution['algorithm'] !== ALTCHA_ALGORITHM){
        logMessage (LOGGER_WARN, "Altcha: unexpected algorithm {$solution['algorithm']}.");
        return false;
    }

    if (!is_int ($solution['number']) || $solution['number'] < 0){
        logMessage (LOGGER_WARN, "Altcha: invalid number in solution.");
        return false;
    }

    /* El reto resuelto tiene que ser el que se envió en esta sesión, ... */
    if (!hash_equals ($challenge, (string) $solution['challenge'])){
        logMessage (LOGGER_WARN, "Altcha: solution for another challenge.");
        return false;
    }

    /* ... la firma tiene que ser nuestra, ... */
    $signature = hash_hmac ('sha256', $challenge, altchaKey ());
    if (!hash_equals ($signature, (string) $solution['signature'])){
        logMessage (LOGGER_WARN, "Altcha: bad signature.");
        return false;
    }

    /* ... el número tiene que resolverlo de verdad ... */
    if (!hash_equals ($challenge, hash ('sha256', $solution['salt'] . $solution['number']))){
        logMessage (LOGGER_WARN, "Altcha: wrong solution.");
        return false;
    }

    /* ... y no puede llegar tarde. */
    if (altchaExpired ($solution['salt'])){
        logMessage (LOGGER_WARN, "Altcha: expired challenge.");
        return false;
    }

    return true;
}

function altchaError (){
    ?>
    <strong>
        <p>No se ha podido comprobar que la petición la hace una persona.</p>
        <p>Vuelve a cargar la página e inténtalo de nuevo.</p>
        <p>Si se repite el error, comprueba que el navegador tiene
            JavaScript activado.</p>
    </strong>
    <?php
}
