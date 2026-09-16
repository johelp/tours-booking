<?php
namespace AmirBooking\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Overrides editables (desde TourFlow → ✉️ Emails → Editar textos) de los
 * textos que arma cada clase Xxx­Email — guardados en una sola opción
 * serializada, sin tabla nueva.
 *
 * Reemplaza la dependencia de __()/.mo para el contenido de los emails: los
 * defaults viven hardcodeados en cada clase de email (ver BaseEmail::text())
 * en español e inglés reales, así que un idioma sin .mo compilado (ej. 'en',
 * que nunca tuvo amir-booking-en_US.mo) ya no cae de vuelta al msgid en
 * español — bug real reportado por el cliente.
 *
 * Estructura de la opción: [ tipo => [ idioma => [ clave => texto ] ] ].
 */
class EmailTexts {

    private const OPTION = 'amir_email_texts';

    /** Override guardado, o null si no hay (el caller decide el fallback). */
    public static function get_override( string $type, string $key, string $lang ): ?string {
        $all = get_option( self::OPTION, [] );
        $val = $all[ $type ][ $lang ][ $key ] ?? '';
        return $val !== '' ? (string) $val : null;
    }

    /** Todos los overrides guardados de un tipo+idioma (para precargar el form de edición). */
    public static function overrides_for( string $type, string $lang ): array {
        $all = get_option( self::OPTION, [] );
        return is_array( $all[ $type ][ $lang ] ?? null ) ? $all[ $type ][ $lang ] : [];
    }

    /**
     * Guarda (o limpia, si el valor llega vacío — así "Restaurar default" es
     * simplemente dejar el campo en blanco y guardar) los textos de un
     * tipo+idioma de una sola vez.
     */
    public static function save( string $type, string $lang, array $texts ): void {
        $all = get_option( self::OPTION, [] );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        foreach ( $texts as $key => $val ) {
            $val = trim( wp_unslash( (string) $val ) );
            if ( $val === '' ) {
                unset( $all[ $type ][ $lang ][ $key ] );
            } else {
                $all[ $type ][ $lang ][ $key ] = $val;
            }
        }
        // Limpiar ramas vacías para no dejar basura creciendo en la opción.
        if ( empty( $all[ $type ][ $lang ] ) ) {
            unset( $all[ $type ][ $lang ] );
        }
        if ( empty( $all[ $type ] ) ) {
            unset( $all[ $type ] );
        }
        update_option( self::OPTION, $all );
    }
}
