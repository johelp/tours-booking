<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Moneda base del operador (Configuración → Moneda).
 *
 * Las columnas y variables históricas del código (`total_mxn`, `price_mxn`,
 * `usd_reference`, etc.) conservan su nombre por compatibilidad — pero
 * guardan el monto en la moneda que esté configurada acá, no necesariamente
 * pesos mexicanos. Renombrarlas implicaría tocar el esquema de DB y docenas
 * de archivos sin beneficio real; esta clase es el único punto que necesita
 * saber "qué moneda es esta" para mostrarla correctamente.
 */
class Currency {

    public const SUPPORTED = [ 'MXN', 'ARS', 'USD', 'EUR' ];

    private const SYMBOLS = [
        'MXN' => '$',
        'ARS' => '$',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'BRL' => 'R$',
    ];

    public static function code(): string {
        $code = strtoupper( trim( (string) get_option( 'amir_currency', 'MXN' ) ) );
        return $code !== '' ? $code : 'MXN';
    }

    public static function symbol( ?string $code = null ): string {
        $code = $code ?? self::code();
        return self::SYMBOLS[ $code ] ?? ( $code . ' ' );
    }

    /**
     * "$1,234.00 MXN" / "€980.00 EUR" — mismo separador de miles/decimales
     * que usaba el código histórico, solo cambia el símbolo y el código.
     */
    public static function format( float $amount, int $decimals = 2 ): string {
        return self::symbol() . number_format( $amount, $decimals ) . ' ' . self::code();
    }
}
