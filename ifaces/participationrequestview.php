<?php

/**
 * Base class of the pages where the participation URL of a survey is requested:
 * GetCode (first request) and Resend (sending it again).
 *
 * Both have the same form, with only the email address, and handle its submit in
 * the same way: the survey must be open and the address must be of DOMAIN; then,
 * holding the lock of the address, the subclass stores the request
 * (reserveParticipation) and the URL is sent to the address.
 */

require_once 'ifaces/view.php';
require_once 'utils/html.php';
require_once 'utils/crypt.php';
require_once 'utils/dbutils.php';
require_once 'utils/participation.php';
require_once 'utils/showsurvey.php';
require_once 'utils/token.php';
require_once 'utils/altcha.php';
require_once 'include/icons.php';
require_once 'include/mlmailer.php';

abstract class ParticipationRequestView extends View
{

    private const ACTION = "Solicitar";
    /* Solo pueden participar las direcciones de este dominio. */
    private const DOMAIN = "educa.madrid.org";
    /* Minutos que tienen que pasar entre un envío del enlace y el siguiente. */
    protected const RESEND_WAIT_MINUTES = 5;

    function getMenuGroup()
    {
        return ML_MENU_GROUP_SURVEYS;
    }

    /* Sin menú: el usuario se centra en solicitar el voto / votar. */
    function showNavigation()
    {
        return false;
    }

    public function loadStyles()
    {
?>
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <?= altchaStyleHTML(); ?>
    <?php
    }

    /**
     * For avoiding bots this page includes a CAPTCHA.
     * Thanks to ALTCHA: https://altcha.org/
     * We're using the simplest implementation of ALTCHA  with the ALTCHA widget.
     */
    public function addHead()
    {
        echo (altchaScriptHTML());
    }

    /**
     * - If it's the submit of the form, handles it (submit).
     * - If it's a GET with survey info (?responseid=N), shows the survey and the
     * form. The page is loaded with GET so it can be linked and reloaded without
     * sending the form again.
     * - Otherwise calls showNoSurvey.
     */
    public function show()
    {
        if (isset($_REQUEST[self::ACTION])) {
            if ($this->checkSubmit())
                $this->submit();
            return;
        }
        if (!isset($_GET['responseid'])) {
            $this->showNoSurvey();
            return;
        }
        $surveyid = $this->getSurveyId($_GET['responseid']);
        if ($surveyid === null)
            return;
        try {
            $db = dbConn();
            if ($this->getOpenSurveyName($db, $surveyid) === null)
                return;
            if (!showSurveyHeader($db, $surveyid, true))
                return;
            $this->showPage($db, $surveyid);
        } catch (Exception $e) {
            echo ("<p><strong>Error al acceder a la consulta seleccionada.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} getting survey for participation request.");
        }
    }

    /* Lo que se muestra debajo de la cabecera de la consulta: el formulario
       (showEmailForm) y lo que cada vista quiera añadir. */
    abstract protected function showPage(PDO $db, int $surveyid);

    /* Guarda la petición de participación. Se llama con el bloqueo del correo
       cogido. Devuelve [pid, código] para el enlace, o null (con el motivo ya
       mostrado) si no procede. */
    abstract protected function reserveParticipation(PDO $db, string $email, string $hashemail,
        int $surveyid): ?array;

    /* Mensaje de que el enlace se ha enviado; $surveyname ya va escapado. */
    abstract protected function showSent(string $surveyname);

    /* Petición sin consulta. */
    protected function showNoSurvey()
    {
        echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
    }

