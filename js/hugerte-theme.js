/* =====================================================================
   mlsurvey · HugeRTE atado al tema
   ---------------------------------------------------------------------
   El marco del editor lo viste css/hugerte-theme.css. Lo que este
   archivo resuelve es lo que el CSS de la página no alcanza:

   · El texto que se edita vive en un <iframe>. Un iframe no hereda las
     custom properties de theme.css, así que aquí se leen ya resueltas
     con getComputedStyle y se inyectan como content_style.

   · La piel (oxide / oxide-dark) se elige al arrancar el editor y no se
     puede cambiar en caliente. Si el usuario cambia de modo con el
     selector de tema, se reinicia el editor con la piel correcta,
     volcando antes el contenido a los <textarea> para no perder nada.

   Uso desde las vistas:

       hugerte.init(mlHugerte.options({ selector: '.description' }));
       new hugerte.Editor(id, mlHugerte.options(), hugerte.EditorManager);

   Lo que se pase gana sobre los valores base.
   ===================================================================== */
(function (global) {
    "use strict";

    var root = document.documentElement;
    /* Opciones del último init, para poder rehacerlo al cambiar de modo. */
    var lastInitOptions = null;
    var lastMode = null;

    function token(name, fallback) {
        var value = "";
        try {
            value = global.getComputedStyle(root).getPropertyValue(name);
        } catch (e) {
            /* Da igual: se sigue con el valor de respaldo. */
        }
        value = (value || "").trim();
        return value !== "" ? value : fallback;
    }

    function isDark() {
        var mode = root.getAttribute("data-theme");
        if (mode === "dark") {
            return true;
        }
        if (mode === "light") {
            return false;
        }
        /* Sin atributo manda el sistema, igual que en theme.css. */
        return !!(global.matchMedia &&
            global.matchMedia("(prefers-color-scheme: dark)").matches);
    }

    /* Hoja que se inyecta dentro del iframe. Los valores van resueltos,
       no como var(), porque ahí dentro no existen. */
    function contentStyle() {
        var text = token("--ml-text", "#161d26");
        var muted = token("--ml-text-muted", "#6b7785");
        var border = token("--ml-border", "#e1e6ec");
        var accent = token("--ml-accent-text", "#047857");
        var surface2 = token("--ml-surface-2", "#eef1f5");

        return [
            "body{",
            "margin:12px 14px;",
            "background:" + token("--ml-surface", "#ffffff") + ";",
            "color:" + text + ";",
            "font-family:" + token("--ml-font", "system-ui, sans-serif") + ";",
            "font-size:1rem;line-height:1.6;}",
            "h1,h2,h3,h4,h5,h6{color:" + text + ";font-weight:600;line-height:1.3;}",
            "a{color:" + accent + ";}",
            "hr{border:0;border-top:1px solid " + border + ";}",
            "blockquote{margin:0 0 1em;padding:2px 0 2px 14px;",
            "border-left:3px solid " + border + ";color:" + muted + ";}",
            "code,pre{font-family:" + token("--ml-font-mono", "monospace") + ";",
            "background:" + surface2 + ";border-radius:4px;}",
            "code{padding:1px 4px;}",
            "pre{padding:8px 10px;}",
            "table{border-collapse:collapse;}",
            "table td,table th{border:1px solid " + border + ";padding:6px 8px;}",
            "::selection{background:" + token("--ml-accent-ring", "rgba(4,120,87,.32)") + ";}"
        ].join("");
    }

    /* Reemplaza la hoja del iframe sin tocar el contenido. */
    function paintContent(editor) {
        var doc = editor && editor.getDoc && editor.getDoc();
        if (!doc || !doc.head) {
            return;
        }
        var style = doc.getElementById("ml-content-theme");
        if (!style) {
            style = doc.createElement("style");
            style.id = "ml-content-theme";
            doc.head.appendChild(style);
        }
        style.textContent = contentStyle();
    }

    function eachEditor(fn) {
        if (!global.hugerte || !global.hugerte.editors) {
            return;
        }
        Array.prototype.slice.call(global.hugerte.editors).forEach(fn);
    }

    function options(extra) {
        var dark = isDark();
        var opts = {
            license_key: "gpl",
            language: "es",
            menubar: false,
            branding: false,
            skin: dark ? "oxide-dark" : "oxide",
            content_css: dark ? "dark" : "default",
            content_style: contentStyle()
        };

        var key;
        if (extra) {
            for (key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    opts[key] = extra[key];
                }
            }
        }

        /* Repintar al abrir: content_style ya lo deja bien, pero si el
           tema cambió entre el init y el arranque real del editor, esto
           lo pone al día. Se encadena con el setup de quien llame. */
        var userSetup = opts.setup;
        opts.setup = function (editor) {
            editor.on("init", function () {
                paintContent(editor);
            });
            if (typeof userSetup === "function") {
                userSetup(editor);
            }
        };

        if (extra && extra.selector) {
            lastInitOptions = extra;
        }
        lastMode = dark;
        return opts;
    }

    /* Cambio de modo: la piel no se puede cambiar en caliente, así que se
       rehace el editor. triggerSave vuelca el HTML a los <textarea>, que
       es de donde init vuelve a leerlo. */
    function reinit() {
        if (!global.hugerte || !lastInitOptions) {
            return;
        }
        global.hugerte.triggerSave();
        global.hugerte.remove();
        global.hugerte.init(options(lastInitOptions));
    }

    function onThemeChange() {
        var dark = isDark();
        if (dark !== lastMode) {
            reinit();
        } else {
            /* Mismo modo, distinto acento: basta con repintar. */
            eachEditor(paintContent);
        }
    }

    /* theme.js no emite ningún evento: se vigila el atributo que escribe
       sobre <html>, y aparte el modo automático, que cambia sin que nadie
       toque el atributo. */
    if (global.MutationObserver) {
        new global.MutationObserver(onThemeChange).observe(root, {
            attributes: true,
            attributeFilter: ["data-theme"]
        });
    }

    if (global.matchMedia) {
        var query = global.matchMedia("(prefers-color-scheme: dark)");
        if (query.addEventListener) {
            query.addEventListener("change", onThemeChange);
        } else if (query.addListener) {
            query.addListener(onThemeChange);
        }
    }

    global.mlHugerte = {
        options: options,
        contentStyle: contentStyle,
        paintContent: paintContent,
        isDark: isDark
    };
})(window);
