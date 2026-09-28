<?php
/*
 * Salida de datos en HTML.
 *
 * Los textos planos (nombres de consulta, opciones, archivos...) se escapan
 * con h(). Las descripciones las escribe el editor HugeRTE y son HTML, así
 * que se pasan por sanitizeHtml(), que solo deja las etiquetas y atributos
 * que genera el editor: cualquier usuaria puede crear consultas y su
 * contenido acaba en las páginas públicas.
 */

function h ($text): string {
    return htmlspecialchars ((string) ($text ?? ""), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* Etiquetas que se conservan. El resto se sustituye por su contenido. */
const ML_HTML_TAGS = [
    'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
    'span', 'div', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'blockquote', 'pre', 'code', 'hr',
];
/* Etiquetas que se eliminan junto con todo su contenido. */
const ML_HTML_DROP = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
    'applet', 'template', 'svg', 'math', 'noscript', 'textarea', 'select',
    'input', 'button', 'form', 'link', 'meta', 'base', 'title', 'head',
];
/* Propiedades de style que usa el editor (sangría y alineación). */
const ML_HTML_STYLES = ['padding-left', 'margin-left', 'text-align', 'text-decoration'];

function sanitizeHtml ($html): string {
    if ($html === null || trim ($html) === "")
        return "";

    $doc = new DOMDocument ();
    $previous = libxml_use_internal_errors (true);
    $doc->loadHTML ('<?xml encoding="UTF-8"><div>' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors ();
    libxml_use_internal_errors ($previous);

    $root = $doc->getElementsByTagName ('div')->item (0);
    if ($root === null)
        return "";
    sanitizeChildren ($root);

    $out = "";
    foreach ($root->childNodes as $child)
        $out .= $doc->saveHTML ($child);
    return $out;
}

function sanitizeChildren (DOMNode $node){
    foreach (iterator_to_array ($node->childNodes) as $child){
        if ($child instanceof DOMText)
            continue;
        if (!($child instanceof DOMElement)){
            /* Comentarios, CDATA, instrucciones de proceso... */
            $node->removeChild ($child);
            continue;
        }
        $tag = strtolower ($child->tagName);
        if (in_array ($tag, ML_HTML_DROP, true)){
            $node->removeChild ($child);
            continue;
        }
        sanitizeChildren ($child);
        if (!in_array ($tag, ML_HTML_TAGS, true)){
            while ($child->firstChild !== null)
                $node->insertBefore ($child->firstChild, $child);
            $node->removeChild ($child);
            continue;
        }
        sanitizeAttributes ($child, $tag);
    }
}

function sanitizeAttributes (DOMElement $element, string $tag){
    foreach (iterator_to_array ($element->attributes) as $attribute){
        $name = strtolower ($attribute->name);
        $value = $attribute->value;
        $keep = false;
        if ($name == 'style'){
            $value = sanitizeStyle ($value);
            $keep = $value !== "";
        }
        else if ($tag == 'a' && $name == 'href'){
            $keep = isSafeUrl ($value);
        }
        else if ($tag == 'a' && $name == 'title'){
            $keep = true;
        }
        else if ($tag == 'a' && $name == 'target'){
            $keep = $value == '_blank';
        }
        else if ($tag == 'ol' && ($name == 'start' || $name == 'type')){
            $keep = preg_match ('/^[0-9a-zA-Z]+$/', $value) == 1;
        }
        $element->removeAttribute ($attribute->name);
        if ($keep)
            $element->setAttribute ($name, $value);
    }
    if ($tag == 'a' && $element->hasAttribute ('target'))
        $element->setAttribute ('rel', 'noopener noreferrer');
}

/* Solo enlaces http(s), mailto o relativos: nada de javascript: o data:. */
function isSafeUrl (string $url): bool {
    /* El navegador ignora los espacios y controles dentro del esquema. */
    $compact = preg_replace ('/[\x00-\x20]+/', '', $url);
    if (preg_match ('/^(https?|mailto):/i', $compact))
        return true;
    return preg_match ('/^[^\/?#]*:/', $compact) == 0;
}

function sanitizeStyle (string $style): string {
    $kept = [];
    foreach (explode (';', $style) as $declaration){
        $parts = explode (':', $declaration, 2);
        if (count ($parts) != 2)
            continue;
        $property = strtolower (trim ($parts[0]));
        $value = trim ($parts[1]);
        if (in_array ($property, ML_HTML_STYLES, true) &&
            preg_match ('/^[a-zA-Z0-9 .%#-]+$/', $value))
            $kept[] = "{$property}: {$value}";
    }
    return implode ('; ', $kept);
}
