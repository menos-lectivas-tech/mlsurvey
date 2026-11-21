<?php
require_once 'utils/html.php';
require_once 'utils/dbutils.php';

/**
 * Text with the time left for a survey to close.
 *
 * @param int $minutes Minutes until the end date.
 * @return string The text to show in the card.
 */
function surveyTimeLeft (int $minutes): string {
    if ($minutes >= 2880)
        return "quedan " . intdiv ($minutes, 1440) . " días";
    if ($minutes >= 1440)
        return "queda 1 día";
    if ($minutes >= 120)
        return "quedan " . intdiv ($minutes, 60) . " horas";
    if ($minutes >= 60)
        return "queda 1 hora";
    if ($minutes >= 2)
        return "quedan {$minutes} minutos";
    return "último minuto";
}

/**
 * Shows the active surveys as cards, the ones closing first at the start.
 * Without active surveys, or on a database error, it shows nothing: the rest
 * of the main page has to keep working.
 */
function showActiveSurveyCards (): void {
    try {
        $db = dbConn ();
        $query = $db->prepare ("SELECT surveyid, surveyname, showpartial,
            DATE_FORMAT(enddate, '%d/%m/%Y') AS enddateday,
            DATE_FORMAT(enddate, '%H:%i') AS enddatehour,
            TIMESTAMPDIFF(MINUTE, NOW(), enddate) AS minutesleft
            FROM {Surveys}
            WHERE startdate < NOW() AND enddate > NOW()
            ORDER BY enddate ASC");
        $query->execute ();
        $surveys = $query->fetchAll ();
        $query->closeCursor ();
    }
    catch (Exception $e) {
        logMessage (LOGGER_ERROR, "Error {$e} loading active surveys for the main page.");
        return;
    }
    if (empty ($surveys))
        return;
    ?>
    <section class="ml-survey-cards" aria-label="Consultas abiertas">
        <?php foreach ($surveys as $survey) {
            $id = (int) $survey['surveyid'];
            $name = h ($survey['surveyname']);
            $minutes = (int) $survey['minutesleft'];
            /* Menos de un día: se avisa con el color. */
            $urgent = $minutes < 1440;
            ?>
            <article class="ml-survey-card<?= $urgent ? " is-urgent" : ""; ?>">
                <p class="ml-survey-status">
                    <span class="ml-survey-dot" aria-hidden="true"></span>
                    Abierta · <?= h (surveyTimeLeft ($minutes)); ?>
                </p>
                <h2 class="ml-survey-name"><?= $name; ?></h2>
                <p class="ml-survey-end">
                    Cierra el <?= h ($survey['enddateday']); ?> a las <?= h ($survey['enddatehour']); ?>
                </p>
                <div class="ml-survey-actions">
                    <?php /* Este enlace cubre toda la tarjeta (ver css/home.css). */ ?>
                    <a class="button-3 ml-survey-cta" href="get_code?responseid=<?= $id; ?>">
                        Participar<span class="sr-only"> en <?= $name; ?></span>
                        <span aria-hidden="true">→</span>
                    </a>
                    <?php if (!empty ($survey['showpartial'])) { ?>
                        <a class="ml-survey-partial" href="results?queryid=<?= $id; ?>">
                            Ver resultados parciales<span class="sr-only"> de <?= $name; ?></span>
                        </a>
                    <?php } ?>
                </div>
            </article>
        <?php } ?>
    </section>
    <?php
}
