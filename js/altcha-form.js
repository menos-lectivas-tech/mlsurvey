/* =====================================================================
   mlsurvey · bloqueo del envío hasta completar el captcha ALTCHA
   ---------------------------------------------------------------------
   Deshabilita los botones de envío del formulario que contiene cada
   <altcha-widget> y sólo los habilita cuando el widget queda en estado
   «verified». Si la verificación caduca o falla, se vuelven a bloquear.

   El bloqueo se pone desde aquí y no en el HTML: si el script no carga,
   el botón sigue funcionando y es el servidor quien rechaza el envío.
   ===================================================================== */
(function (){
    function setupWidget (widget){
        var form = widget.closest ("form");
        if (!form)
            return;

        var buttons = form.querySelectorAll ("button[type=submit], input[type=submit]");
        function setEnabled (enabled){
            buttons.forEach (function (button){
                button.disabled = !enabled;
            });
        }

        setEnabled (false);
        widget.addEventListener ("statechange", function (ev){
            setEnabled (ev.detail && ev.detail.state === "verified");
        });
    }

    function init (){
        document.querySelectorAll ("altcha-widget").forEach (setupWidget);
    }

    if (document.readyState === "loading")
        document.addEventListener ("DOMContentLoaded", init);
    else
        init ();
})();
