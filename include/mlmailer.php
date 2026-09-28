<?php
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
        /*$db = dbConn ();
        try {
            $query = $db->prepare ("SELECT * FROM {SystemConfig} LIMIT 1");
            $query->execute ();
            if ($query->rowCount () == 0){
                throw new Exception("No server settings not found. Configure them first.");
                return;
            }
            $row = $query->fetch ();
            if (is_null($row['emailmethod']) || $row['emailmethod'] == self::NO_METHOD){
                throw new Exception("Email settings not found. Configure them first.");
                return;
            }
            $method = $row['emailmethod'];
            if ($method == self::SMTP_METHOD){
                $this->configSMTP ($row);
            }
            else if ($method == self::SENDMAIL_METHOD){
                $this->configSendmail ();
            }
            else {
                throw new Exception("Unkown email method configured. Method: {$method}.");
            }
            $this->m_from = $row['emailfrom'];
            $this->CharSet = self::CHARSET_UTF8;
            $this->Encoding = self::ENCODING_BASE64;
        }
        catch (Exception $e){
            throw $e;
        }*/
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
}