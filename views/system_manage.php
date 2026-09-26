<?php

require_once 'ifaces/view.php';
require_once 'utils/dbutils.php';
require_once 'include/mlmailer.php';

class SystemManage extends View {

    private const MANAGEACTION = 'manageaction';

    function addHead (){
        ?>
        <script type="text/javascript" src="vendor/hugerte/hugerte/hugerte.min.js" referrerpolicy="origin" crossorigin="anonymous"></script>
        <!-- Viste el marco del editor con el tema; el JS se ocupa del iframe. -->
        <link href="css/hugerte-theme.css" rel="stylesheet" />
        <script src="js/hugerte-theme.js"></script>
        <?php
    }

    function getMenuGroup (){
        return ML_MENU_GROUP_ADMIN;
    }
    
    function isMulticol (){
        startSession ();
        if (isAdmin ())
            return true;

        return false;
    }

    function leftMenu (){
        include 'include/userleftcolumn.php';
    }

    function show (){
        if (!isAdmin ()){
            showMain ();
            return;
        }
        $this->showSystemManage ();
    }

    private function showSystemManage (){
        echo ('<div class="col-md-8">');
        
        if (isset($_REQUEST[self::MANAGEACTION])){
            if ($_REQUEST[self::MANAGEACTION] == "Modificar")
                $this->modifySystemConfig ();
        }
        include 'include/timezones.php';
        $dbconn = dbConn ();
        $query = $dbconn->query ("SELECT * from {SystemConfig} LIMIT 1");
        if ($query->rowCount () < 1){
            $query->closeCursor ();
            $dbconn->exec ("INSERT into {SystemConfig} (timezone, alloweddomains) " .
                "values ('', '')");
            $query = $dbconn->query ("SELECT * from {SystemConfig} LIMIT 1");
        }
        $row = $query->fetch ();
        $timezonestr = $row['timezone'];
        $alloweddomains = $row['alloweddomains'];
        $_SESSION['configid'] = $row['configid'];
        $sitename = $row["sitename"];
        $mainheader = $row["mainheader"];
        $maincontent = $row["maincontent"];
        $contact = $row["contact"];
        $icon = $row["icon"];
        
        $query->closeCursor ();
        $timezoneindex = -1;
        if ($timezonestr != ""){
            $timezoneindex = array_search ($timezonestr, $timezones);
            if ($timezoneindex === false )
                $timezoneindex = 0;
        }
        ?>
        <link href="css/select2.css" rel="stylesheet" />
        <!-- Despues de select2.css: reescribe su aspecto con el tema. -->
        <link href="css/select2-theme.css" rel="stylesheet" />
        <link href="css/button3.css" rel="stylesheet" />
        <link href="css/questions.css" rel="stylesheet" />
        <script src="js/select2.js"></script>
        <script type="text/javascript">
            function isDomain (domain){
                regexp = /^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,6}$/i;
  
                if (regexp.test(domain))
                {
                    return true;
                }
                else
                {
                    return false;
                }
            }
            function checkDomains (domains){
                const domainsarray = domains.split (" ");
                return domainsarray.every (isDomain);
            }

            function allowedFields (method){
                var smtpdiv = document.getElementById ("smtpdiv");
                var sendmaildiv = document.getElementById ("sendmaildiv");
                var mailtest = document.getElementById ("mailtest");
                if (method == <?= MLMailer::SMTP_METHOD ?>){
                    sendmaildiv.style.display = "none";
                    smtpdiv.style.display = "block";
                    mailtest.style.display = "block";
                }
                else if (method == <?= MLMailer::SENDMAIL_METHOD ?>){
                    sendmaildiv.style.display = "block";
                    smtpdiv.style.display = "none";
                    mailtest.style.display = "block";
                }
                else {
                    sendmaildiv.style.display = "none";
                    smtpdiv.style.display = "none";
                    mailtest.style.display = "none";
                }
            }

            
            /* Igual que en las pantallas de gestión: el diálogo del tema
               es asíncrono, así que cuando hay que preguntar se frena el
               envío y se vuelve a pulsar el botón tras responder. */
            function validate_config (boton){
                if (boton && boton.dataset.confirmado === '1')
                    return true;

                if ($("#timezone").val () == 0 ){
                    mlDialog.alert ("Debes indicar una zona horaria.");
                    $("#timezone").select2 ('open');
                    return false;
                }

                var element = document.getElementById ("alloweddomains");
                if (element.value.trim () != ""){
	                if (!checkDomains (element.value)){
        	            mlDialog.alert ("Los dominios introducidos no son válidos.")
                	    element.focus ({preventScroll: false, focusVisible: true});
	                    return false;
        	        }
		}
		else {
			mlDialog.confirm ({
				title: "Sin dominios permitidos",
				message: "Si no introduces ningún dominio, cualquiera podrá " +
					"participar en las consultas.\n¿Confirmas que esto es así?",
				confirmText: "Sí, guardar así"
			}).then (function (confirmado){
				if (!confirmado)
					return;
				boton.dataset.confirmado = '1';
				boton.click ();
			});
			return false;
		}
                

                element = document.getElementById ("sendtest");
                if (element.checked){
                    element = document.getElementById ("testrecipient");
                    if (element.value == ""){
                        mlDialog.alert ("Para enviar un correo de prueba debes añadir destinatarias.");
                        element.focus ({preventScroll: false, focusVisible: true});
                        return false;
                    }
                }
                return true;
            }

