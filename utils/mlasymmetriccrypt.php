<?php
require_once 'utils/crypt.php';

/**
 * Class for doing asymmetric encryption using libsodium.
 * 
 * Encrypts using public key and decrypts using private key.
 * 
 * Private key is exported encrypted with a password. Public key not.
 * 
 */
class MLAsymmetricCrypt {
    private const UNINITIALIZED = 0;
    private const CAN_DECRYPT = 3;
    private const CAN_ENCRYPT = 1;
    private string $keypair = "";
    private string $secret = "";
    private string $public = "";
    private const MLHEADER = "mlcrypt";
    private int $status = self::UNINITIALIZED;
    private const KEYSEP = "|";

    /**
     * Generates a keypair
     */
    public function generate () {
        $this->keypair = sodium_crypto_box_keypair();
        $this->public = sodium_crypto_box_publickey($this->keypair);
        $this->secret = sodium_crypto_box_secretkey($this->keypair);
        $this->status = self::CAN_DECRYPT;
    }

    /**
     * Returns encrypted private key in base64 format.
     * 
     * @param string $password  Password for encrypt the private key.
     * 
     * @return "Encrypted private key in base64 or false if not initilized."
     * 
     * @throws "Encryption exception."
     */
    public function getPrivate (#[\SensitiveParameter] string $password):string | false {
        if($this->status != self::CAN_DECRYPT ){
            return false;
        }
        $x = random_bytes (1);
        $bytes = $x . self::MLHEADER . $this->secret;
        for ($i = 1; $i < strlen($bytes); $i++){
            $bytes[$i] = $bytes[$i] ^ $x;
        }
        return encrypt ($bytes, $password);
    }

    /**
     * @return "Public key or false if there isn't one."
     */
    public function getPublic (): string | false {
        if (($this->status & self::CAN_ENCRYPT) == 0)
            return false;
        return base64_encode ($this->public);
    }

    /**
     * Reads public key for encryption
     * 
     * @param string Public key in base64 format.
     * 
     * 
     */
    public function setPublic (string $public){
        $this->public = base64_decode ($public);
        $this->status = self::CAN_ENCRYPT;
    }

    public function getFromPrivate (#[\SensitiveParameter] string $encsecret, 
        #[\SensitiveParameter] string $password):bool{
        
        $bytes = decrypt ($encsecret, $password);
        $x = $bytes[0];
        for ($i = 1; $i < strlen($bytes); $i++){
            $bytes[$i] = $bytes[$i] ^ $x;
        }
        if (substr_compare ($bytes, self::MLHEADER, 1, strlen (self::MLHEADER)) != 0){
            throw new Exception("Error decrypting. Incorrect password");
            return false;
        }
        $this->secret = substr ($bytes, 1 + strlen (self::MLHEADER));
        $this->public = sodium_crypto_box_publickey_from_secretkey($this->secret);
        $this->keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey (
            $this->secret, $this->public);
        $this->status = self::CAN_DECRYPT;
        return true;
    }

    /**
     * Encrypts data
     * 
     * @param string Data to encrypt using the public key
     * 
     * @return "encrypted data in base64 format or false if no public key
     */
    public function encrypt (string $data){
        if (($this->status & self::CAN_ENCRYPT) == 0 )
            return false;

        $aeskey = random_bytes (32);
        $encdata = encrypt ($data, $aeskey);
        $enckey = sodium_crypto_box_seal($aeskey, $this->public);
        return base64_encode ($enckey) . self::KEYSEP . $encdata;
    }

    /**
     * Decrypts data
     * 
     * @param string Data to decrypt using the private key in base64 format.
     * 
     * @return "decrypted data or false if no private key
     */
    public function decrypt (string $encdata){
        if ($this->status != self::CAN_DECRYPT)
            return false;
        $datakey = explode (self::KEYSEP, $encdata, 2);
        if (count ($datakey) != 2)
            return false;
        $aeskey = sodium_crypto_box_seal_open (base64_decode ($datakey[0]), $this->keypair);
        if ($aeskey === false)
            return false;
        return decrypt ($datakey[1], $aeskey);
    }



}