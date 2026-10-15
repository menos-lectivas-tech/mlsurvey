<?php
require_once 'include/config.php';

/**
 * Encrypts data using AES-256-ECB. As all application encryption keys are random and
 * encrypted data is short there's a very low risk of patterns generation.
 * 
 * @param string $data Data to encrypt.
 * @param string $key 256 bit length encrypting key.
 * 
 * @return string Encrypted data.
 * 
 */
function encrypt (#[\SensitiveParameter]string $data, #[\SensitiveParameter]string $key){
    $method = "AES-256-ECB"; //Using ECB is secure as $key is random.
    $encrypted = openssl_encrypt ($data, $method, $key);
    if ($encrypted === false){
        throw new Exception("Error when encrypting: " . openssl_error_string());
        return false;
    }
    return $encrypted;
}

/**
 * Decrypts data with AES-256-ECB.
 * 
 * @param string $encrypted Encrypted data.
 * @param string $key 256 bit length encrypting key decrypting key.
 * 
 * @return string Decrypted data.
 */
function decrypt (#[\SensitiveParameter]string $encrypted, #[\SensitiveParameter]string $key){
    $method = "AES-256-ECB"; //Using ECB is secure as $key is random.
    $data = openssl_decrypt ($encrypted, $method, $key);
    if ($data === false){
        throw new Exception("Error when decrypting: " . openssl_error_string());
        return false;
    }
    return $data;
}

/**
 * Base64 encoding method replacing URL unsafe characters with safe ones.
 */
function url_base64_encode (#[\SensitiveParameter] string $data):string {
    $encoded = strtr(base64_encode($data), '+=/', '-_.');
    //return rtrim($encoded, '=');
    return $encoded;
}

/**
 * Base64 decoding method for the previous url_base64_encode.
 */
function url_base64_decode(#[\SensitiveParameter] string $data): string {
    $decoded = base64_decode(strtr($data, '-_.', '+=/'), true);
    if (false === $decoded) {
        throw new InvalidArgumentException('url_base64 mal formado.');
    }
    return $decoded;
}

/**
 * Hash that identifies a participant by her email address or DNI: HMAC-SHA256 with
 * the participant_hmac_key of the configuration. Without the key the hashes stored
 * in Participants can't be checked against a list of addresses or DNIs.
 * The HMAC is computed over the SHA-256 of the value, so updatedb.php can convert
 * the plain SHA-256 hashes stored by older versions.
 *
 * @param string $value Email address or DNI.
 *
 * @return string Hexadecimal hash, 64 characters.
 */
function participantHash (#[\SensitiveParameter] string $value): string {
    return participantHmac (hash ('sha256', $value));
}

/**
 * HMAC-SHA256 with participant_hmac_key of an already SHA-256 hashed value.
 */
function participantHmac (string $sha256): string {
    $key = Config::PARAMS['participant_hmac_key'] ?? '';
    if ($key === '')
        throw new Exception ("participant_hmac_key is not set in config/config.php");
    return hash_hmac ('sha256', $sha256, $key);
}