            $(document).ready(function() {
                
                $(".searchbox").select2();
                

                const sendtest = document.getElementById('sendtest');

                sendtest.addEventListener('change', (event) => {
                    const recipient = document.getElementById("testrecipient")
                    recipient.disabled = !(event.currentTarget.checked);
                /*if (event.currentTarget.checked) {
                    recipient.disabled = false;
                } else {
                    rece
                }*/
                });

                hugerte.init(mlHugerte.options ({
                    selector: '.description',
		            plugins: 'link autolink lists',
		            toolbar: 'undo redo | styles | bold italic | link | indent outdent | bullist numlist'
                }));
            });
        </script>
        <h2>Configuración del sistema.</h2>
        <form id="systemmanage" name="systemmanage" method="POST" 
            action="system_manage" onload='prepareTimezones ();'>
        <div class="question">
            <p><label for="timezone">Zona horaria:</label>
            <select id="timezone" name="timezone" class="searchbox"
                style="width: 40%;">
                <?php
                foreach ($timezones as $key => $timezone) {
                    echo ("<option value=\"{$key}\"");
                    if ($key != 0 && $key == $timezoneindex)
                        echo (" selected ");
                    echo (">{$timezone}</option>" );
                }
                ?>
            </select></p>
            <p><label for="alloweddomains">Dominios permitidos:</label>
            <input type="text" id="alloweddomains" name="alloweddomains"
                value="<?= $alloweddomains; ?>"
                placeholder="Separados por espacios. Vacío indica sin restricciones."></p>
            <p><label for="sitename">Nombre del sitio:</label>
                <input type="text" id="sitename" name="sitename" value="<?= $sitename; ?>">
            </p>
            <p><label for="contact">Dirección de contacto:</label>
                <input type="email" id="contact" name="contact" value="<?= $contact; ?>">
            </p>
            <p><label for="mainheader">Texto cabecera:</label>
                <input type="text" id="mainheader" name="mainheader" value="<?= $mainheader; ?>">
            </p>
            <p><label for="maincontent">Texto principal:</label>
                <textarea class="description" name="maincontent" id="maincontent">
                    <?= $maincontent; ?>
                </textarea>
            </p>
            <div class="option" id="mailtest" style="display: none;">
                <p><label for="sendtest">Enviar mensaje de prueba:</label>
                <input type="checkbox" id="sendtest" name="sendtest"></p>
                <p><label for="testrecipient">Destinatarias:</label>
                <input type="email" id="testrecipient" name="testrecipient" disabled
                placeholder="Separadas por , (comas)" multiple></p>
            </div>
        </div>
        <p><input type="submit" class="button-3" name="<?= self::MANAGEACTION ?>"
             id="mod" value="Modificar" onclick="return validate_config (this);">
            </p>
        </form>
        <?php
    }
    private function modifySystemConfig(){
        include 'include/timezones.php';

        if (!isset ($_SESSION['configid']))
            return;
        $cid = $_SESSION['configid'];
        unset ($_SESSION['configid']);
        $dbconn = dbConn ();
        $query = $dbconn->prepare ("UPDATE {SystemConfig} SET
            timezone = :timezone, alloweddomains = :domain, contact = :contact,
            mainheader = :mh, maincontent = :mc, sitename = :sn
            WHERE configid = :id");
        $query->bindParam (":id", $cid, PDO::PARAM_INT);
        $query->bindParam (":timezone", $timezones[$_REQUEST['timezone']],
            PDO::PARAM_STR);
        $query->bindParam (":domain", $_REQUEST['alloweddomains'],
            PDO::PARAM_STR);
        $query->bindParam (":mh", $_REQUEST['mainheader'],
            PDO::PARAM_STR);
        $query->bindParam (":mc", $_REQUEST['maincontent'],
            PDO::PARAM_STR);
        $query->bindParam (":sn", $_REQUEST['sitename'],
            PDO::PARAM_STR);
        $query->bindParam (":contact", $_REQUEST['contact'], 
            PDO::PARAM_STR);
        $query->execute ();

        if (isset($_REQUEST["sendtest"])){
            $mailer = new MLMailer ();
            try {
                $mailer->configure ();
                $mailer->sendTest ($_REQUEST['testrecipient']);
                echo ("<strong>Mensaje de prueba enviado.</strong>");
            }
            catch (Exception $e){
                echo ("<strong>Error enviando mensaje de prueba.</strong>");
                logMessage (LOGGER_ERROR, "Error {$e} sending test email.");
            }
        }
    }
}