    /**
     * Handles the submit of the form: checks the survey and the email address and,
     * holding the lock of the address, stores the request with reserveParticipation.
     * Then sends the participation URL to the address.
     */
    private function submit()
    {
        $surveyid = $this->getSurveyId($_REQUEST['surveyid'] ?? null);
        if ($surveyid === null)
            return;
        $email = $this->getEmail();
        if ($email === null)
            return;
        $surveyname = "";
        try {
            $db = dbConn();
            /* El nombre va tal cual en el correo y escapado en la página. */
            $mailsurveyname = $this->getOpenSurveyName($db, $surveyid);
            if ($mailsurveyname === null)
                return;
            $surveyname = h($mailsurveyname);
            if (!$this->checkDomain($db, $email))
                return;
            $hashemail = participantHash($email);
            if (!lockParticipant($db, $hashemail)) {
                echo ("<p><strong>Hay otra petición en curso con la dirección de correo indicada. " .
                    "Inténtalo de nuevo en unos segundos.</strong></p>");
                return;
            }
            try {
                $reserved = $this->reserveParticipation($db, $email, $hashemail, $surveyid);
            } finally {
                unlockParticipant($db, $hashemail);
            }
            if ($reserved === null)
                return;
            [$pid, $code] = $reserved;
            $mailer = new MlMailer();
            $mailer->configure();
            $mailer->sendCode($email, $pid, url_base64_encode($code), $mailsurveyname);
            $this->showSent($surveyname);
        } catch (Exception $e) {
            echo ("<p><strong>Error al enviar el enlace para participar en la consulta " .
                "<em>{$surveyname}</em>.</strong></p>");
            logMessage(LOGGER_ERROR, "Error {$e} when sending participation URL");
        }
    }

    /* Comprueba el token y el CAPTCHA del formulario enviado. Devuelve
       false (con el motivo ya mostrado) si no valen. */
    private function checkSubmit(): bool
    {
        if (!checkToken()) {
            tokenError();
            return false;
        }
        if (!altchaCheck()) {
            altchaError();
            return false;
        }
        return true;
    }

    /* Id de consulta de la petición, o null (con el motivo ya mostrado) si no
       es un número. */
    private function getSurveyId($value): ?int
    {
        if (!is_string($value) || !ctype_digit($value)) {
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            return null;
        }
        return (int) $value;
    }

    /* Nombre de la consulta, o null (con el motivo ya mostrado) si no existe
       o no está abierta. */
    private function getOpenSurveyName(PDO $db, int $surveyid): ?string
    {
        $query = $db->prepare("SELECT surveyname, startdate < NOW() AND enddate > NOW() AS active
            FROM {Surveys} WHERE surveyid = :sid");
        $query->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $query->execute();
        $survey = $query->fetch();
        $query->closeCursor();
        if ($survey === false) {
            echo ("<p><strong>Imposible acceder a la consulta seleccionada.</strong></p>");
            logMessage(LOGGER_ERROR, "Survey {$surveyid} does not exist.");
            return null;
        }
        if (!$survey['active']) {
            echo ("<p><strong>La consulta <em>" . h($survey['surveyname']) .
                "</em> no está abierta.</strong></p>");
            return null;
        }
        return $survey['surveyname'];
    }

    /* Dirección de correo del formulario enviado, normalizada, o null (con
       el motivo ya mostrado) si no es válida. */
    private function getEmail(): ?string
    {
        $email = isset($_REQUEST['email']) && is_string($_REQUEST['email']) ?
            strtolower(trim($_REQUEST['email'])) : "";
        if ($email == "" || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            echo ("<p><strong>La dirección de correo es incorrecta.</strong></p>");
            return null;
        }
        return $email;
    }

    /* Comprueba que el sistema está configurado y que la dirección es del
       dominio autorizado. */
    private function checkDomain(PDO $db, string $email): bool
    {
        $query = $db->prepare("SELECT 1 FROM {SystemConfig} LIMIT 1");
        $query->execute();
        $configured = $query->rowCount() > 0;
        $query->closeCursor();
        if (!$configured) {
            echo ("<p><strong>El sistema no está configurado aún. No se puede participar.</strong></p>");
            return false;
        }
        if (substr(strrchr($email, "@"), 1) === self::DOMAIN)
            return true;
        echo ("<p><strong>La dirección de correo tiene que ser de " . self::DOMAIN . ".</strong></p>");
        return false;
    }

    /* Etiqueta fija por correo y consulta con la que se buscan las peticiones
       anteriores: participant va cifrado con un código aleatorio distinto en
       cada petición, así que no sirve para eso. */
    protected function requestTag(string $hashemail, int $surveyid): string
    {
        return hash('sha256', $hashemail . ':' . $surveyid);
    }

    /* Segundos desde la última petición en vigor (de la última hora) con esa
       etiqueta, o null si no hay ninguna. */
    protected function secondsSinceRequest(PDO $db, string $requesttag, int $surveyid): ?int
    {
        $query = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(participationdate), NOW())
            FROM {Participation} WHERE
            requesttag = :tag AND surveyid = :sid
            AND participationdate > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $query->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $query->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $query->execute();
        $elapsed = $query->fetchColumn();
        $query->closeCursor();
        return $elapsed === null || $elapsed === false ? null : (int) $elapsed;
    }

