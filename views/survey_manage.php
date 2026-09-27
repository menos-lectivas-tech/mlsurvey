<?php
require_once 'ifaces/view.php';
require_once 'utils/user.php';
require_once 'include/fileparams.php';
require_once 'utils/fileutils.php';
require_once 'utils/results.php';
require_once 'include/icons.php';

enum SurveyJavascript {
        case NoJavascript;
        case ListJavascript;
        case AddJavascript;
        case ModifJavascript;
    };

class SurveyManage extends View {
    private $surveyscount = 0;
    private const MANAGEACTION = 'manageaction';
    private const ADDACTION = 'addaction';
    private const MODIFYACTION = 'modifaction';
    private const DELACTION = 'delaction';
    private const GENERATERESULTS = 'Generar resultados';
    /* Lo que haya que contar de la generación de resultados se muestra
       dentro de la página, no antes de su cabecera. */
    private string $resultsmessage = "";
    private bool $havefile = false;
    private string $filename = "";
    private string $fileerror = "";
    private int $fileid = 0;
    function addHead (){
        ?>
        <script type="text/javascript" src="vendor/hugerte/hugerte/hugerte.min.js" referrerpolicy="origin" crossorigin="anonymous"></script>
        <!-- Viste el marco del editor con el tema; el JS se ocupa del iframe. -->
        <link href="css/hugerte-theme.css" rel="stylesheet" />
        <script src="js/hugerte-theme.js"></script>
        <?php
    }
    function getMenuGroup (){
        return ML_MENU_GROUP_SURVEYS;
    }    

    private SurveyJavascript $javascriptype = SurveyJavascript::NoJavascript;

    function isMulticol (){
        startSession ();
        if (isUser ())
            return true;

        return false;
    }

    function leftMenu (){
        include 'include/userleftcolumn.php';
    }

    function show (){
        if (!isUser ()){
            showMain ();
            return;
        }
        if (isset($_REQUEST[self::MANAGEACTION])){
            $action = $_REQUEST[self::MANAGEACTION];
            unset ($_REQUEST[self::MANAGEACTION]);
            switch ($action) {
                case 'Añadir':
                    $this->showAddSurvey ();
                    return;
                    break;
                case 'Modificar':
                    $this->showModifySurvey ();
                    return;
                case 'Eliminar':
                    //The warning should be previous;
                    $this->deleteSurvey ();
                    break;
                case self::GENERATERESULTS:
                    $this->generateResults ();
                    break;
                default:
                    logMessage (LOGGER_ERROR, "Unkown surveys manage action {$action}");
                    break;
            }
        }
        else if (isset($_REQUEST[self::ADDACTION])){
            $action = $_REQUEST[self::ADDACTION];
            unset ($_REQUEST[self::ADDACTION]);
            switch ($action) {
                case 'Aceptar':
                    $this->addSurvey ();
                    break;
                default:
                    break;
            }
        }
        else if (isset($_REQUEST[self::MODIFYACTION])){
            $action = $_REQUEST[self::MODIFYACTION];
            unset ($_REQUEST[self::MODIFYACTION]);
            switch ($action) {
                case 'Aceptar':
                    $this->modifySurvey ();
                    break;
            }
        }
        ?>
        <div class="col-md-8">
        <h2>Gestión de consultas</h2>
        <?= $this->resultsmessage; ?>
        <form id="surveymanage" name="surveymanage" method="POST"
            action="survey_manage">
        <!-- Los botones de cada fila escriben aquí sobre qué consulta actúan:
             el servidor sigue leyendo $_REQUEST['surveyid']. -->
        <input type="hidden" name="surveyid" id="selectedsurvey" value="">
        <?php
        $this->listSurveys ();
        $this->listEndedSurveys ();
        $this->showControls ();
        echo ('</form></div>');

    }

