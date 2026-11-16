<?php
require_once 'include/config.php';
/*
 * URL pública del sitio, con la barra final. Sale siempre de la
 * configuración (site_url), nunca de la petición: la cabecera Host la
 * escribe el cliente, y con ella se podría mandar a una víctima un enlace
 * de participación que apunte a otro servidor.
 */
function getURL (){
    $url = trim (Config::PARAMS["site_url"] ?? "");
    if ($url == "")
        throw new Exception ("site_url is not configured: can't build links to the site.");
    return rtrim ($url, "/") . "/";
}

/*
 * Si el navegador llega al sitio por HTTPS: la petición lo es, o lo es la
 * URL pública (un proxy inverso puede terminar el TLS y reenviar por HTTP).
 */
function isHttps (): bool {
    if (!empty ($_SERVER['HTTPS']) && strtolower ($_SERVER['HTTPS']) != 'off')
        return true;
    return stripos (trim (Config::PARAMS["site_url"] ?? ""), "https://") === 0;
}

/*
 * URL del registro, con la barra final: la del servidor que pide el
 * certificado electrónico (registration_url). El resto del sitio no lo pide.
 * "" si no está configurada: se registra en el propio sitio.
 */
function getRegistrationURL (): string {
    $url = trim (Config::PARAMS["registration_url"] ?? "");
    return $url == "" ? "" : rtrim ($url, "/") . "/";
}

/*
 * Si la petición ha llegado a la URL del registro (o no hay otra). Solo
 * sirve para mandar allí al usuario: que el certificado sea válido lo
 * comprueba utils/clientcert.php.
 */
function isRegistrationHost (): bool {
    $url = getRegistrationURL ();
    if ($url == "")
        return true;
    /* Solo host y puerto: con un proxy TLS delante la petición llega por
       HTTP. El navegador no manda el puerto por defecto. */
    $parts = parse_url ($url);
    $default = strtolower ($parts['scheme'] ?? "") == "http" ? 80 : 443;
    $expected = strtolower ($parts['host'] ?? "") . ":" . ($parts['port'] ?? $default);
    $host = strtolower ($_SERVER['HTTP_HOST'] ?? "");
    if (!preg_match ('/:\d+$/', $host))
        $host .= ":" . $default;
    return $host === $expected;
}

/*
 * Dominio de la cookie de sesión. El asistente de documentos se muestra en
 * el sitio y su envío va a la URL del registro, y el token y el CAPTCHA se
 * comprueban con la sesión: la cookie tiene que llegar a las dos. Con el
 * mismo host en otro puerto ya llega (las cookies no distinguen puertos);
 * si el registro es un subdominio del sitio (registro.consultas.org y
 * consultas.org), la cookie es del dominio del sitio. "" (solo el host) en
 * otro caso.
 */
function sessionCookieDomain (): string {
    $registration = getRegistrationURL ();
    if ($registration == "")
        return "";
    $site = strtolower (parse_url (trim (Config::PARAMS["site_url"] ?? ""), PHP_URL_HOST) ?? "");
    $reg = strtolower (parse_url ($registration, PHP_URL_HOST) ?? "");
    if ($site == "" || $reg == $site || !str_ends_with ($reg, "." . $site) || filter_var ($site, FILTER_VALIDATE_IP))
        return "";
    return $site;
}
