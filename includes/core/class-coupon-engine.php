<?php
namespace AmirBooking\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Validación y aplicación de cupones de descuento.
 *
 * "Programable por fechas" se interpreta como la ventana en la que el
 * cupón puede USARSE (valid_from/valid_until comparado contra hoy, no
 * contra la fecha del tour) — es el patrón estándar de cupones tipo
 * promoción ("válido del 1 al 15 de agosto"), no una restricción sobre
 * qué tours se pueden reservar.
 */
class CouponEngine {

    /**
     * Valida un código de cupón para un tour específico.
     * Devuelve el registro del cupón si es válido, o un CouponResult de error.
     */
    public function validate( string $code, int $tour_id ): CouponResult {
        global $wpdb;

        $code = strtoupper( trim( $code ) );
        if ( $code === '' ) {
            return CouponResult::error( 'Código de cupón vacío' );
        }

        $coupon = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}amir_coupons WHERE code = %s AND active = 1",
            $code
        ) );

        if ( ! $coupon ) {
            return CouponResult::error( 'Cupón no válido' );
        }

        $today = current_time( 'Y-m-d' );
        if ( $coupon->valid_from && $today < $coupon->valid_from ) {
            return CouponResult::error( 'Este cupón todavía no está activo' );
        }
        if ( $coupon->valid_until && $today > $coupon->valid_until ) {
            return CouponResult::error( 'Este cupón ya venció' );
        }

        if ( $coupon->usage_limit !== null && (int) $coupon->times_used >= (int) $coupon->usage_limit ) {
            return CouponResult::error( 'Este cupón alcanzó su límite de usos' );
        }

        if ( $coupon->tour_id !== null && (int) $coupon->tour_id !== $tour_id ) {
            return CouponResult::error( 'Este cupón no aplica para este tour' );
        }

        return CouponResult::success( $coupon );
    }

    /** Monto de descuento en MXN sobre un subtotal, sin dejarlo negativo. */
    public function calculate_discount( object $coupon, float $subtotal ): float {
        if ( $coupon->discount_type === 'percent' ) {
            $discount = $subtotal * ( (float) $coupon->discount_value / 100 );
        } else {
            $discount = (float) $coupon->discount_value;
        }
        return round( min( $discount, $subtotal ), 2 );
    }

    /** Incrementa el contador de usos — llamar solo cuando la reserva se crea con éxito. */
    public function mark_used( int $coupon_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}amir_coupons SET times_used = times_used + 1 WHERE id = %d",
            $coupon_id
        ) );
    }
}

/**
 * Resultado de validar un cupón. PHP 7.4+ compatible (sin readonly).
 */
class CouponResult {
    /** @var bool */
    public $valid;
    /** @var object|null */
    public $coupon;
    /** @var string */
    public $error;

    private function __construct( bool $valid, $coupon, string $error ) {
        $this->valid  = $valid;
        $this->coupon = $coupon;
        $this->error  = $error;
    }

    public static function success( object $coupon ) {
        return new self( true, $coupon, '' );
    }

    public static function error( string $message ) {
        return new self( false, null, $message );
    }
}
