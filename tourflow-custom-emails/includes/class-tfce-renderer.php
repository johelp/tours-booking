<?php
namespace TourFlowCustomEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Motor de plantillas deliberadamente simple: reemplazo literal de
 * {{variable}}, sin lógica condicional ni loops — el operador pega HTML
 * completo (de Mailchimp, Figma-to-HTML, lo que sea) y solo necesita
 * variables puntuales, no un lenguaje de templates nuevo que aprender.
 */
final class Renderer {

	/** @param array<string,string> $vars */
	public static function render( string $template, array $vars ): string {
		$search  = [];
		$replace = [];

		foreach ( Vars::TEXT_KEYS as $key ) {
			$search[]  = '{{' . $key . '}}';
			$value     = esc_html( $vars[ $key ] ?? '' );
			// El mensaje libre del operador puede tener saltos de línea.
			$replace[] = $key === 'custom_note' ? nl2br( $value ) : $value;
		}
		// Las de URL/HTML van sin esc_html() — ya llegan por esc_url() desde
		// Vars::build(), y se usan dentro de href="..."/src="..." en el HTML
		// que pega el operador; escaparlas como texto las rompería.
		foreach ( Vars::URL_KEYS as $key ) {
			$search[]  = '{{' . $key . '}}';
			$replace[] = $vars[ $key ] ?? '';
		}

		// Los comentarios HTML (la plantilla de ejemplo trae uno de instrucciones)
		// no tienen por qué viajar en el correo real: no se ven, pero ensucian el
		// código fuente y algunos filtros de spam los puntúan.
		$template = preg_replace( '/<!--(?!\[if|<!).*?-->/s', '', $template ) ?? $template;

		// Bloques condicionales: {{#if tour_time}} … {{/if}} se muestra solo si
		// la variable tiene valor (ej. una experiencia sin horario cargado en
		// TourFlow no deja un "a las  ." vacío en el texto). Sin anidar, a
		// propósito — sigue siendo reemplazo simple, no un lenguaje de plantillas.
		$template = preg_replace_callback(
			'/\{\{#if\s+([a-z_]+)\s*\}\}(.*?)\{\{\/if\}\}/s',
			static fn( array $m ): string => trim( (string) ( $vars[ $m[1] ] ?? '' ) ) !== '' ? $m[2] : '',
			$template
		) ?? $template;

		return str_replace( $search, $replace, $template );
	}
}
