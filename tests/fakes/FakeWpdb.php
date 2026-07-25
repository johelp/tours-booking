<?php
/**
 * Doble de prueba minimalista de $wpdb.
 *
 * No es un mock genérico: expone propiedades públicas para que cada test
 * cargue exactamente las filas que espera recibir, y decide qué devolver
 * mirando qué tabla aparece en el texto de la consulta. Alcanza para
 * probar lógica de dominio que hace 1-2 queries simples; si una clase
 * necesita queries más complejas, ese es el momento de crecer este fake
 * (o de pasar a un entorno de integración real con wp-phpunit).
 */
class FakeWpdb {

    public string $prefix = 'wp_';

    /** @var object|null Fila que devuelve get_row() si la consulta menciona amir_tours */
    public $tour_row = null;

    /** @var object|null Fila que devuelve get_row() si la consulta menciona amir_bookings (y no amir_tours) */
    public $booking_row = null;

    /** @var array Filas que devuelve get_results() si la consulta menciona amir_prices */
    public array $price_rows = [];

    /** @var mixed Valor que devuelve get_var() */
    public $var_result = null;

    /** @var int Se autoincrementa en cada insert(); refleja $wpdb->insert_id */
    public int $insert_id = 0;

    /** @var array Último $data pasado a insert(), para assertions */
    public array $last_insert_data = [];

    /** @var string Última tabla pasada a insert() */
    public string $last_insert_table = '';

    /** @var array Último $data pasado a update(), para assertions */
    public array $last_update_data = [];

    /** @var array Último $where pasado a update(), para assertions */
    public array $last_update_where = [];

    public function prepare( string $query, ...$args ): string {
        return $query;
    }

    public function get_row( string $query ) {
        if ( str_contains( $query, 'amir_tours' ) ) {
            return $this->tour_row;
        }
        if ( str_contains( $query, 'amir_bookings' ) ) {
            return $this->booking_row;
        }
        return null;
    }

    public function get_results( string $query ): array {
        if ( str_contains( $query, 'amir_prices' ) ) {
            return $this->price_rows;
        }
        return [];
    }

    public function get_var( string $query ) {
        return $this->var_result;
    }

    public function get_col( string $query ): array {
        return [];
    }

    public function insert( string $table, array $data, $format = null ) {
        $this->last_insert_table = $table;
        $this->last_insert_data  = $data;
        $this->insert_id++;
        return 1;
    }

    public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
        $this->last_update_data  = $data;
        $this->last_update_where = $where;
        return 1;
    }

    public function query( string $sql ) {
        return true;
    }
}