    private function listSurveys (){
        try {
            $dbconn = dbConn ();
            $query = $dbconn->prepare ("SELECT surveyid, surveyname" .
            ", DATE_FORMAT(enddate,'%d/%m/%Y %T') as dend" . 
            ", DATE_FORMAT(startdate,'%d/%m/%Y %T') as dstart FROM {Surveys}" .
            " WHERE startdate > NOW()" .
                " ORDER BY startdate DESC");
            $query->execute ();
            $this->surveyscount = $query->rowCount ();
            if ($this->surveyscount == 0){
                ?>
                    <p><strong>Actualmente no hay consultas para gestionar.</strong></p>
                <?php
                return;
            }
            ?>
            <p>Solo es posible gestionar las consultas que aún no hayan comenzado.</p>
            <link href="css/tablecard.css" rel="stylesheet" />
            <link href="css/button3.css" rel="stylesheet" />
<div class="card-table-container">
            <table class="card-like-table ml-stack" id="surveystable">
                <thead><tr>
                    <td>Consulta</td><td>Fecha inicio</td><td>Fecha fin</td><td class="ml-row-actions">Acciones</td>
                </tr></thead>
                <tbody>
                <?php
                while ($row = $query->fetch ()){
                    $id = $row['surveyid'];
                    $name = $row['surveyname'];
                    ?>
                    <tr id="<?= $id; ?>">
                        <td><span class="username" id="sv-<?= $id; ?>"><?= $name ?></span></td>
                        <td data-label="Fecha inicio"><?= $row['dstart'] ?></td>
                        <td data-label="Fecha fin"><?= $row['dend'] ?></td>
                        <td class="ml-row-actions">
                            <button type="submit" class="button-3 is-ghost is-icon"
                                name="<?= self::MANAGEACTION ?>" value="Modificar"
                                id="modify-<?= $id; ?>" onclick="return pick_survey (<?= $id; ?>);"
                                title="Modificar" aria-label="Modificar la consulta <?= htmlspecialchars ($name); ?>">
                                <?= mlIcon ('edit'); ?>
                            </button>
                            <button type="submit" class="button-3 is-ghost is-icon is-danger"
                                name="<?= self::MANAGEACTION ?>" value="Eliminar"
                                id="delete-<?= $id; ?>" onclick="return confirm_delete (<?= $id; ?>, this);"
                                title="Eliminar" aria-label="Eliminar la consulta <?= htmlspecialchars ($name); ?>">
                                <?= mlIcon ('trash'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php
                }
                $query->closeCursor ();
                ?>
                </tbody>
            </table>
</div>
            <?php
        }
        catch (Exception $e){
            ?>
                <p>Error obteniendo la lista de consultas. Contacte con soporte.</p>
            <?php
            logMessage (LOGGER_ERROR, "DB error {$e} getting surveys list");
        }
    }

    /* Las consultas ya finalizadas no se pueden modificar ni eliminar, pero
       sí volver a contar: de ahí que tengan tabla propia con un único botón. */
    private function listEndedSurveys (){
        try {
            $dbconn = dbConn ();
            $query = $dbconn->prepare ("SELECT s.surveyid, s.surveyname" .
            ", DATE_FORMAT(s.enddate,'%d/%m/%Y %T') as dend" .
            ", DATE_FORMAT(r.resultsdate,'%d/%m/%Y %T') as dresults" .
            ", r.ispartial FROM {Surveys} s" .
            " LEFT JOIN {Results} r ON r.surveyid = s.surveyid" .
            " WHERE s.enddate < NOW()" .
                " ORDER BY s.enddate DESC");
            $query->execute ();
            if ($query->rowCount () == 0)
                return;
            ?>
            <h3>Consultas finalizadas</h3>
            <p>De las consultas finalizadas solo es posible generar sus resultados
               a partir de las respuestas recibidas.</p>
<div class="card-table-container">
            <table class="card-like-table ml-stack" id="endedsurveystable">
                <thead><tr>
                    <td>Consulta</td><td>Fecha fin</td><td>Resultados</td><td class="ml-row-actions">Acciones</td>
                </tr></thead>
                <tbody>
                <?php
                while ($row = $query->fetch ()){
                    $id = $row['surveyid'];
                    $name = $row['surveyname'];
                    if (empty ($row['dresults']))
                        $results = "Sin generar";
                    else if (!empty ($row['ispartial']))
                        $results = "Parciales ({$row['dresults']})";
                    else
                        $results = $row['dresults'];
                    ?>
                    <tr id="ended-<?= $id; ?>">
                        <td><span class="username" id="sve-<?= $id; ?>"><?= $name ?></span></td>
                        <td data-label="Fecha fin"><?= $row['dend'] ?></td>
                        <td data-label="Resultados"><?= $results ?></td>
                        <td class="ml-row-actions">
                            <button type="submit" class="button-3"
                                name="<?= self::MANAGEACTION ?>" value="<?= self::GENERATERESULTS ?>"
                                id="generate-<?= $id; ?>" onclick="return pick_survey (<?= $id; ?>);"
                                title="<?= self::GENERATERESULTS ?>"
                                aria-label="Generar los resultados de la consulta <?= htmlspecialchars ($name); ?>">
                                <?= self::GENERATERESULTS ?>
                            </button>
                        </td>
                    </tr>
                    <?php
                }
                $query->closeCursor ();
                ?>
                </tbody>
            </table>
</div>
            <?php
        }
        catch (Exception $e){
            ?>
                <p>Error obteniendo la lista de consultas finalizadas. Contacte con soporte.</p>
            <?php
            logMessage (LOGGER_ERROR, "DB error {$e} getting ended surveys list");
        }
    }

    /* Guarda en Results el recuento definitivo de una consulta finalizada
       contando todas sus Responses. */
    private function generateResults (){
        if (!isset ($_REQUEST['surveyid']))
            return;
        $surveyid = $_REQUEST['surveyid'];
        try {
            $dbconn = dbConn ();
            $surveys = $dbconn->prepare ("SELECT surveyname FROM {Surveys} WHERE
                surveyid = :sid AND enddate < NOW()");
            $surveys->bindParam (":sid", $surveyid, PDO::PARAM_INT);
            $surveys->execute ();
            if ($surveys->rowCount () == 0){
                $this->resultsmessage =
                    "<p><strong>No se encuentra la consulta finalizada seleccionada.</strong></p>";
                $surveys->closeCursor ();
                return;
            }
            $surveyname = $surveys->fetch ()['surveyname'];
            $surveys->closeCursor ();

            $results = countResponses ($dbconn, $surveyid);
            if ($results["Total"] == 0){
                $this->resultsmessage = "<p><strong>La consulta {$surveyname} no tiene " .
                    "respuestas: no hay resultados que generar.</strong></p>";
                return;
            }
            saveResults ($dbconn, $surveyid, $results, false);
            $this->resultsmessage = "<p><strong>Resultados de la consulta {$surveyname} " .
                "generados a partir de {$results["Total"]} respuestas.</strong></p>";
        }
        catch (Exception $e){
            $this->resultsmessage =
                "<p><strong>Error generando los resultados de la consulta.</strong></p>";
            logMessage (LOGGER_ERROR, "Error {$e} generating results for survey {$surveyid}.");
        }
    }

    private function showControls (){
       ?>
       <script type="text/javascript">
        /* Cada fila dice sobre qué consulta actúa antes de enviar. */
        function pick_survey (id){
            document.getElementById ('selectedsurvey').value = id;
            return true;
        }
        /* El diálogo del tema no detiene la ejecución como confirm(), así
           que este onclick siempre frena el envío y, si la respuesta es
           que sí, vuelve a pulsar el mismo botón: la segunda vez pasa de
           largo y el formulario se envía con su name y su value. */
        function confirm_delete (id, boton){
            if (boton.dataset.confirmado === '1')
                return true;

            pick_survey (id);
            var surveyname = document.getElementById ('sv-' + id).innerText;
            mlDialog.confirm ({
                title: "Eliminar consulta",
                message: "¿Seguro que quieres eliminar la consulta " + surveyname +
                    "?\nEsto no se puede deshacer.",
                confirmText: "Eliminar",
                danger: true
            }).then (function (confirmado){
                if (!confirmado)
                    return;
                boton.dataset.confirmado = '1';
                boton.click ();
            });
            return false;
        }
        </script>
       <p>
       <input type="submit" class="button-3" name="<?= self::MANAGEACTION ?>" id="add" value="Añadir">
        </p>
        <?php
       
    }

    function loadStyles (){
        ?>
        <link href="css/tablecard.css" rel="stylesheet" />
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <style>
            /* Zona de arrastre del PDF adjunto. */
            .drop-zone {
                display: block;
                width: 100%;
                padding: 22px;
                margin-bottom: 20px;
                border-radius: var(--ml-radius);
                background: var(--ml-surface-2);
                color: var(--ml-text-muted);
                text-align: center;
                cursor: pointer;
                transition: background var(--ml-transition),
                            border-color var(--ml-transition),
                            color var(--ml-transition);
            }
            .drop-zone:hover {
                background: var(--ml-accent-soft);
                color: var(--ml-accent-text);
            }
            .dragidle {
                border: 1px dashed var(--ml-border-strong);
            }
            .dragging {
                border: 2px dashed var(--ml-accent);
                background: var(--ml-accent-soft-hover);
                color: var(--ml-accent-text);
            }

            .survey-file {
                display: none !important;
            }

            #filediv {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin-top: 12px;
            }

            #filename {
                cursor: pointer;
                field-sizing: content;
                max-width: 75%;
            }
        </style>
        <?php
    }

    
    private function showAddSurvey (){
        $this->javascriptype = SurveyJavascript::AddJavascript;
    ?>
    <div class="col-md-8">
    <h2>Añadir consulta</h2>
        <form id="addsurvey" name="addsurvey" method="POST" action="survey_manage" 
onload='document.getElementById("survey").focus();' enctype="multipart/form-data">
        <p><label for="survey">Consulta:</label>
            <input type="text" id="survey" name="survey" tabindex="-1"></p>
        <p><label for="surveydesc">Descripción</label>
        <textarea class="description" name="surveydesc" id="surveydesc"></textarea>
        </p>
        <label id="drop-zone" class="drop-zone dragidle">
        <div id="text-file">Añadir documentación en PDF. Pulsa o arrastra el archivo aquí.</div>
            <input type="file" id="file-input" accept="application/pdf" name="file-input"
            class="survey-file"/>
            <div id="filediv" style="display: none;">
                <input type="text" name="filename" id="filename" readonly onclick="downloadFile ();">
                <button type="button" id="del-file" class="button-3" 
                        onclick="delfile ();">
                        <img src="img/del.svg" />
                </button>
            </div>
        </label>
        <p><label for="showpartial">Mostrar resultados parciales:</label>
            <input type="checkbox" name="showpartial" id="showpartial" value="1"></p>
        <p><label for="startdate">Inicio:</label>
            <input type="datetime-local" id="startdate" name="startdate"></p>
        <p><label for="enddate">Fin:</label>
            <input type="datetime-local" id="enddate" name="enddate"></p>
        <?php
         $this->addQuestionsTable ();
         ?>
        <p><input class="button-3" type="submit" onclick="return validate_add ();" name="<?= self::ADDACTION ?>" id="ok" value="Aceptar">
        <input class="button-3" type="submit" name="<?= self::ADDACTION ?>" id="cancel" value="Cancelar">
        </p>
                </form>
                </div>
        <?php
    }

