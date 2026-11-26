/* =====================================================================
   mlsurvey · avisos y confirmaciones con el tema de la aplicación
   ---------------------------------------------------------------------
   alert() y confirm() los pinta el navegador con el aspecto del sistema
   operativo: ni el color de la aplicación, ni su tipografía, ni el modo
   oscuro. Esto los sustituye por un <dialog> propio.

       mlDialog.alert ("No has rellenado el nombre");
       mlDialog.alert ({title: "...", message: "..."});

       mlDialog.confirm ({
           title: "Eliminar usuaria",
           message: "¿Seguro?",
           confirmText: "Eliminar",
           danger: true
       }).then (function (ok){ ... });

   Diferencia importante con los originales: confirm() del navegador
   detiene la ejecución hasta que respondes, y esto no puede hacerlo.
   Devuelve una promesa, así que un onclick que antes hacía
   «return confirm (...)» ahora tiene que devolver false y reenviar el
   formulario cuando la promesa diga que sí.
   ===================================================================== */
(function (global) {
    "use strict";

    var dialog = null;
    var titleEl = null;
    var messageEl = null;
    var cancelBtn = null;
    var confirmBtn = null;
    var pending = null;

    function build() {
        if (dialog) {
            return dialog;
        }

        dialog = document.createElement("dialog");
        dialog.className = "ml-dialog";
        /* method="dialog" hace que cada botón cierre el diálogo y deje su
           value en returnValue, sin enviar nada. */
        dialog.innerHTML =
            '<form method="dialog" class="ml-dialog__form">' +
            '<h2 class="ml-dialog__title"></h2>' +
            '<p class="ml-dialog__message"></p>' +
            '<div class="ml-dialog__actions">' +
            '<button type="submit" value="cancel" class="button-3 is-secondary"></button>' +
            '<button type="submit" value="confirm" class="button-3"></button>' +
            "</div></form>";

        titleEl = dialog.querySelector(".ml-dialog__title");
        messageEl = dialog.querySelector(".ml-dialog__message");
        cancelBtn = dialog.querySelector('[value="cancel"]');
        confirmBtn = dialog.querySelector('[value="confirm"]');

        /* Un solo sitio donde se resuelve: vale tanto para los botones
           como para Escape, que cierra con returnValue vacío. */
        dialog.addEventListener("close", function () {
            var resolve = pending;
            pending = null;
            if (resolve) {
                resolve(dialog.returnValue === "confirm");
            }
        });

        document.body.appendChild(dialog);
        return dialog;
    }

    function open(options) {
        var opts = typeof options === "string" ? { message: options } : (options || {});
        build();

        titleEl.textContent = opts.title || (opts.confirm ? "¿Confirmas?" : "Aviso");
        titleEl.hidden = !titleEl.textContent;
        messageEl.textContent = opts.message || "";

        confirmBtn.textContent = opts.confirmText || "Aceptar";
        confirmBtn.className = "button-3" + (opts.danger ? " is-danger" : "");

        cancelBtn.textContent = opts.cancelText || "Cancelar";
        if (!opts.confirm){
            cancelBtn.style.display = 'none';
        }
        //cancelBtn.hidden = !opts.confirm;

        dialog.returnValue = "";

        return new Promise(function (resolve) {
            pending = resolve;
            if (typeof dialog.showModal === "function") {
                dialog.showModal();
                /* En un aviso el foco va al único botón; en una
                   confirmación, a Cancelar: que la tecla Intro no
                   dispare sola algo irreversible. */
                (opts.confirm ? cancelBtn : confirmBtn).focus();
            } else {
                /* Navegador sin <dialog>: mejor el del sistema que nada. */
                dialog.close();
                pending = null;
                resolve(opts.confirm ? global.confirm(opts.message) : (global.alert(opts.message), true));
            }
        });
    }

    global.mlDialog = {
        alert: function (options) {
            var opts = typeof options === "string" ? { message: options } : (options || {});
            opts.confirm = false;
            return open(opts);
        },
        confirm: function (options) {
            var opts = typeof options === "string" ? { message: options } : (options || {});
            opts.confirm = true;
            return open(opts);
        }
    };
})(window);
