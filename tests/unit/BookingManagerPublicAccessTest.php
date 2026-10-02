<?php
use PHPUnit\Framework\TestCase;
use AmirBooking\Core\BookingManager;

/**
 * `authorize_public_access()` — token fuerte (256 bits) o email débil
 * (compatibilidad, documentado en CLAUDE.md regla 5). `require_token`
 * (hallazgo de auditoría de seguridad, 2026-09-24): las acciones que CAMBIAN
 * estado (cancelar una reserva) ahora exigen el token — cancelar la reserva
 * de un desconocido es un efecto real y no reversible por la víctima, y el
 * email es algo que un tercero puede conocer sin probar que controla esa
 * casilla. Las acciones de solo lectura (ver reserva, PDF) siguen aceptando
 * el fallback débil.
 */
final class BookingManagerPublicAccessTest extends TestCase {

    private function booking(): object {
        return (object) [
            'access_token'   => 'real-token-abc123',
            'customer_email' => 'ana@example.com',
        ];
    }

    public function test_accepts_the_correct_token(): void {
        $manager = new BookingManager();
        $this->assertTrue( $manager->authorize_public_access( $this->booking(), 'real-token-abc123', '' ) );
    }

    public function test_rejects_a_wrong_token(): void {
        $manager = new BookingManager();
        $this->assertFalse( $manager->authorize_public_access( $this->booking(), 'wrong-token', '' ) );
    }

    public function test_accepts_the_matching_email_by_default(): void {
        $manager = new BookingManager();
        $this->assertTrue( $manager->authorize_public_access( $this->booking(), '', 'ANA@example.com' ) );
    }

    public function test_rejects_a_non_matching_email(): void {
        $manager = new BookingManager();
        $this->assertFalse( $manager->authorize_public_access( $this->booking(), '', 'stranger@example.com' ) );
    }

    public function test_require_token_rejects_a_correct_email_without_a_token(): void {
        $manager = new BookingManager();
        $this->assertFalse( $manager->authorize_public_access( $this->booking(), '', 'ana@example.com', require_token: true ) );
    }

    public function test_require_token_still_accepts_the_correct_token(): void {
        $manager = new BookingManager();
        $this->assertTrue( $manager->authorize_public_access( $this->booking(), 'real-token-abc123', 'ana@example.com', require_token: true ) );
    }
}
