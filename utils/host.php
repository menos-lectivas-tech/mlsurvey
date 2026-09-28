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