    public function addJavascript (){
        switch ($this->javascriptype) {
             case SurveyJavascript::NoJavascript:
                break;
             case SurveyJavascript::AddJavascript:
                $this->writeAddJavascript ();
                break;
        }
    }

    private function writeAddJavascript (){
        $this->javascriptype = SurveyJavascript::NoJavascript;
        ?>
        <script type="text/javascript">
            var questions = 0;
                
        function validate_question (id){
            var e = hugerte.get("desc-q-" + id);
            if (e.getContent () == ""){
                mlDialog.alert ("La descripcióm de la pregunta " + id + 
                " no puede estar vacío.");
                e.focus ({preventScroll: false, focusVisible: true});
                return false;
            }

            var opt = 1;
            e = document.getElementById ("opt-" + id + "-" + opt);
            while (e != null){
                if (e.value == ""){
                    mlDialog.alert ("La opción " + opt + " de la pregunta " + id +
                        " no puede estar vacía."
                    );
                    e.focus ({preventScroll: false, focusVisible: true});
                    return false;
                }
                opt++;
                e = document.getElementById ("opt-" + id + "-" + opt);
            }
            return true;
        }
        function validate_add (){
            const survey = document.getElementById("survey");
            const start = document.getElementById("startdate");
            const end = document.getElementById("enddate"); 
            const startdate = new Date (start.value);
            const enddate = new Date (end.value);
            const today = new Date ();
            const desc = hugerte.get("surveydesc");
           
            
            if (survey.value == ""){
                mlDialog.alert ("El nombre de la consulta no puede estar vacío");
                survey.focus ({preventScroll: false, focusVisible: true});
                return false;
            }

            if (desc.getContent () == ""){
                mlDialog.alert ("La descripción de la consulta no puede estar vacía.");
                desc.focus ({preventScroll: false, focusVisible: true});
                return false;
            }

            if (start.value == ""){
                mlDialog.alert ("Tienes que indicar una fecha de inicio.");
                start.focus ({preventScroll: false, focusVisible: true});
                return false;
            }
            if (end.value == ""){
                mlDialog.alert ("Tienes que indicar una fecha de finalización.");
                end.focus ({preventScroll: false, focusVisible: true});
                return false;
            }

                        
            if (startdate <= today){
                mlDialog.alert ('La fecha de inicio debe ser posterior a ahora.')
                return false;
            }
            if (enddate <= startdate){
                mlDialog.alert ("La fecha de fin debe ser posterior a la de inicio.")
                return false;
            }
            var question = 1;
            var q = document.getElementById ("q-" + question);
            while (q != null){
                if (!validate_question (question))
                    return false;
                question++;
                q = document.getElementById ("q-" + question);
            }
            return true;
        }

        $(document).ready(function() {
            hugerte.init(mlHugerte.options ({
                selector: '.description',
		plugins: 'link autolink lists',
		toolbar: 'undo redo | styles | bold italic | link | indent outdent | bullist numlist'
            }));
        });

        </script>
        <?php
        $this->insertQuestionsCode ();
        $this->insertOptionsCode();
        $this->insertFileCode ();
    }