    /**
     * Generates a random 256bit key, encrypts the email address with it and stores
     * the key (hashed), the encrypted address, the request tag and the survey ID in
     * the Participation table.
     *
     * @return array [pid, key] for the participation URL.
     */
    protected function insertParticipation(PDO $db, string $email, string $requesttag, int $surveyid): array
    {
        $code = random_bytes(32);
        $passwd = hash('sha256', $code);
        $encryptedemail = base64_encode(encrypt($email, $code));
        $query = $db->prepare("INSERT into {Participation} (participant, surveyid, participationkey, requesttag) " .
            "values (:id, :sid, :pwd, :tag)");
        $query->bindParam(":id", $encryptedemail, PDO::PARAM_STR);
        $query->bindParam(":sid", $surveyid, PDO::PARAM_INT);
        $query->bindParam(":pwd", $passwd, PDO::PARAM_STR);
        $query->bindParam(":tag", $requesttag, PDO::PARAM_STR);
        $query->execute();
        return [(int) $db->lastInsertId(), $code];
    }

    /* El formulario: solo la dirección de correo. Cada vista pone sus textos
       en $texts: action, title, infotitle, info, emailinfo, emailhint y button. */
    protected function showEmailForm(int $surveyid, array $texts)
    {
    ?>
        <section class="ml-participate-card">
            <h3><?= h($texts['title']); ?>
                <?= $this->infoButton($texts['infotitle'], $texts['info']); ?>
            </h3>
            <form id="getcode" name="getcode" method="POST" action="<?= h($texts['action']); ?>">
                <?= setTokenHTML(); ?>
                <!-- La consulta va en el propio formulario: en la sesión
                     la pisaría otra pestaña con otra consulta abierta. -->
                <input type="hidden" name="surveyid" value="<?= $surveyid; ?>">
                <div class="ml-participate-field">
                    <label for="email">Dirección de correo</label>
                    <?= $this->infoButton("Dirección de correo",
                        "Solo se admiten direcciones de " . self::DOMAIN . ".\n\n" . $texts['emailinfo']); ?>
                    <small><?= h($texts['emailhint']); ?></small>
                </div>
                <div class="ml-participate-row">
                    <input type="email" name="email" id="email" required>
                    <?= altchaWidgetHTML(); ?>
                    <button type="submit" class="button-3 ml-participate-btn"
                        name="<?= self::ACTION; ?>" value="<?= self::ACTION; ?>">
                        <?= h($texts['button']); ?></button>
                </div>
            </form>
        </section>
        <script>
            /* Los iconos de información abren su explicación en un diálogo. */
            document.querySelectorAll(".ml-participate-card .ml-info").forEach(function(button) {
                button.addEventListener("click", function() {
                    mlDialog.alert({
                        title: button.dataset.title,
                        message: button.dataset.info,
                        confirmText: "Cerrar"
                    });
                });
            });
        </script>
<?php
    }

    /* Icono de información: al pincharlo se muestra la explicación en un diálogo. */
    private function infoButton(string $title, string $info): string
    {
        return '<button type="button" class="ml-info" aria-label="Más información: ' . h($title) .
            '" data-title="' . h($title) . '" data-info="' . h($info) . '">' . mlIcon('info') . '</button>';
    }
}
