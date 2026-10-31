<?php

/**
 * This class shows the page for requesting the participation URL of a survey.
 * The participant is identified by the email address: it's registered on its first
 * request, and the URL is sent to the address.
 */

require_once 'ifaces/participationrequestview.php';

class GetCode extends ParticipationRequestView
{

    private const PAGE = "get_code";

    /* Sin consulta se muestra la página principal. */
    protected function showNoSurvey()
    {
        showMain();
    }

    protected function showPage(PDO $db, int $surveyid)
    {
        $this->showEmailForm($surveyid, [
            'action' => self::PAGE,
            'title' => "Participar en la consulta",
            'infotitle' => "Cómo participar",
            'info' => "Indica tu dirección de correo de educa.madrid.org.\n\n" .
                "Recibirás un mensaje con un enlace personal para participar, que " .
                "caduca en una hora. Solo se puede votar una vez con cada dirección.\n\n" .
                "Si quieres pensarte las respuestas antes de enviar tus datos, puedes " .
                "verlas en «Ver las preguntas de la consulta», debajo de este recuadro.",
            'emailinfo' => "Revísala bien antes de enviar: si la tecleas mal no recibirás " .
                "el enlace.",
            'emailhint' => "Revísala bien: ahí recibirás el enlace.",
            'button' => "Participar en la consulta",
        ]);
?>
        <details class="ml-survey-preview">
            <summary>Ver las preguntas de la consulta</summary>
            <?php showSurveyQuestions($db, $surveyid, true); ?>
        </details>
<?php
    }

    /**
     * Registers the address in Participants table if it isn't yet. Then, if the
     * participant hasn't voted, hasn't unsubscribed and has no request in force for
     * the survey, stores a new one.
     */
    protected function reserveParticipation(PDO $db, string $email, string $hashemail,
        int $surveyid): ?array
    {
        $participantid = registerParticipant($db, $hashemail);
        if (hasParticipated($db, $participantid, $surveyid)) {
            echo ("<p><strong>Ya se ha votado en esta consulta con la dirección de correo indicada.</strong></p>");
            return null;
        }
        if (checkUnsubscribe($db, $participantid)) {
            echo ("<p><strong>No puedes participar en la consulta porque has confirmado " .
                "la solicitud de baja del registro de participantes.</strong></p>");
            return null;
        }
        $requesttag = $this->requestTag($hashemail, $surveyid);
        if ($this->secondsSinceRequest($db, $requesttag, $surveyid) !== null) {
            echo ("<p><strong>Ya existe una petición de participación para esta consulta " .
                "con la dirección de correo indicada.</strong></p>");
            echo ("<p>Podrás realizar una nueva petición en una hora. Si no te ha llegado el " .
                "mensaje, pasados " . self::RESEND_WAIT_MINUTES . " minutos puedes " .
                "<a href='resend?responseid={$surveyid}'>pedir que te lo reenviemos</a>.</p>");
            return null;
        }
        return $this->insertParticipation($db, $email, $requesttag, $surveyid);
    }

    protected function showSent(string $surveyname)
    {
        echo ("<p><strong>El enlace para participar en la consulta <em>{$surveyname}</em> " .
            "ha sido enviado a la dirección indicada.</strong></p>");
    }
}