    private function insertFileCode (){
        ?>
        <script type="text/javascript">
            function showFile (filename){
                const filediv = document.getElementById ("filediv");
                const filetext = document.getElementById ("filename");
                const textfile = document.getElementById ("text-file");
                filetext.value = filename;
                filediv.style.display = "flex";
                textfile.style.display = "none";
            }
            function getDroppedFiles (e){
                const fileItems = [...e.dataTransfer.items].filter(
                    (item) => item.kind === "file",
                );
                if (fileItems.length > 0){
                    e.preventDefault ();
                    if (fileItems.length > 1){
                        mlDialog.alert ("Solo se admite un archivo.")
                        return;
                    }
                    fileItems.forEach ((item, i) => {
                        if (item.kind === "file") {
                            if (item.type != "application/pdf"){
                                mlDialog.alert ("El archivo no está en formato PDF.")
                                return;
                            }
                            const file = item.getAsFile();
                            const fileInput = document.getElementById ("file-input");
                            const dt = new DataTransfer ();
                            dt.items.add (file);
                            fileInput.files = dt.files;
                            showFile (file.name);
                        }
                    });
                }
            }

            function delfile (){
                const fileInput = document.getElementById ("file-input");
                const dt = new DataTransfer ();
                fileInput.files = dt.files;
                const filediv = document.getElementById ("filediv");
                const filetext = document.getElementById ("filename");
                const textfile = document.getElementById ("text-file");
                filetext.value = "";
                filediv.style.display = "none";
                textfile.style.display = "block"
            }
            function addFile (filename){
                const file = new File (["<?= FileParams::NO_FILE_CHANGE; ?>"], filename);
                const fileInput = document.getElementById ("file-input");
                const dt = new DataTransfer ();
                dt.items.add (file);
                fileInput.files = dt.files;
                showFile (file.name);
            }

            
            $(document).ready(function() {
                const dropZone = document.getElementById("drop-zone");
                dropZone.addEventListener("drop", (e) => {
                    e.preventDefault ();
                    getDroppedFiles (e);
                    dropZone.classList.remove ("dragging");
                    dropZone.classList.add ("dragidle");
                });
                dropZone.addEventListener("dragover", (e) => {
                    e.preventDefault ();
                    //Change class to enlight
                    const fileItems = [...e.dataTransfer.items].filter(
                        (item) => item.kind === "file",
                    );
                    if (fileItems.length > 0){
                        dropZone.classList.remove ("dragidle");
                        dropZone.classList.add ("dragging");
                    }       
                });
                dropZone.addEventListener("dragleave", (e) => {
                    e.preventDefault ();
                    const fileItems = [...e.dataTransfer.items].filter(
                        (item) => item.kind === "file",
                    );
                    if (fileItems.length > 0){
                        //Change class to enlight
                        dropZone.classList.add ("dragidle");
                        dropZone.classList.remove ("dragging");
                    }
                });
                <?=  $this->havefile ? "addFile ('{$this->filename}');" : ""?>

                const fileinput = document.getElementById("file-input");
                fileinput.addEventListener("change", function (){
                    showFile (this.files[0].name); 
                });
            })

            function downloadFile(){
                <?php
                    if ($this->havefile){
                        $downloadfile = FileParams::FILE_DIR . $this->fileid . "/" . $this->filename;
                        echo ("window.open ('{$downloadfile}', '_self');");
                    }
                ?>
            }
        </script>
        <?php
    }
    private function insertQuestionsCode (){
        ?>
        <script type="text/javascript">

        function retagquestion (oldid, newid){
            //var names=["name-q-", "desc-q-", "opt-q-", "mul-q-"]
            var names=["desc-q-", "opt-q-", "mul-q-"]
            var e = document.getElementById ("q-" + oldid);
            if (e == null)
                return false;

            e.id = "q-" + newid;
            e = document.getElementById ("qn-" + oldid);
            e.id = "qn-" + newid;
            e.innerText = "Pregunta " + newid;
            for (let j = 0; j < names.length; j++){
                e = document.getElementById ("label-" + names[j] + oldid);
                e.id = "label-" + names[j] + newid;
                e.htmlFor = names[j] + newid;
                e = document.getElementById (names[j] + oldid);
                e.id = names[j] + newid;
                e.name = names[j] + newid;
            }
            e = document.getElementById ("add-q-" + oldid);
            e.id = "add-q-" + newid;
            e.onclick = addquestion.bind (this, newid);
            e = document.getElementById ("del-q-" + oldid);
            if (e != null){
                e.id = "del-q-" + newid;
                e.onclick = delquestion.bind (this, newid);
            }

            //Options
            e = document.getElementById ("p-opt-" + oldid + "-1");
            e.id = "p-opt-" + newid + "-1";
            e = document.getElementById ("opt-" + oldid + "-1");
            e.id = "opt-" + newid + "-1";
            e.name = "opt-" + newid + "-1";

            e = document.getElementById ("p-opt-" + oldid + "-2");
            e.id = "p-opt-" + newid + "-2";
            e = document.getElementById ("opt-" + oldid + "-2");
            e.id = "opt-" + newid + "-2";
            e.name = "opt-" + newid + "-2";
            e = document.getElementById ("add-opt-" + oldid + "-2");
            e.id = "add-opt-" + newid + "-2";
            e.onclick = addoption.bind (this, newid, 2);
            var optid = 3;
            e = document.getElementById ("p-opt-" + oldid + "-" + optid);
            while (e != null){
                e.id = "p-opt-" + newid + "-" + optid;
                e = document.getElementById ("opt-" + oldid + "-" + optid);
                e.id = "opt-" + newid + "-" + optid;
                e.name = "opt-" + newid + "-" + optid;
                e = document.getElementById ("add-opt-" + oldid + "-" + optid);
                e.id = "add-opt-" + newid + "-" + optid;
                e.onclick = addoption.bind (this, newid, optid);
                e = document.getElementById ("del-opt-" + oldid + "-" + optid);
                if (e != null)
                    e.onclick = deloption.bind (this, newid, optid);
                optid++;
                e = document.getElementById ("p-opt-" + oldid + "-" + optid);
            }
            return true;
        }

        function addquestion(questionid) {
            if (!validate_question (questionid)){
                mlDialog.alert ("Debes completar los datos de la pregunta antes de añadir otra.")
                return;
            }
            var nextquestionid = questionid + 1;
            var morequestionsid = nextquestionid;
            var question = document.getElementById ("q-" + morequestionsid);
            while (question != null){
                morequestionsid++;
                question = document.getElementById ("q-" + morequestionsid);
            }

            if (nextquestionid < morequestionsid){
                for (let i = morequestionsid; i > nextquestionid; i--){
                    retagquestion (i-1, i);
                }
            }
            let newquestionhtml = `
            <div class="question" id="q-{qid}">
            <h3 id="qn-{qid}">Pregunta {qid}</h3>
            <!--<p><label id="label-name-q-{qid}" for="name-q-{qid}">Título:</label>
                <input type="text" id="name-q-{qid}" name="name-q-{qid}"></p>-->
            <p><label id="label-desc-q-{qid}" for="desc-q-{qid}">Descripción:</label>
                <textarea class="description" name="desc-q-{qid}" id="desc-q-{qid}"></textarea></p>
            <p><label id="label-opt-q-{qid}" for="opt-q-{qid}">Opcional:</label>
                <input type="checkbox" name="opt-q-{qid}" id="opt-q-{qid}">
                <label id="label-mul-q-{qid}" for="mul-q-{qid}">Multiple:</label><input type="checkbox" 
                name="mul-q-{qid}" id="mul-q-{qid}"></p>
            <p><input id="add-q-{qid}" type="button" class="button-3" 
                                onclick="addquestion ({qid});" value="Añadir pregunta">
                <input id="del-q-{qid}" type="button" class="button-3" 
                                onclick="delquestion ({qid});" value="Eliminar pregunta"></p>
                                
            <div class="option">
                <p><strong>Opciones:</strong></p>
                <p id="p-opt-{qid}-1"><input type="text" name="opt-{qid}-1" id="opt-{qid}-1"></p>
                <p id="p-opt-{qid}-2"><input type="text" name="opt-{qid}-2" id="opt-{qid}-2">
                    <button type="button" id="add-opt-{qid}-2" class="button-3" onclick="addoption ({qid},2);">
                        <img src="img/add.svg" />
                    </button>
                </p>
             </div>
        </div>`;
            /*newquestion = document.createElement ("div");
            newquestion.id = "q-" + nextquestionid;
            newquestion.class = "question";*/
            newquestion = newquestionhtml.replace (/{qid}/g, nextquestionid);
            let position = document.getElementById ("q-" + questionid);
            position.insertAdjacentHTML("afterend", newquestion);
            const newed = new hugerte.Editor('desc-q-' + nextquestionid, mlHugerte.options ({
		    plugins: 'link autolink lists',
		    toolbar: 'undo redo | styles | bold italic | link | indent outdent | bullist numlist'
            }), hugerte.EditorManager);
            newed.render ();
        }


        function delquestion (id){
            var e = document.getElementById ("q-" + id);
            e.remove ();
            id++;
            while (retagquestion (id, id-1)){
                id++;
            }
        }
        </script>
        <?php
    }

