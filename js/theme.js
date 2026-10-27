/* =====================================================================
   mlsurvey · selector de tema
   ---------------------------------------------------------------------
   Modo persistido en localStorage:
     · modo   -> auto | light | dark   (auto = sigue al sistema operativo)

   La aplicación del tema se hace antes de pintar con el script en línea
   de <head> (ver index.php). Este archivo sólo monta la interfaz.
   ===================================================================== */
(function () {
    "use strict";

    var STORE_MODE = "mlsurvey:theme-mode";
    var DEFAULT_MODE = "auto";
    var root = document.documentElement;

    /* localStorage puede lanzar (modo privado, cookies bloqueadas). */
    function read(key, fallback) {
        try {
            return window.localStorage.getItem(key) || fallback;
        } catch (e) {
            return fallback;
        }
    }

    function write(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            /* Sin persistencia, pero la sesión actual sigue funcionando. */
        }
    }

    function applyMode(mode) {
        if (mode === "light" || mode === "dark") {
            root.setAttribute("data-theme", mode);
        } else {
            /* Sin atributo, manda prefers-color-scheme. */
            root.removeAttribute("data-theme");
        }
    }

    function init() {
        var panel = document.getElementById("ml-theme-panel");
        var trigger = document.getElementById("ml-theme-btn");
        if (!panel || !trigger) {
            return;
        }

        var mode = read(STORE_MODE, DEFAULT_MODE);
        var modeButtons = panel.querySelectorAll("[data-mode]");

        function syncChecked(buttons, attr, value) {
            Array.prototype.forEach.call(buttons, function (btn) {
                btn.setAttribute(
                    "aria-checked",
                    btn.getAttribute(attr) === value ? "true" : "false"
                );
            });
        }

        function refresh() {
            syncChecked(modeButtons, "data-mode", mode);
        }

        Array.prototype.forEach.call(modeButtons, function (btn) {
            btn.addEventListener("click", function () {
                mode = btn.getAttribute("data-mode");
                applyMode(mode);
                write(STORE_MODE, mode);
                refresh();
            });
        });

        function open() {
            panel.hidden = false;
            trigger.setAttribute("aria-expanded", "true");
        }

        function close() {
            panel.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
        }

        trigger.addEventListener("click", function (event) {
            event.stopPropagation();
            if (panel.hidden) {
                open();
            } else {
                close();
            }
        });

        /* Cerrar al pulsar fuera o con Escape. */
        document.addEventListener("click", function (event) {
            if (!panel.hidden && !panel.contains(event.target)) {
                close();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && !panel.hidden) {
                close();
                trigger.focus();
            }
        });

        refresh();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
