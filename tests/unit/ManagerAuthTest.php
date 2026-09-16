<?php

use PHPUnit\Framework\TestCase;
use TourFlow\Panel\ManagerAuth;

/**
 * ManagerAuth es el login propio del panel de gestión sin wp-admin (ver
 * PROMPT-PANEL-GESTOR.md) — el token HMAC es la pieza de seguridad real
 * acá (la cookie que reemplaza la sesión de wp-admin), así que se prueba
 * su round-trip y los casos de manipulación/expiración explícitamente.
 */
final class ManagerAuthTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__amir_test_users'] = [];
    }

    public function test_issue_and_verify_round_trip(): void {
        $GLOBALS['__amir_test_users'][42] = new WP_User( 42, [ 'manage_amir_booking' => true ] );

        $token = ManagerAuth::issue_token( 42 );

        $this->assertSame( 42, ManagerAuth::verify_token( $token ) );
    }

    public function test_tampered_token_is_rejected(): void {
        $GLOBALS['__amir_test_users'][42] = new WP_User( 42, [ 'manage_amir_booking' => true ] );

        $token          = ManagerAuth::issue_token( 42 );
        [ $encoded ]    = explode( '.', $token, 2 );
        $tampered_payload = base64_encode( wp_json_encode( [ 'user_id' => 999, 'expires_at' => time() + 3600 ] ) );
        // Mismo formato, payload distinto — la firma original no le corresponde.
        $forged = $tampered_payload . '.' . explode( '.', $token, 2 )[1];

        $this->assertNull( ManagerAuth::verify_token( $forged ) );
        // Confirma que el token real (sin tocar) sigue siendo válido — el
        // rechazo de arriba es por la firma, no por algo roto en el helper.
        $this->assertSame( 42, ManagerAuth::verify_token( $token ) );
    }

    public function test_expired_token_is_rejected(): void {
        $GLOBALS['__amir_test_users'][42] = new WP_User( 42, [ 'manage_amir_booking' => true ] );

        $encoded   = base64_encode( wp_json_encode( [ 'user_id' => 42, 'expires_at' => time() - 10 ] ) );
        $signature = hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) . 'tourflow_manager_panel' );
        $expired   = $encoded . '.' . $signature;

        $this->assertNull( ManagerAuth::verify_token( $expired ) );
    }

    public function test_malformed_token_is_rejected(): void {
        $this->assertNull( ManagerAuth::verify_token( 'not-a-real-token' ) );
        $this->assertNull( ManagerAuth::verify_token( '' ) );
    }

    public function test_token_rejected_once_user_loses_capability(): void {
        $GLOBALS['__amir_test_users'][42] = new WP_User( 42, [ 'manage_amir_booking' => true ] );
        $token = ManagerAuth::issue_token( 42 );

        // El operador le sacó el rol de Tour Manager después de emitido el
        // token — verify_token() no debe confiar solo en la firma.
        $GLOBALS['__amir_test_users'][42] = new WP_User( 42, [] );

        $this->assertNull( ManagerAuth::verify_token( $token ) );
    }

    public function test_user_can_access_requires_admin_or_tour_manager_capability(): void {
        $this->assertTrue( ManagerAuth::user_can_access( new WP_User( 1, [ 'manage_options' => true ] ) ) );
        $this->assertTrue( ManagerAuth::user_can_access( new WP_User( 2, [ 'manage_amir_booking' => true ] ) ) );
        $this->assertFalse( ManagerAuth::user_can_access( new WP_User( 3, [] ) ) );
    }

    /** "Panel rápido" (2026-09-15) — un usuario con SOLO la capability chica ya puede entrar al panel; ManagerPanel::is_quick_only() es quien decide después que solo vea esa sección. */
    public function test_user_can_access_accepts_quick_panel_capability(): void {
        $this->assertTrue( ManagerAuth::user_can_access( new WP_User( 4, [ \AmirBooking\Core\QuickPanelRole::CAP_VIEW => true ] ) ) );
        $this->assertTrue( ManagerAuth::user_can_access( new WP_User( 5, [
            \AmirBooking\Core\QuickPanelRole::CAP_VIEW     => true,
            \AmirBooking\Core\QuickPanelRole::CAP_PAYMENTS => true,
        ] ) ) );
    }
}