    private function insertOptionsCode (){
        ?>
        <script type="text/javascript">
        function deloption (questionid, optid){
            var option = document.getElementById ("p-opt-" + questionid +
                "-" + optid
            );
            option.remove ();
            while (retagoption(questionid,optid+1, optid))
                optid++;

        }
        function retagoption (questionid, oldid, newid){
            var lastopt = document.getElementById ("p-opt-" + questionid + "-" +
                oldid
            );
            if (lastopt == null){
                return false;
            }
            lastopt.id = "p-opt-" + questionid + "-" + newid;
            var lastopt = document.getElementById ("opt-" + questionid + "-" +
                oldid
            );
            lastopt.id = "opt-" + questionid + "-" + newid;
            lastopt.name = "opt-" + questionid + "-" + newid;
            lastopt = document.getElementById ("add-opt-" + questionid + "-" +
                oldid
            );
            lastopt.id = "add-opt-" + questionid + "-" + newid;
            lastopt.onclick = addoption.bind (this, questionid, newid);

            lastopt = document.getElementById ("del-opt-" + questionid + "-" +
                oldid
            );
            lastopt.id = "del-opt-" + questionid + "-" + newid;
            lastopt.onclick = deloption.bind (this, questionid, newid);
            return true;
        }
        function addoption (questionid, optionid){
            var curropt = document.getElementById ("opt-" + questionid + "-" +
                optionid
            );
            if (!validate_question (questionid)){
                mlDialog.alert ("Debes completar todos los datos de la pregunta antes de añadir otra opción");
                return false;
            }
            var lastid = optionid + 1;
            var nextoptid = lastid;
            var lastopt = document.getElementById ("p-opt-" + questionid + "-" +
                lastid
            );
            while (lastopt != null){
                lastid++;
                lastopt = document.getElementById ("p-opt-" + questionid + "-" +
                    lastid
                );
            }
            for (let i = lastid; i > nextoptid; i--){
                retagoption (questionid, i-1, i);
            }
            var optHTML = `<p id="p-opt-{qid}-{oid}">
                <input type="text" name="opt-{qid}-{oid}" id="opt-{qid}-{oid}">
                    <button type="button" id="add-opt-{qid}-{oid}" class="button-3" 
                        onclick="addoption ({qid},{oid});">
                        <img src="img/add.svg" />
                    </button>
                    <button type="button" id="del-opt-{qid}-{oid}" class="button-3" 
                        onclick="deloption ({qid},{oid});">
                        <img src="img/del.svg" />
                    </button>
                </p>`;
            optHTML1 = optHTML.replace (/{qid}/g, questionid);
            optHTML2 = optHTML1.replace (/{oid}/g, nextoptid);
            let position = document.getElementById ("p-opt-" + questionid + 
                "-" + optionid
            );
            position.insertAdjacentHTML("afterend", optHTML2);    
        }
        </script>
        <?php
    }

