<?php
/**
 * Class for handling email server and messages.
 * Extends PHPMailer (https://github.com/PHPMailer/PHPMailer), which is installed
 * via Composer
 * 
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
require_once 'vendor/autoload.php'; //For PHPMailer as installed via composer.
require_once 'utils/dbutils.php';
require_once 'utils/host.php';
require_once 'utils/logger.php';

class MLMailer extends PHPMailer {
    public const NO_METHOD = 0;
    public const SMTP_METHOD = "SMTP";
    public const SENDMAIL_METHOD = "Sendmail";

    public const METHODS = [
        self::NO_METHOD => '',
        self::SMTP_METHOD => 'SMTP',
        self::SENDMAIL_METHOD => 'Sendmail',
    ];

    public const ENCRYPTION = [
        0 => "",
        self::ENCRYPTION_SMTPS => "SSL",
        self::ENCRYPTION_STARTTLS => "STARTTLS"
    ];
    /* Se añade al final de todos los mensajes. */
    /*private const UNSUBSCRIBE = "\n\n--\nSi quieres darte de baja, envía un correo a " .
        "datos@huelga14o.org con el asunto BAJA.";*/
    private $m_from = "";
    public function configure (){
        $method = Config::PARAMS["email_method"];
        $this->m_from = Config::PARAMS["email_from"];
        $this->CharSet = self::CHARSET_UTF8;
        $this->Encoding = self::ENCODING_BASE64;
         if ($method == self::SMTP_METHOD){
            $this->configSMTP ();
        }
        else if ($method == self::SENDMAIL_METHOD){
            $this->configSendmail ();
        }
        else {
            throw new Exception("Unkown email method configured. Method: {$method}.");
        }
    }

    private function configSMTP (){
        $this->isSMTP ();
        //$this->SMTPDebug = SMTP::DEBUG_SERVER;
        $this->Host = Config::PARAMS["email_server"];
        $this->Port = Config::PARAMS["email_port"];
        $this->SMTPAuth = true;
        $this->Username = Config::PARAMS["email_user"];
        $this->Password = Config::PARAMS["email_password"];
        $this->SMTPSecure = Config::PARAMS["email_encryption"];
    }

    private function configSendmail (){
        $this->isSendmail ();
    }

    /**
     * Tests email sending with a hardcoded message. This message must be
     * configurable in the future.
     * 
     * @param string $recipient Recipient email address.
     * 
     * @throws Exception with the error info.
     */
    public function sendTest (#[\SensitiveParameter] string $recipient){
        $this->setFrom ($this->m_from);
        $recipients = explode (",", $recipient);
        foreach ($recipients as $key => $address) {
            $this->addAddress (trim($address));
        }
        $this->Subject = "Mensaje de prueba de MLSurvey";
        //This could be better in an external file or something
        $this->Body = "Es un mensaje de prueba de mlsurvey";// . self::UNSUBSCRIBE;

        if (!$this->send ()){
            throw new Exception("Error {$this->ErrorInfo} sending test email.");
        }
    }

    /**
     * Sends the message with the link that verifies the account of a participant
     * who has just registered. Until it is opened the participant can't vote.
     * The message is hardcoded, but must be configurable in the future.
     *
     * @param string $recipient Recipient's mail address
     * @param string $code url_base64 code of the link.
     * @param int $minutes How long the link is valid, in minutes.
     *
     * @throws Exception with the error info.
     */
    public function sendVerification (#[\SensitiveParameter] string $recipient,
        #[\SensitiveParameter] string $code, int $minutes){
        $theurl = rtrim (getURL (), "/") . "/verify?code=" . $code;
        $sitename = Config::$sitename != "" ? Config::$sitename : "mlsurvey";
        $this->setFrom ($this->m_from);
        $this->addAddress ($recipient);
        $this->Subject = "Activa tu cuenta en {$sitename}";
        $this->Body = "Te has registrado en {$sitename} con esta dirección de correo.\n\n" .
            "Para activar tu cuenta abre este enlace, que caduca en {$minutes} minutos:\n{$theurl}\n\n" .
            "Una vez activada, para participar en las consultas te identificarás con tu " .
            "certificado electrónico: no te enviaremos más mensajes para votar.\n\n" .
            "Si no te has registrado tú, ignora este mensaje.";
        logMessage (LOGGER_DEBUG, "Verification URL: {$theurl}");
        if (!$this->send ()){
            throw new Exception("Error {$this->ErrorInfo} sending verification email.");
        }
    }
}