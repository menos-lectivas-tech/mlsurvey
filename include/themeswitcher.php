<?php
/*
 * Selector de tema para la barra de navegación.
 * El comportamiento está en js/theme.js; los colores, en css/theme.css.
 */
$ml_modes = [
    'auto'  => 'Auto',
    'light' => 'Claro',
    'dark'  => 'Oscuro',
];
?>
<div class="ml-theme">
    <button type="button" id="ml-theme-btn" class="ml-theme-btn"
            aria-haspopup="dialog" aria-expanded="false"
            aria-controls="ml-theme-panel" title="Apariencia">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/>
        </svg>
        <span class="sr-only">Apariencia</span>
    </button>

    <div class="ml-theme-panel" id="ml-theme-panel" role="dialog"
         aria-label="Apariencia" hidden>
        <p class="ml-theme-title" id="ml-theme-mode-label">Modo</p>
        <div class="ml-seg" role="radiogroup" aria-labelledby="ml-theme-mode-label">
            <?php foreach ($ml_modes as $ml_value => $ml_label) { ?>
            <button type="button" role="radio" aria-checked="false"
                    data-mode="<?= $ml_value; ?>"><?= $ml_label; ?></button>
            <?php } ?>
        </div>
    </div>
</div>
