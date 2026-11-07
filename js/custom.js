/* Comportamiento común a todas las páginas. */
jQuery(function ($) {
	"use strict";

	// Nombre de cada red social al pasar por encima.
	$('.social-network li a').tooltip();

	// Botón para volver arriba.
	$(window).scroll(function () {
		if ($(this).scrollTop() > 100)
			$('.scrollup').fadeIn();
		else
			$('.scrollup').fadeOut();
	});
	$('.scrollup').click(function () {
		$("html, body").animate({ scrollTop: 0 }, 1000);
		return false;
	});
});
