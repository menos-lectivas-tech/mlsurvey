/* =====================================================================
   mlsurvey · firma de la declaración desde el navegador con AutoFirma
   ---------------------------------------------------------------------
   En el formulario de la declaración (ifaces/registrationview.php) añade la
   opción de firmarla aquí en vez de subir un PDF firmado por su cuenta:

   1. Se escribe la dirección de correo y se pulsa «Firmar y enviar».
   2. AutoFirma (js/afirma/autoscript.js) pide elegir el certificado del
      almacén del navegador o del sistema.
   3. El servidor (declaracion_pdf) genera la declaración con el NIF o NIE
      de ese certificado y la dirección.
   4. AutoFirma la firma (PAdES) con el mismo certificado, sin volver a
      pedirlo («sticky signatory»).
   5. El PDF firmado se pone en el campo «documento» y se envía el
      formulario, igual que si se hubiera subido: el servidor lo comprueba
      entero (utils/pdfsign.php).

   Sin JavaScript, o si el navegador no permite poner un fichero en el
   campo, la opción no aparece y solo queda subir el PDF.
   ===================================================================== */
(function (){
    var form = document.getElementById ("declaracion");
    if (!form || typeof AutoScript === "undefined" || !window.fetch || !window.URLSearchParams)
        return;
    /* El fichero firmado se pone en el <input type=file> con un DataTransfer. */
    try {
        new DataTransfer ().items.add (new File ([""], "x.pdf"));
    }
    catch (e) {
        return;
    }

    var modes = document.getElementById ("ml-firma-modes");
    var panelAuto = document.getElementById ("ml-firma-autofirma");
    var panelPdf = document.getElementById ("ml-firma-pdf");
    var emailInput = document.getElementById ("firma_email");
    var fileInput = document.getElementById ("documento");
    var button = document.getElementById ("ml-declaracion-submit");
    var status = document.getElementById ("ml-firma-status");
    if (!modes || !panelAuto || !panelPdf || !emailInput || !fileInput || !button || !status)
        return;

    /* Campo de firma vacío del modelo (PDF_FIELD_SIGNATURE en utils/pdfsign.php). */
    var SIGN_PARAMS = "signatureField=Signature\nfilters=nonexpired:";
    var CERT_PARAMS = "filters=nonexpired:";

    var loaded = false;
    var busy = false;
    var signed = false;

    function mode (){
        var checked = form.querySelector ("input[name=modo_firma]:checked");
        return checked ? checked.value : "pdf";
    }

    function setStatus (text, isError){
        status.textContent = text || "";
        status.hidden = !text;
        status.classList.toggle ("ml-firma-status--error", !!isError);
    }

    function update (){
        var auto = mode () === "autofirma";
        panelAuto.hidden = !auto;
        panelPdf.hidden = auto;
        emailInput.required = auto;
        fileInput.required = !auto;
        if (signed){
            /* El PDF firmado aquí no se queda en el campo si se cambia de opción. */
            fileInput.value = "";
            signed = false;
        }
        button.textContent = auto ? button.dataset.labelAutofirma : button.dataset.labelPdf;
        setStatus ("");
    }

    function absoluteUrl (path){
        return new URL (path, document.baseURI).href;
    }

    function load (){
        if (loaded)
            return;
        /* Servidor intermedio: solo lo usan los móviles y los navegadores muy
           antiguos; en el resto AutoFirma se conecta por un WebSocket local. */
        AutoScript.setServlets (absoluteUrl (form.dataset.storageUrl),
            absoluteUrl (form.dataset.retrieverUrl));
        AutoScript.cargarAppAfirma ();
        loaded = true;
    }

    function finish (){
        busy = false;
        button.disabled = false;
    }

    function fail (message){
        setStatus (message, true);
        finish ();
    }

    function afirmaError (type, message){
        if (type === "es.gob.afirma.core.AOCancelledOperationException")
            fail ("Has cancelado la firma. Puedes volver a intentarlo.");
        else if (type === "es.gob.afirma.standalone.ApplicationNotFoundException")
            fail ("No se ha podido abrir AutoFirma. Comprueba que está instalado y vuelve a " +
                "intentarlo, o elige «Subir un PDF que ya he firmado».");
        else {
            if (window.console)
                console.log ("AutoFirma: " + type + ": " + message);
            fail ("AutoFirma no ha podido completar la firma" + (message ? ": " + message : "") +
                ". Vuelve a intentarlo o elige «Subir un PDF que ya he firmado».");
        }
    }

    function base64ToFile (b64){
        var bin = atob (b64.replace (/-/g, "+").replace (/_/g, "/").replace (/\s+/g, ""));
        var bytes = new Uint8Array (bin.length);
        for (var i = 0; i < bin.length; i++)
            bytes[i] = bin.charCodeAt (i);
        return new File ([bytes], "declaracion_firmada.pdf", {type: "application/pdf"});
    }

    function submitSigned (signatureB64){
        var dt = new DataTransfer ();
        dt.items.add (base64ToFile (signatureB64));
        fileInput.files = dt.files;
        signed = true;
        setStatus ("Declaración firmada. Enviándola…");
        button.disabled = false;
        if (form.requestSubmit)
            form.requestSubmit (button);
        else {
            /* Sin requestSubmit el botón pulsado no va en el envío. */
            var action = document.createElement ("input");
            action.type = "hidden";
            action.name = button.name;
            action.value = button.value;
            form.appendChild (action);
            form.submit ();
        }
    }

    function signDeclaracion (pdfB64){
        setStatus ("Confirma la firma de la declaración en AutoFirma…");
        AutoScript.sign (pdfB64, "SHA256withRSA", "PAdES", SIGN_PARAMS, submitSigned, afirmaError);
    }

    function generate (certB64){
        setStatus ("Generando la declaración con los datos de tu certificado…");
        var body = new URLSearchParams ();
        body.set ("email", emailInput.value.trim ());
        body.set ("cert", certB64);
        fetch (form.dataset.pdfUrl, {method: "POST", body: body, credentials: "same-origin"})
            .then (function (response){
                return response.json ().then (function (json){
                    return {ok: response.ok, json: json};
                });
            })
            .then (function (result){
                if (result.ok && result.json && result.json.pdf)
                    signDeclaracion (result.json.pdf);
                else
                    fail ((result.json && result.json.error) || "No se ha podido generar la declaración.");
            })
            .catch (function (){
                fail ("No se ha podido generar la declaración. Comprueba tu conexión y vuelve a intentarlo.");
            });
    }

    function start (){
        busy = true;
        button.disabled = true;
        setStatus ("Abriendo AutoFirma para que elijas tu certificado… Si no se abre, " +
            "comprueba que está instalado.");
        try {
            load ();
            /* El certificado que se elija ahora se usa también para firmar, sin
               volver a pedirlo; en cada intento se elige de nuevo. */
            AutoScript.setStickySignatory (false);
            AutoScript.setStickySignatory (true);
            AutoScript.selectCertificate (CERT_PARAMS, generate, afirmaError);
        }
        catch (e) {
            afirmaError ("java.lang.Exception", e && e.message);
        }
    }

    form.addEventListener ("submit", function (ev){
        if (mode () !== "autofirma" || signed)
            return;
        ev.preventDefault ();
        if (!busy)
            start ();
    });

    form.querySelectorAll ("input[name=modo_firma]").forEach (function (radio){
        radio.addEventListener ("change", update);
    });
    emailInput.addEventListener ("input", function (){
        if (!busy)
            setStatus ("");
    });
    modes.hidden = false;
    update ();
})();
