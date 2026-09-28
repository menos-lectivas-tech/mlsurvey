<?php
require_once 'include/config.php';
/**
 * @return string The server URL with the configured poxy_port and proxy_path 
 */
function getURL (){
    $server = rtrim ($_SERVER['HTTP_HOST'], "/");
    if (!empty (Config::PARAMS["proxy_port"])){
         $server .= ":" . Config::PARAMS["proxy_port"] . "/";
    }
    else{
         $server .= "/";
    }
    return (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
         $server . Config::PARAMS["proxy_path"];
}
