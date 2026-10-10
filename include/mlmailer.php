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
    public function sendTest ($recipient){
        $this->setFrom ($this->m_from);
        $recipients = explode (",", $recipient);
        foreach ($recipients as $key => $address) {
            $this->addAddress (trim($address));
        }
        $this->Subject = "Mensaje de prueba de MLSurvey";
        //This could be better in an external file or something
        $this->Body = "Es un mensaje de prueba de NLSurvey";

        if (!$this->send ()){
            throw new Exception("Error {$this->ErrorInfo} sending test email.");
        }
    }

    /**
     * Sends the message with the link for participating.
     * Creates the link with the parameters.
     * The message is hardcoded, but must be configurable in the future.
     * 
     * @param string $recipient Recipient's mail address
     * @param string $pid Participation table id.
     * @param string $code Base64 code for participating
     * @param string $surveyname Survey name.
     * 
     * @throws Exception with the error info.
     */
    public function sendCode ($recipient, $pid, $code, $surveyname){
        $theurl = rtrim (getURL (), "/") . "/participate?pid=" . $pid . "&auth=" . $code;
        $this->setFrom ($this->m_from);
        $this->addAddress ($recipient);
        $this->Subject = "Dirección para opinar en la consulta {$surveyname}";
        $this->Body = "La dirección para opinar en la consulta {$surveyname} es:\n{$theurl}";
        if (!$this->send ()){
            throw new Exception("Error {$this->ErrorInfo} sending code email.");
        }
    }

    /**
     * Sends the message confirming that the address has been registered as participant.
     * The message is hardcoded, but must be configurable in the future.
     * 
     * @param string $recipient Recipient's mail address
     * 
     * @throws Exception with the error info.
     */
    public function sendRegistered ($recipient){
        $theurl = rtrim (getURL (), "/") . "/surveys";
        $sitename = Config::$sitename != "" ? Config::$sitename : "mlsurvey";
        $this->setFrom ($this->m_from);
        $this->addAddress ($recipient);
        $this->Subject = "Registro completado en {$sitename}";
        $this->Body = "Te has registrado correctamente en {$sitename} con esta dirección de correo.\n\n" .
            "A partir de ahora, para participar en una consulta solo tendrás que indicar " .
            "esta dirección. Las consultas activas están en:\n{$theurl}";
        if (!$this->send ()){
            throw new Exception("Error {$this->ErrorInfo} sending registration email.");
        }
    }
}