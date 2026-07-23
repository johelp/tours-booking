<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Motor de precios.
 *
 * Calcula el precio total de una reserva según el modelo del tour.
 *
 * Uso:
 *   $engine = new PricingEngine();
 *   $quote  = $engine->quote( tour_id: 3, schedule_id: 7, date: '2026-06-18', adults: 2, children: 1, babies: 0 );
 *   // $quote->total_mxn, $quote->breakdown, $quote->usd_reference
 */
class PricingEngine {

    // ── API pública ───────────────────────────────────────────────────────

    public function quote(
        int    $tour_id,
        int    $schedule_id,
        string $date,
        int    $adults      = 1,
        int    $children    = 0,
        int    $babies      = 0,
        string $coupon_code = ''
    ): PriceQuote {
        $tour = $this->get_tour( $tour_id );
        if ( ! $tour ) {
            return PriceQuote::error( 'Tour no encontrado' );
        }

        $quote = $tour->price_model === 'group'
            ? $this->quote_group( $tour_id, $schedule_id, $date, $adults + $children + $babies )
            : $this->quote_percapita( $tour_id, $schedule_id, $date, $adults, $children, $babies );

        if ( ! $quote->is_valid() || $coupon_code === '' ) {
            return $quote;
        }

        return $this->apply_coupon( $quote, $tour_id, $coupon_code );
    }

    /**
     * Aplica un cupón a una cotización ya calculada. Si el cupón no es
     * válido, devuelve la cotización sin descuento (el error del cupón
     * queda en $quote->coupon_error para que el frontend pueda mostrarlo
     * sin que eso bloquee ver el precio normal).
     */
    private function apply_coupon( PriceQuote $quote, int $tour_id, string $coupon_code ): PriceQuote {
        $coupons = new CouponEngine();
        $result  = $coupons->validate( $coupon_code, $tour_id );

        if ( ! $result->valid ) {
            $quote->coupon_error = $result->error;
            return $quote;
        }

        $discount = $coupons->calculate_discount( $result->coupon, $quote->total_mxn );

        $quote->coupon_code   = strtoupper( trim( $coupon_code ) );
        $quote->coupon_id     = (int) $result->coupon->id;
        $quote->discount_mxn  = $discount;
        $quote->total_mxn     = round( $quote->total_mxn - $discount, 2 );
        $quote->usd_reference = $this->convert_to_usd( $quote->total_mxn );

        return $quote;
    }

    // ── Modelo per-capita ─────────────────────────────────────────────────

    private function quote_percapita(
        int    $tour_id,
        int    $schedule_id,
        string $date,
        int    $adults,
        int    $children,
        int    $babies
    ): PriceQuote {
        $prices = $this->get_prices_for( $tour_id, $schedule_id, $date );

        $adult_price    = $this->find_price( $prices, 'adult' );
        $children_price = $this->find_price( $prices, 'child' );
        $baby_price     = $this->find_price( $prices, 'baby' );

        if ( $adult_price === null ) {
            return PriceQuote::error( 'Precio de adulto no configurado para este tour' );
        }

        $breakdown = [];
        $total     = 0.0;

        if ( $adults > 0 ) {
            $subtotal    = round( $adult_price * $adults, 2 );
            $total      += $subtotal;
            $breakdown[] = [
                'type'      => 'adult',
                'qty'       => $adults,
                'unit_mxn'  => $adult_price,
                'total_mxn' => $subtotal,
            ];
        }

        if ( $children > 0 && $children_price !== null ) {
            $subtotal    = round( $children_price * $children, 2 );
            $total      += $subtotal;
            $breakdown[] = [
                'type'      => 'child',
                'qty'       => $children,
                'unit_mxn'  => $children_price,
                'total_mxn' => $subtotal,
            ];
        }

        if ( $babies > 0 && $baby_price !== null && $baby_price > 0 ) {
            $subtotal    = round( $baby_price * $babies, 2 );
            $total      += $subtotal;
            $breakdown[] = [
                'type'      => 'baby',
                'qty'       => $babies,
                'unit_mxn'  => $baby_price,
                'total_mxn' => $subtotal,
            ];
        }

        return new PriceQuote( round( $total, 2 ), $this->convert_to_usd( $total ), $breakdown, 'percapita' );
    }

    // ── Modelo grupo (precio fijo por rango de personas) ──────────────────

