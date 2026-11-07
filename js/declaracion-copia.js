/* =====================================================================
   mlsurvey · copia de la declaración firmada en el navegador
   ---------------------------------------------------------------------
   En la página del resultado del registro (RegistrationView::showSignedCopy)
   descarga la declaración que js/declaracion-firma.js ha firmado con
   AutoFirma y ha dejado en sessionStorage antes de enviarla. No se descarga
   en la página del formulario porque al enviarlo se saldría de ella y la
   descarga se anularía.
   También muestra un enlace por si el navegador no la descarga solo. La
   declaración se borra de sessionStorage en cuanto se lee: lleva el NIF.
   Si se ha subido un PDF, no hay nada guardado y no se muestra nada.
   ===================================================================== */
(function (){
    var KEY = "ml-declaracion-firmada";
    var box = document.getElementById ("ml-declaracion-copia");
    var link = document.getElementById ("ml-declaracion-copia-link");
    if (!box || !link || !window.Blob || !window.URL || !URL.createObjectURL)
        return;
    var b64;
    try {
        b64 = sessionStorage.getItem (KEY);
        sessionStorage.removeItem (KEY);
    }
    catch (e) {
        return;
    }
    if (!b64)
        return;
    var bytes;
    try {
        var bin = atob (b64.replace (/-/g, "+").replace (/_/g, "/").replace (/\s+/g, ""));
        bytes = new Uint8Array (bin.length);
        for (var i = 0; i < bin.length; i++)
            bytes[i] = bin.charCodeAt (i);
    }
    catch (e) {
        return;
    }
    /* La URL vale mientras siga abierta la página: no se revoca. */
    link.href = URL.createObjectURL (new Blob ([bytes], {type: "application/pdf"}));
    link.download = "declaracion_firmada.pdf";
    box.hidden = false;
    link.click ();
})();