    private function addQuestionsTable (){
        ?>

        <div class="question" id="q-1">
            <h3 id="qn-1">Pregunta 1</h3>
            <!--<p><label id="label-name-q-1" for="name-q-1">Título:</label>
                <input type="text" id="name-q-1" name="name-q-1"></p>-->
            <p><label id="label-desc-q-1" for="desc-q-1">Descripción:</label>
                <textarea class="description" name="desc-q-1" id="desc-q-1"></textarea></p>
            <p><label id="label-opt-q-1" for="opt-q-1">Opcional:</label>
                <input type="checkbox" name="opt-q-1" id="opt-q-1">
                <label id="label-mul-q-1" for="mul-q-1">Multiple:</label><input type="checkbox" name="mul-q-1"
                id="mul-q-1"></p>
            <p><input id="add-q-1" type="button" class="button-3" 
                                onclick="addquestion(1)" value="Añadir pregunta"></p>
                                
            <div class="option">
                <p><strong>Opciones:</strong></p>
                <p id="p-opt-1-1"><input type="text" name="opt-1-1" id="opt-1-1"></p>
                <p id="p-opt-1-2"><input type="text" name="opt-1-2" id="opt-1-2">
                    <!--<input id="add-opt-1-2" type="button" class="button-3" 
                                    onclick="addoption (1,2);" value="Añadir opción">-->
                    <button type="button" id="add-opt-1-2" class="button-3" onclick="addoption (1,2);">
                        <img src="img/add.svg" alt="Añadir">
                    </button>
                </p>
             </div>
        </div>
        <?php


    
    }

