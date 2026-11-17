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
			$replace[] = esc_html( $vars[ $key ] ?? '' );
		}
		// Las de URL/HTML van sin esc_html() — ya llegan por esc_url() desde
		// Vars::build(), y se usan dentro de href="..."/src="..." en el HTML
		// que pega el operador; escaparlas como texto las rompería.
		foreach ( Vars::URL_KEYS as $key ) {
			$search[]  = '{{' . $key . '}}';
			$replace[] = $vars[ $key ] ?? '';
		}

		return str_replace( $search, $replace, $template );
	}
}
