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
     * Valida un código de cupón para un tour Y/O una habitación (nunca los
     * dos a la vez — un cupón scoped a un tour puntual jamás aplica a
     * habitaciones, y viceversa; un cupón sin tour_id NI room_id es global,
     * aplica a cualquiera de los dos). $room_id se agregó en 2026-08-04
     * (§ 16.21 CONTRIBUTING.md) — antes los cupones eran 100% tour-only.
     * Devuelve el registro del cupón si es válido, o un CouponResult de error.
     */
    public function validate( string $code, int $tour_id = 0, int $room_id = 0 ): CouponResult {
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

        if ( $coupon->tour_id !== null ) {
            if ( $room_id > 0 || (int) $coupon->tour_id !== $tour_id ) {
                return CouponResult::error( 'Este cupón no aplica para este tour' );
            }
        }

        if ( isset( $coupon->room_id ) && $coupon->room_id !== null ) {
            if ( $tour_id > 0 || (int) $coupon->room_id !== $room_id ) {
                return CouponResult::error( 'Este cupón no aplica para esta habitación' );
            }
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
