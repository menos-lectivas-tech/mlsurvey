<?php
require_once "utils/crypt.php";
require_once "include/mlmailer.php";
require_once "utils/participation.php";
require_once "utils/altcha.php";

class Unsubscribe extends View {
    #[Override]
    public function show()
    {
        if (!checkToken()){
            if (isset($_REQUEST["uid"]) && isset($_REQUEST["auth"])){
                $this->confirm ();
                return;
            }
            tokenError();
            return;
        }
        /* El formulario de baja lleva el CAPTCHA de la página de participar. */
        if (!altchaCheck()){
            altchaError();
            return;
        }
        if (!isset($_REQUEST["email"]) || $_REQUEST["email"] == ""){
            echo "<p><strong>No se ha proporcionado una dirección de correo.</strong></p>";
            return;
        }
        $email = strtolower(trim($_REQUEST["email"]));
        $db = dbConn();
        $id = getParticipantId($db, participantHash($email));
        if ($id === null){
            echo "<p><strong>La dirección de correo introducida no corresponde a ninguna
                persona registrada como participante.</strong></p>";
            return;
        }
        $code = random_bytes(32);
        $codehash = hash('sha256', $code);
        $query = $db->prepare ("INSERT into {Unsubscriptions} (participantid, confirmkey)
            VALUES (:id, :code)");
        $query->bindParam(":id", $id, PDO::PARAM_INT);
        $query->bindParam(":code", $codehash, PDO::PARAM_STR);
        $query->execute();
        $uid = $db->lastInsertId ();
        $mailer = new MLMailer();
        $mailer->configure();
        $mailer->sendUnsubscribe ($email, $uid, url_base64_encode($code));
        echo "<p><strong>Se ha enviado un mensaje con el enlace para confirmar la baja
            a la dirección de correo indicada.</strong></p>";
    }

    private function confirm (){
        $uid = $_REQUEST["uid"];
        $code = hash ("sha256", url_base64_decode($_REQUEST["auth"]));
        try {
            $db = dbConn();
            $query = $db->prepare("UPDATE {Unsubscriptions} set confirmed = 1
                WHERE unsubscribeid = :uid AND confirmkey = :code");
            $query->bindParam (":uid", $uid, PDO::PARAM_INT);
            $query->bindParam (":code", $code, PDO::PARAM_STR);
            $query->execute();
            if ($query->rowCount() == 0){
                echo "<p><strong>La dirección proporcionada no corresponde a ninguna
                solicitud de baja.</strong></p>";
            }
            else {
                echo "<p><strong>La solicitud de baja ha sido confirmada con éxito. En un
                plazo menor de 30 días todos tus datos serán eliminados.</strong></p>";
            }
        }
        catch (Exception $e){
            echo "<p><strong>Ha ocurrido un error tratando de procesar tu confirmación 
                de baja del registro de participantes.</strong></p>";
            logMessage(LOGGER_ERROR, "Unsubscribe: {$e}");
        }
    }

}