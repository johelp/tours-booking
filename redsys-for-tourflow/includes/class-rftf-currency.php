<?php
namespace RedsysForTourFlow;

defined( 'ABSPATH' ) || exit;

/**
 * Redsys pide la moneda como código numérico ISO 4217 (no "EUR"/"MXN") y el
 * importe en unidad menor (céntimos) como entero. Solo cubre las monedas que
 * TourFlow ya soporta (AmirBooking\Core\Currency::SUPPORTED + las que tienen
 * símbolo definido) — no hace falta el catálogo completo de ~180 monedas
 * que trae el módulo oficial, esto es un gateway pensado para comercios
 * europeos operando en EUR.
 */
class Currency {

	private const NUMERIC_CODES = [
		'EUR' => 978,
		'USD' => 840,
		'MXN' => 484,
		'ARS' => 32,
		'GBP' => 826,
		'BRL' => 986,
	];

	public static function numeric_code( string $iso_code ): int {
		return self::NUMERIC_CODES[ strtoupper( $iso_code ) ] ?? 0;
	}

	public static function is_supported( string $iso_code ): bool {
		return isset( self::NUMERIC_CODES[ strtoupper( $iso_code ) ] );
	}

	/** Todas las monedas soportadas acá usan 2 decimales (igual asunción que Core\Currency::format()). */
	public static function to_minor_units( float $amount ): int {
		return (int) round( $amount * 100 );
	}
}
