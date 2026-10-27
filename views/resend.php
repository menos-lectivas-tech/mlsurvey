<?php

/**
 * This class shows the page for sending again the participation URL to someone
 * who has already requested it.
 * It isn't in the navigation menu: the link to it is in the message GetCode shows
 * when there is already a request for the email address.
 */

require_once 'ifaces/participationrequestview.php';

class Resend extends ParticipationRequestView
{

    private const PAGE = "resend";

    protected function showPage(PDO $db, int $surveyid)
    {
        $this->showEmailForm($surveyid, [
            'action' => self::PAGE,
            'title' => "Reenviar el enlace para participar",
            'infotitle' => "Reenviar el enlace",
            'info' => "Si ya has solicitado participar en esta consulta y no te ha llegado " .
                "el mensaje, indica la misma dirección de correo y te enviaremos un " .
                "enlace nuevo.\n\n" .
                "El enlace nuevo caduca en una hora y sustituye a los anteriores, que " .
                "dejan de funcionar.\n\n" .
                "Entre un envío y el siguiente tienen que pasar " . self::RESEND_WAIT_MINUTES .
                " minutos.\n\n" .
                "Antes de pedirlo, mira en la carpeta de correo no deseado.",
            'emailinfo' => "Tiene que ser la dirección con la que solicitaste participar.",
            'emailhint' => "La misma con la que solicitaste participar.",
            'button' => "Reenviar el enlace",
        ]);
    }

    /**
     * The code of the URL isn't stored (only its hash, and the email address is
     * encrypted with it), so the URL already sent can't be built again: the method
     * stores a new request and deletes the previous ones of that address for the
     * survey.
     * The address must have a request in force, and RESEND_WAIT_MINUTES must have
     * passed since the last one.
     */
    protected function reserveParticipation(PDO $db, string $email, string $hashemail,
        int $surveyid): ?array
    {
        $request = "<a href='get_code?responseid={$surveyid}'>solicitar participar</a>";
        $participantid = getParticipantId($db, $hashemail);
        if ($participantid === null) {
            echo ("<p><strong>No hay ninguna petición de participación para esta consulta con " .
                "la dirección de correo indicada.</strong></p>");
            echo ("<p>Puedes {$request} en la consulta.</p>");
            return null;
        }
        if (hasParticipated($db, $participantid, $surveyid)) {
            echo ("<p><strong>Ya se ha votado en esta consulta con la dirección de correo indicada.</strong></p>");
            return null;
        }
        $requesttag = $this->requestTag($hashemail, $surveyid);
        $elapsed = $this->secondsSinceRequest($db, $requesttag, $surveyid);
        if ($elapsed === null) {
            echo ("<p><strong>No hay ninguna petición de participación en vigor para esta " .
                "consulta con la dirección de correo indicada.</strong></p>");
            echo ("<p>Puedes {$request} de nuevo en la consulta.</p>");
            return null;
        }
        $wait = self::RESEND_WAIT_MINUTES * 60 - $elapsed;
        if ($wait > 0) {
            $minutes = (int) ceil($wait / 60);
            $retry = "<a href='" . self::PAGE . "?responseid={$surveyid}'>pedir el reenvío</a>";
            echo ("<p><strong>Hace menos de " . self::RESEND_WAIT_MINUTES . " minutos que te enviamos el " .
                "enlace para participar en esta consulta: aún no se puede reenviar.</strong></p>");
            echo ("<p>Podrás {$retry} dentro de " .
                ($minutes == 1 ? "1 minuto" : "{$minutes} minutos") . ". Mientras tanto, revisa " .
                "la bandeja de entrada y la carpeta de correo no deseado: el mensaje puede " .
                "tardar un poco en llegar. El enlace que ya te enviamos sigue siendo válido.</p>");
            return null;
        }

        $reserved = $this->insertParticipation($db, $email, $requesttag, $surveyid);
        $delete = $db->prepare("DELETE FROM {Participation} WHERE
            requesttag = :tag AND surveyid = :sid AND participationid <> :pid");
        $delete->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $delete->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $delete->bindParam(":pid", $reserved[0], PDO::PARAM_INT);
        $delete->execute();
        return $reserved;
    }

    protected function showSent(string $surveyname)
    {
        echo ("<p><strong>Hemos enviado un enlace nuevo para participar en la consulta " .
            "<em>{$surveyname}</em> a la dirección indicada.</strong></p>");
        echo ("<p>Los enlaces anteriores han dejado de funcionar.</p>");
    }
}
