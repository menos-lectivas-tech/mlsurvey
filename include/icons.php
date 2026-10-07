<?php
/**
 * Iconos SVG en línea.
 *
 * Se insertan dentro del documento, y no como <img> ni como máscara CSS,
 * por un motivo concreto: así fill="currentColor" se resuelve contra el
 * elemento que los contiene, de modo que el icono toma el color del botón
 * y acompaña al tema (gris en reposo, rojo en la acción destructiva,
 * claro sobre fondo de acento) sin filtros ni reglas adicionales.
 *
 * Además llevan su tamaño en sus propios atributos, así que se ven aunque
 * el CSS no haya llegado todavía.
 *
 * Son de Bootstrap Icons (MIT), todos sobre la misma rejilla de 16 y con
 * el mismo grosor de trazo, para que combinen entre sí.
 */

const ML_ICONS = [
    /* bi-pencil */
    'edit' => '<path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10a.5.5 0 0 1-.168.11l-5 2a.5.5 0 0 1-.65-.65l2-5a.5.5 0 0 1 .11-.168zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zm1.586 3L10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zm-9.761 5.175-.106.106-1.528 3.821 3.821-1.528.106-.106A.5.5 0 0 1 5 12.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.468-.325"/>',

    /* bi-trash */
    'trash' => '<path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/>',

    /* bi-info-circle */
    'info' => '<path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/><path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533zM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/>',
];

/**
 * Devuelve el icono listo para incrustar.
 *
 * aria-hidden porque el texto accesible lo pone el aria-label del botón;
 * si el icono también se anunciara, se leería dos veces.
 */
function mlIcon (string $name): string {
    if (!isset (ML_ICONS[$name]))
        return '';

    return '<svg class="ml-icon" xmlns="http://www.w3.org/2000/svg" ' .
        'width="16" height="16" viewBox="0 0 16 16" fill="currentColor" ' .
        'aria-hidden="true" focusable="false">' . ML_ICONS[$name] . '</svg>';
}