    private function addSurvey (){
        $surveyname = $_REQUEST['survey'];
        $startstring = $_REQUEST['startdate'];
        $endstring = $_REQUEST['enddate'];
        $surveydesc = $_REQUEST['surveydesc'];
        $showpartial = isset ($_REQUEST["showpartial"]);
        $nquestion = 1;
        $noption = 1;
        $questions = array();
        while (isset($_REQUEST['desc-q-' . $nquestion])){
            $questions[$nquestion] = array();
            //$questions[$nquestion]['name'] = $_REQUEST['name-q-' . $nquestion];
            $questions[$nquestion]['desc'] = $_REQUEST['desc-q-' . $nquestion];
            $questions[$nquestion]['optional'] = isset(
                $_REQUEST['opt-q-' . $nquestion]);
            $questions[$nquestion]['multiple'] = isset(
                $_REQUEST['mul-q-' . $nquestion]);
            $noption = 1;
            $questions[$nquestion]['options'] = array();
            while (isset($_REQUEST['opt-' . $nquestion . '-' .$noption])){
                $questions[$nquestion]['options'][$noption] = 
                    $_REQUEST['opt-' . $nquestion . '-' .$noption];
                $noption++;
            }
            $nquestion++;
        }
        
        
        $filename = $this->saveFile (session_id ());
        try {
            $dbconn = dbConn ();
            $dbconn->beginTransaction ();
            try {
                $query = $dbconn->prepare ("INSERT into {Surveys}  
                    (surveyname, surveydesc, surveyfile, showpartial, startdate, enddate,
                    createdby, modifiedby)
                    values (:name, :desc, :file, :partial, :start, :end,
                    :uid, :uid)");
                $query->bindParam (":name", $surveyname, PDO::PARAM_STR);
                $query->bindParam (":partial", $showpartial, PDO::PARAM_BOOL);
                $query->bindParam (":start", $startstring, PDO::PARAM_STR);
                $query->bindParam (":end", $endstring, PDO::PARAM_STR);
                $query->bindParam (":desc", $surveydesc, PDO::PARAM_STR);
                $query->bindParam (":uid", $_SESSION["userid"], PDO::PARAM_INT);
                if (is_string ($filename)){
                    $query->bindParam (":file", $filename, PDO::PARAM_STR);
                }
                else {
                    $query->bindParam (":file", "", PDO::PARAM_STR);
                }
                $query->execute ();
                $sid = $dbconn->lastInsertId ();
                $this->mvdir (session_id (), $sid);
                $this->insertQuestions ($dbconn, $sid, $questions);
                $dbconn->commit ();
            }
            catch (Exception $e){
                $dbconn->rollBack ();
                throw $e;
            }
        }
        catch (Exception $e){
            echo ("<p><strong>Error creando la consulta.</strong></p>");
            logMessage (LOGGER_ERROR, "Error {$e} inserting survey.");
            $this->deldir (session_id ());
        }
        if ($filename === false){
            echo ("<p><strong>Error subiendo archivo: {$this->fileerror}.</strong></p>");
            $this->deldir (session_id ());
        }

    }

    private function insertQuestions ($dbconn, $sid, $questions){
        foreach ($questions as $qid => $question) {
            $query = $dbconn->prepare ("INSERT into {Questions} " .
                "(surveyid, questionid, questiondesc, optional, multiple) " .
                "values (:sid, :qid, :desc, :opt, :mul)");
            $query->bindParam (":sid", $sid, PDO::PARAM_INT);
            $query->bindParam (":qid", $qid, PDO::PARAM_INT);
            $query->bindParam (":desc", $question['desc'], PDO::PARAM_STR);
            $query->bindParam (":opt", $question['optional'], PDO::PARAM_BOOL);
            $query->bindParam (":mul", $question['multiple'], PDO::PARAM_BOOL);
            $query->execute ();

            $query = $dbconn->prepare ("INSERT into {Options} " .
                "(surveyid, questionid, optionid, optiondesc) " .
                "values (:sid, :qid, :oid, :desc)");
            $oid = 0;
            $option = '';
            $query->bindParam (":sid", $sid, PDO::PARAM_INT);
            $query->bindParam (":qid", $qid, PDO::PARAM_INT);
            $query->bindParam (":oid", $oid, PDO::PARAM_INT);
            $query->bindParam (":desc", $option, PDO::PARAM_STR);
            foreach ($question['options'] as $oid => $option){
                $query->execute ();
            }
        }
    }

    private function deleteSurvey (){
        if (!isset ($_REQUEST['surveyid']))
            return;
        try {
            $dbconn = dbConn ();
            $dbconn->beginTransaction ();
            try {
                $query = $dbconn->prepare ("UPDATE {Surveys} SET modifiedby = :uid
                    WHERE surveyid = :sid");
                $query->bindParam (":sid", $_REQUEST['surveyid'], PDO::PARAM_INT);
                $query->bindParam (":uid", $_SESSION["userid"], PDO::PARAM_INT);
                $query->execute ();
                $query = $dbconn->prepare ("DELETE FROM {Surveys} WHERE
                    surveyid = :sid");
                $query->bindParam (":sid", $_REQUEST['surveyid'], PDO::PARAM_INT);
                $query->execute ();
                $dbconn->commit ();
            }
            catch (Exception $e){
                $dbconn->rollBack ();
                throw $e;
                
            }
        }
        catch (Exception $e){
            echo ('<strong>Error eliminando la consulta.</strong>');
            logMessage (LOGGER_ERROR, "Error {$e} deleting survey.");
        }
    }

    private function showModifySurvey (){
        if (!isset ($_REQUEST['surveyid']))
            return;
        $sid = $_REQUEST['surveyid'];
        $_SESSION['surveyid'] = $sid;
        $this->javascriptype = SurveyJavascript::AddJavascript;
        $dbconn = dbConn ();
        $surveys = $dbconn->prepare ("SELECT * from {Surveys} where surveyid = :sid");
        $surveys->bindParam (":sid", $sid, PDO::PARAM_INT);
        $surveys->execute ();
        if ($surveys->rowCount() == 0)
            return;
        $survey = $surveys->fetch ();
        if (!empty ($survey['surveyfile'])){
            $this->havefile = true;
            $this->filename = $survey['surveyfile'];
            $this->fileid = $sid;
        }
    ?>
    <div class="col-md-8">
    <h2>Modificar consulta</h2>
        <form id="modsurvey" name="modsurvey" method="POST" action="survey_manage" 
onload='document.getElementById("survey").focus();' enctype="multipart/form-data">
        <p><label for="survey">Consulta:</label>
            <input type="text" id="survey" name="survey" tabindex="-1"
            value="<?= $survey['surveyname'] ?>"></p>
        <p><label for="surveydesc">Descripción</label>
        <textarea class="description" name="surveydesc" id="surveydesc">
            <?= $survey['surveydesc']; ?>
        </textarea>
        </p>
        <label id="drop-zone" class="drop-zone dragidle">
        <div id="text-file">Añadir documentación en PDF. Pulsa o arrastra el archivo aquí.</div>
            <input type="file" id="file-input" accept="application/pdf" name="file-input"
            class="survey-file"/>
            <div id="filediv" style="display: none;">
                <input type="text" name="filename" id="filename" readonly onclick="downloadFile ();">
                <button type="button" id="del-file" class="button-3" 
                        onclick="delfile ();">
                        <img src="img/del.svg" />
                </button>
            </div>
        </label>
        <p><label for="showpartial">Mostrar resultados parciales:</label>
            <input type="checkbox" name="showpartial" id="showpartial"
            <?= empty ($survey['showpartial'])?"":"checked" ?> value="1"></p>
        <p><label for="startdate">Inicio:</label>
            <input type="datetime-local" id="startdate" name="startdate"
            value="<?= $survey['startdate'] ?>"></p>
        <p><label for="enddate">Fin:</label>
            <input type="datetime-local" id="enddate" name="enddate"
            value="<?= $survey['enddate'] ?>"></p>
        <?php
         $this->getQuestionsTable ($dbconn, $sid);
         $surveys->closeCursor ();
         ?>
        <p><input class="button-3" type="submit" onclick="return validate_add ();" 
            name="<?= self::MODIFYACTION ?>" id="ok" value="Aceptar">
        <input class="button-3" type="submit" name="<?= self::MODIFYACTION ?>" 
            id="cancel" value="Cancelar">
        </p>
                </form>
                </div>
        <?php
    }

    private function getQuestionsTable ($dbconn, $sid){
        $questions = $dbconn->prepare ("Select * from {Questions} where surveyid = :sid");
        $questions->bindParam (":sid", $sid);
        $questions->execute ();
        while ($question = $questions->fetch ()){
            $qid = $question['questionid']; 
            $mul = $question['multiple'] ? 'checked':'';
            $opt = $question['optional'] ? 'checked':'';
            ?>
            <div class="question" id="q-<?= $qid; ?>">
            <h3 id="qn-<?= $qid; ?>">Pregunta <?= $qid; ?></h3>
            <!--<p><label id="label-name-q-<?= $qid; ?>" for="name-q-<?= $qid; ?>">Título:</label>
                <input type="text" id="name-q-<?= $qid; ?>" name="name-q-<?= $qid; ?>"
                value=""></p>-->
            <p><label id="label-desc-q-<?= $qid; ?>" for="desc-q-<?= $qid; ?>">Descripción:</label>
                <textarea class="description" name="desc-q-<?= $qid; ?>" id="desc-q-<?= $qid; ?>"><?= $question['questiondesc']; ?></textarea></p>
            <p><label id="label-opt-q-<?= $qid; ?>" for="opt-q-<?= $qid; ?>">Opcional:</label>
                <input type="checkbox" name="opt-q-<?= $qid; ?>" id="opt-q-<?= $qid; ?>" 
                <?= $opt; ?>>
                <label id="label-mul-q-<?= $qid; ?>" for="mul-q-<?= $qid; ?>">Multiple:</label>
                <input type="checkbox" name="mul-q-<?= $qid; ?>"
                id="mul-q-<?= $qid; ?>" <?= $mul; ?>></p>
            <p><input id="add-q-<?= $qid; ?>" type="button" class="button-3" 
                                onclick="addquestion(<?= $qid;?>)" value="Añadir pregunta"></p>
            <div class="option">
                <p><strong>Opciones:</strong></p>
            <?php
            $this->getOptionsTable ($dbconn, $sid, $qid);
            ?>
            </div>
        </div>
            <?php
        }
        $questions->closeCursor ();

    }
    private function getOptionsTable ($dbconn, $sid, $qid){
        $options = $dbconn->prepare ("SELECT * from {Options} " .
            "where surveyid = :sid and questionid = :qid");
        $options->bindParam (":sid", $sid);
        $options->bindParam (":qid", $qid);
        $options->execute ();
        while ($option = $options->fetch ()){
            $oid = $option['optionid'];
            if ($oid == 1){
    ?>
    
                <p id="p-opt-<?= $qid; ?>-1"><input type="text" 
                name="opt-<?= $qid;?>-1" id="opt-<?= $qid;?>-1"
                value="<?= $option['optiondesc']; ?>"></p>
    <?php
            }
            else if ($oid == 2){?>
                <p id="p-opt-<?= $qid;?>-2"><input type="text" name="opt-<?= $qid;?>-2"
                 id="opt-<?= $qid;?>-2" value="<?= $option['optiondesc']; ?>">
                    
                    <button type="button" id="add-opt-<?= $qid;?>-2" class="button-3" 
                    onclick="addoption (<?= $qid;?>,2);">
                        <img src="img/add.svg" alt="Añadir">
                    </button>
                </p>
    <?php
            }
            else {
                $qoid = $qid . "-" . $oid;
            ?>
                <p id="p-opt-<?= $qoid; ?>">
                <input type="text" name="opt-<?= $qoid; ?>" id="opt-<?= $qoid; ?>"
                    value="<?= $option['optiondesc']; ?>">
                    <button type="button" id="add-opt-<?= $qoid; ?>" class="button-3" 
                        onclick="addoption (<?= $qid; ?>,<?= $oid; ?>);">
                        <img src="img/add.svg" />
                    </button>
                    <button type="button" id="del-opt-<?= $qoid; ?>" class="button-3" 
                        onclick="deloption (<?= $qid; ?>,<?= $oid; ?>);">
                        <img src="img/del.svg" />
                    </button>
                </p>
            <?php
            }
        }
        $options->closeCursor ();
    }

    private function modifySurvey(){
        if (!isset ($_SESSION['surveyid']))
            return;
        $sid = $_SESSION['surveyid'];
        unset ($_SESSION['surveyid']);        
        $surveyname = $_REQUEST['survey'];
        $startstring = $_REQUEST['startdate'];
        $endstring = $_REQUEST['enddate'];
        $surveydesc = $_REQUEST['surveydesc'];
        $showpartial = isset ($_REQUEST["showpartial"]);
        $nquestion = 1;
        $noption = 1;
        $questions = array();
        while (isset($_REQUEST['desc-q-' . $nquestion])){
            $questions[$nquestion] = array();
            //$questions[$nquestion]['name'] = $_REQUEST['name-q-' . $nquestion];
            $questions[$nquestion]['desc'] = $_REQUEST['desc-q-' . $nquestion];
            $questions[$nquestion]['optional'] = isset(
                $_REQUEST['opt-q-' . $nquestion]);
            $questions[$nquestion]['multiple'] = isset(
                $_REQUEST['mul-q-' . $nquestion]);
            $noption = 1;
            $questions[$nquestion]['options'] = array();
            while (isset($_REQUEST['opt-' . $nquestion . '-' .$noption])){
                $questions[$nquestion]['options'][$noption] = 
                    $_REQUEST['opt-' . $nquestion . '-' .$noption];
                $noption++;
            }
            $nquestion++;
        }
        $filename = $this->saveFile ($sid);
        try {
            $dbconn = dbConn ();
            $dbconn->beginTransaction ();
            try {
                $query = $dbconn->prepare ("UPDATE {Surveys}  
                     SET surveyname = :name, startdate = :start, showpartial = :partial,
                    enddate = :end, surveydesc = :desc, surveyfile = :file, modifiedby = :uid 
                    WHERE surveyid = :sid");
                $query->bindParam (":name", $surveyname, PDO::PARAM_STR);
                $query->bindParam (":start", $startstring, PDO::PARAM_STR);
                $query->bindParam (":end", $endstring, PDO::PARAM_STR);
                $query->bindParam (":desc", $surveydesc, PDO::PARAM_STR);
                $query->bindParam (":sid", $sid, PDO::PARAM_INT);
                $query->bindParam (":partial", $showpartial, PDO::PARAM_BOOL);
                $query->bindParam (":uid", $_SESSION["userid"], PDO::PARAM_INT);
                if (is_string ($filename)){
                    $query->bindParam (":file", $filename, PDO::PARAM_STR);
                }
                else {
                    $query->bindParam (":file", "", PDO::PARAM_STR);
                }
                $query->execute ();
                $query = $dbconn->prepare ("DELETE from {Questions} ".
                    " where surveyid = :sid");
                $query->bindParam (":sid", $sid, PDO::PARAM_INT);
                $query->execute ();
                $this->insertQuestions ($dbconn, $sid, $questions);
                $dbconn->commit ();
            }
            catch (Exception $e){
                $dbconn->rollBack ();
                throw $e;
            }                
        }
        catch (Exception $e){
            echo ("<strong>Error al modificar la consulta.</strong>");
            logMessage (LOGGER_ERROR, "Error {$e} when modifying survey");
        }
        if ($filename === false){
            echo ("<p><strong>Error subiendo archivo: {$this->fileerror}.</strong></p>");
            $this->deldir ($sid);
        }
    }

    private function saveFile ($surveyid): string|bool {
        $dir = FileParams::FILE_DIR . $surveyid;
                
        $fileinfo = $_FILES['file-input'];
        if (is_array ($fileinfo["error"])){
            $this->fileerror = "Solo un archivo por subida";
            return false;
        }

        if ($fileinfo["error"] != UPLOAD_ERR_OK && $fileinfo["error"] != UPLOAD_ERR_NO_FILE){
            $this->fileerror = "Error {$fileinfo['error']} al subir el archivo {$fileinfo['name']}";
            return false;
        }
        else if ($fileinfo["error"] == UPLOAD_ERR_NO_FILE){
            return "";
        }
        
        $name = basename($fileinfo["name"]);
        $tmp_name = $fileinfo["tmp_name"];
        $res = $this->isPDF ($tmp_name);
        if ($res == 2){
            return $name;
        }
        else if ($res != 0){
            $this->fileerror = "No es un PDF válido";
            return false;
        }
        
        $newname = "{$dir}/{$name}";
        $this->deldir ($surveyid);
        mkdir ($dir, 0700, true);
        move_uploaded_file($tmp_name, $newname);
        return $name;
    }

    private function isPDF ($filename): int{
        $pdfheader = "%PDF-";
        if(!$handle = fopen($filename, 'r'))
            return 1;

        if(!$readBytes = fread($handle, 5))
            return 1;
        
        if ($readBytes == FileParams::NO_FILE_CHANGE)
            return 2;

        if ($readBytes != $pdfheader)
            return 1;
        

        return 0;
    }

    private function deldir ($surveyid) {
        $src = FileParams::FILE_DIR . $surveyid;
        delDir ($src);
    }

    private function mvdir ($orig, $dest){
        $src = FileParams::FILE_DIR . $orig;
        $dst = FileParams::FILE_DIR . $dest;
        rename ($src, $dst);
    }
}
