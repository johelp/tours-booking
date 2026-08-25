<?php
namespace TourFlow\Rooms;

defined( 'ABSPATH' ) || exit;

// BookingResult vive DENTRO de class-booking-manager.php (varias clases por
// archivo) — el autoloader por convención kebab-case no lo encuentra solo.
// require_once explícito: sin esto, si el primer uso de RoomBookingResult
// en un request ocurre ANTES de que algo más haya cargado BookingManager
// (ej. una validación temprana en RoomBookingManager::create_pending() que
// devuelve RoomBookingResult::error() antes de tocar BookingManager), PHP
// no puede resolver el "extends" y fatalea con "Class not found".
require_once __DIR__ . '/../core/class-booking-manager.php';

/**
 * Extiende AmirBooking\Core\BookingResult (no lo duplica) — así un resultado
 * de RoomBookingManager::create_pending() se puede pasar tal cual a
 * PaymentGatewayInterface::create_payment(), que está tipado contra
 * BookingResult. Mismo criterio de "generalizar en vez de duplicar" que ya
 * se aplicó a amir_bookings (§ 16 CONTRIBUTING.md).
 */
final class RoomBookingResult extends \AmirBooking\Core\BookingResult {

	public static function error( string $message ): self {
		return new self( false, 0, '', 0.0, null, $message );
	}
}
