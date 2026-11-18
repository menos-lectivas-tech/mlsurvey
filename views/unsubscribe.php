<?php
require_once "utils/crypt.php";

/**
 * Confirms unsubscriptions requested by email before the baja was done with
 * the client certificate (see Register::unregister). Only the links already
 * sent are processed: new ones aren't sent anymore.
 */
class Unsubscribe extends View {
    #[Override]
    public function show()
    {
        if (isset($_REQUEST["uid"]) && isset($_REQUEST["auth"])){
            $this->confirm ();
            return;
        }
        echo "<p>Para darte de baja entra en <a href='register'>Regístrate</a> e
            identifícate con tu certificado electrónico.</p>";
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