    private function quote_group(
        int    $tour_id,
        int    $schedule_id,
        string $date,
        int    $total_pax
    ): PriceQuote {
        $prices = $this->get_prices_for( $tour_id, $schedule_id, $date );

        // Buscar el rango que cubre el número de personas
        $matched = null;
        foreach ( $prices as $price ) {
            if ( $price->person_type !== 'group' ) {
                continue;
            }
            $min = (int) $price->group_min;
            $max = (int) $price->group_max;
            if ( $total_pax >= $min && $total_pax <= $max ) {
                $matched = $price;
                break;
            }
        }

        if ( ! $matched ) {
            return PriceQuote::error(
                sprintf( 'Precio no configurado para %d personas en este tour', $total_pax )
            );
        }

        $total = (float) $matched->price_mxn;

        return new PriceQuote(
            round( $total, 2 ),
            $this->convert_to_usd( $total ),
            [ [
                'type'      => 'group',
                'qty'       => $total_pax,
                'group_min' => (int) $matched->group_min,
                'group_max' => (int) $matched->group_max,
                'total_mxn' => round( $total, 2 ),
            ] ],
            'group'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Obtiene los precios vigentes para un tour/horario/fecha.
     * Prioriza filas con valid_from/valid_until más específicas.
     */
    private function get_prices_for( int $tour_id, int $schedule_id, string $date ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT person_type, group_min, group_max, price_mxn
                 FROM {$wpdb->prefix}amir_prices
                 WHERE tour_id = %d
                   AND ( schedule_id = %d OR schedule_id IS NULL )
                   AND ( valid_from  IS NULL OR valid_from  <= %s )
                   AND ( valid_until IS NULL OR valid_until >= %s )
                 ORDER BY
                   -- Precios específicos de horario tienen prioridad
                   CASE WHEN schedule_id = %d THEN 0 ELSE 1 END,
                   -- Precios de temporada (con fechas) tienen prioridad sobre genéricos
                   CASE WHEN valid_from IS NOT NULL THEN 0 ELSE 1 END",
                $tour_id,
                $schedule_id,
                $date,
                $date,
                $schedule_id
            )
        ) ?? [];
    }

    private function find_price( array $prices, string $type ): ?float {
        foreach ( $prices as $price ) {
            if ( $price->person_type === $type ) {
                return (float) $price->price_mxn;
            }
        }
        return null;
    }

    private function get_tour( int $tour_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, price_model FROM {$wpdb->prefix}amir_tours WHERE id = %d",
                $tour_id
            )
        );
    }

    // ── Tipo de cambio USD ────────────────────────────────────────────────

    public function convert_to_usd( float $mxn ): float {
        $rate = $this->get_exchange_rate();
        if ( $rate <= 0 ) {
            return 0.0;
        }
        return round( $mxn / $rate, 2 );
    }

    public function get_exchange_rate(): float {
        $mode = get_option( 'amir_usd_rate_mode', 'auto' );

        if ( $mode === 'manual' ) {
            return (float) get_option( 'amir_usd_rate_manual', 17.00 );
        }

        // Modo auto: caché de 4 horas en WP Transients
        $cached = get_transient( 'amir_usd_mxn_rate' );
        if ( $cached !== false ) {
            return (float) $cached;
        }

        $rate = $this->fetch_live_rate();
        if ( $rate > 0 ) {
            set_transient( 'amir_usd_mxn_rate', $rate, 4 * HOUR_IN_SECONDS );
            return $rate;
        }

        // Fallback: usar manual si la API falla
        return (float) get_option( 'amir_usd_rate_manual', 17.00 );
    }

    private function fetch_live_rate(): float {
        // ExchangeRate-API (plan gratuito soporta consultas básicas)
        $response = wp_remote_get(
            'https://open.er-api.com/v6/latest/USD',
            [ 'timeout' => 5 ]
        );

        if ( is_wp_error( $response ) ) {
            return 0.0;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $rate = $body['rates']['MXN'] ?? 0.0;

        return (float) $rate;
    }
}

/**
 * Value object con la cotización de precio de una reserva.
 */
/**
 * Value object with price quote. PHP 7.4+ compatible.
 */
class PriceQuote {

    /** @var float */
    public $total_mxn;
    /** @var float */
    public $usd_reference;
    /** @var array */
    public $breakdown;
    /** @var string */
    public $model;
    /** @var string */
    public $error;
    /** @var string cupón aplicado con éxito, si lo hubo */
    public $coupon_code = '';
    /** @var int */
    public $coupon_id = 0;
    /** @var float monto descontado en MXN */
    public $discount_mxn = 0.0;
    /** @var string motivo por el que un cupón enviado NO se aplicó (sin bloquear el precio normal) */
    public $coupon_error = '';

    public function __construct( float $total_mxn, float $usd_reference, array $breakdown, string $model, string $error = '' ) {
        $this->total_mxn     = $total_mxn;
        $this->usd_reference = $usd_reference;
        $this->breakdown     = $breakdown;
        $this->model         = $model;
        $this->error         = $error;
    }

    public static function error( string $message ) {
        return new self( 0.0, 0.0, [], '', $message );
    }

    public function is_valid() {
        return $this->error === '' && $this->total_mxn > 0;
    }

    public function to_array() {
        return [
            'valid'         => $this->is_valid(),
            'total_mxn'     => $this->total_mxn,
            'usd_reference' => $this->usd_reference,
            'breakdown'     => $this->breakdown,
            'model'         => $this->model,
            'error'         => $this->error,
            'coupon_code'   => $this->coupon_code,
            'discount_mxn'  => $this->discount_mxn,
            'coupon_error'  => $this->coupon_error,
        ];
    }
}
