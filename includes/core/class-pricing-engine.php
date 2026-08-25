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
        string $coupon_code = '',
        array  $addons      = [],
        string $lang        = 'es'
    ): PriceQuote {
        $tour = $this->get_tour( $tour_id );
        if ( ! $tour ) {
            return PriceQuote::error( 'Tour no encontrado' );
        }

        $quote = $tour->price_model === 'group'
            ? $this->quote_group( $tour_id, $schedule_id, $date, $adults + $children + $babies )
            : $this->quote_percapita( $tour_id, $schedule_id, $date, $adults, $children, $babies );

        if ( ! $quote->is_valid() ) {
            return $quote;
        }

        // El cupón descuenta solo el precio del tour — los add-ons se
        // cobran siempre a precio completo (equipo alquilado a terceros,
        // comida con costo real: un cupón de marketing del tour no debería
        // comerse ese margen). Por eso se aplica ANTES de sumar add-ons.
        if ( $coupon_code !== '' ) {
            $quote = $this->apply_coupon( $quote, $tour_id, $coupon_code );
        }

        if ( ! empty( $addons ) ) {
            $quote = $this->apply_addons( $quote, $tour_id, $addons, $adults + $children, $lang );
        }

        // Depósito parcial ("Depósito parcial por tour", Pro Max) — estimado
        // para mostrarle al cliente ANTES de agregar al carrito cuánto paga
        // ahora vs. después. El cálculo real y autoritativo (que además
        // respeta la exclusión mutua con cobro diferido de proveedor/"solo a
        // pedido") vive en BookingManager::create_pending() — acá alcanza
        // con el % configurado en el tour, sin duplicar esa lógica completa.
        $deposit_pct = (int) ( $tour->deposit_enabled ?? 0 ) ? (int) ( $tour->deposit_pct ?? 0 ) : 0;
        if ( $deposit_pct >= 1 && $deposit_pct <= 99 && $quote->is_valid() ) {
            $quote->deposit_pct   = $deposit_pct;
            $quote->deposit_mxn   = round( $quote->total_mxn * $deposit_pct / 100, 2 );
            $quote->remaining_mxn = round( $quote->total_mxn - $quote->deposit_mxn, 2 );
        }

        return $quote;
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

    /**
     * Suma servicios extra (add-ons) ya validados contra la definición real
     * del tour — nunca confía en el precio/nombre que mande el cliente,
     * solo en el id + cantidad pedida. $requested viene como [{id, qty}, ...].
     * $people_cap = adultos+niños de la reserva: un addon 'per_unit' nunca
     * puede pedirse en más cantidad que gente hay en la reserva (decisión
     * del cliente — no tiene sentido pedir 5 cenas para 2 personas).
     */
    private function apply_addons( PriceQuote $quote, int $tour_id, array $requested, int $people_cap, string $lang = 'es' ): PriceQuote {
        global $wpdb;

        $ids = array_values( array_unique( array_filter( array_map(
            fn( $r ) => (int) ( $r['id'] ?? 0 ), $requested
        ) ) ) );
        if ( empty( $ids ) ) {
            return $quote;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, pricing_type, name_es, name_en, content_i18n, price_mxn
             FROM {$wpdb->prefix}amir_addons
             WHERE tour_id = %d AND active = 1 AND id IN ($placeholders)",
            array_merge( [ $tour_id ], $ids )
        ) ) ?? [];

        $by_id = [];
        foreach ( $rows as $row ) {
            $by_id[ (int) $row->id ] = $row;
        }

        $addons_total = 0.0;

        foreach ( $requested as $r ) {
            $addon_id = (int) ( $r['id'] ?? 0 );
            $addon    = $by_id[ $addon_id ] ?? null;
            if ( ! $addon ) {
                continue; // id inexistente, inactivo, o de otro tour — se ignora, no rompe el quote
            }

            $qty_requested = max( 0, (int) ( $r['qty'] ?? 0 ) );
            if ( $addon->pricing_type === 'flat' ) {
                $qty = $qty_requested > 0 ? 1 : 0;
            } else {
                $qty = min( $qty_requested, max( 0, $people_cap ) );
            }
            if ( $qty <= 0 ) {
                continue;
            }

            $unit_price = (float) $addon->price_mxn;
            $subtotal   = round( $unit_price * $qty, 2 );
            $addons_total += $subtotal;

            $quote->breakdown[] = [
                'type'      => 'addon',
                'addon_id'  => $addon_id,
                'name'      => Languages::tour_field( $addon, 'name', $lang ) ?: $addon->name_es,
                'qty'       => $qty,
                'unit_mxn'  => $unit_price,
                'total_mxn' => $subtotal,
            ];
        }

        if ( $addons_total > 0 ) {
            $quote->addons_mxn   = round( $quote->addons_mxn + $addons_total, 2 );
            $quote->total_mxn    = round( $quote->total_mxn + $addons_total, 2 );
            $quote->usd_reference = $this->convert_to_usd( $quote->total_mxn );
        }

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

        // Bug real y grave encontrado en vivo 2026-08-12 (caliafarm.com/stag,
        // tour de proveedor externo con price_mxn=0 cargado — nunca se le
        // configuró un precio real): $adult_price=0.0 no es null, así que
        // pasaba el chequeo de arriba, pero el total resultante ($0) hacía
        // que PriceQuote::is_valid() lo rechazara (exige total_mxn > 0) SIN
        // que nada acá seteara un mensaje de error — el widget recibía un
        // 422 con error:"" y lo tragaba en silencio (ver también el fix del
        // lado del cliente): el sitio quedaba "roto" sin ningún aviso, ni
        // para el cliente final ni para el operador. Ahora cualquier total
        // en $0 (precio mal configurado, no un cupón del 100% — esos se
        // aplican después de este punto) devuelve un error explícito.
        if ( $total <= 0 ) {
            return PriceQuote::error( 'Precio no configurado para este tour — contactá al operador' );
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

        // Mismo bug/fix que quote_percapita() — ver el comentario ahí.
        if ( $total <= 0 ) {
            return PriceQuote::error(
                sprintf( 'Precio no configurado para %d personas en este tour — contactá al operador', $total_pax )
            );
        }

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

    // ── Costo del proveedor externo (marketplace, § 11 CONTRIBUTING.md) ────

    /**
     * Mismo criterio de selección de fila que quote() (vigencia/horario),
     * pero suma provider_cost_mxn en vez de price_mxn — lo que le cuesta a
     * TourFlow la reserva, no lo que se le cobra al cliente. Usado por el
     * listener de amir_provider_booking_approved (class-plugin.php) para
     * generar la fila del ledger en amir_provider_payouts.
     */
    public function calculate_provider_cost(
        int    $tour_id,
        int    $schedule_id,
        string $date,
        int    $adults   = 1,
        int    $children = 0,
        int    $babies   = 0
    ): float {
        $tour   = $this->get_tour( $tour_id );
        $prices = $this->get_prices_for( $tour_id, $schedule_id, $date );

        if ( $tour && $tour->price_model === 'group' ) {
            $total_pax = $adults + $children + $babies;
            foreach ( $prices as $price ) {
                if ( $price->person_type !== 'group' ) {
                    continue;
                }
                if ( $total_pax >= (int) $price->group_min && $total_pax <= (int) $price->group_max ) {
                    return round( (float) $price->provider_cost_mxn, 2 );
                }
            }
            return 0.0;
        }

        $adult_cost = $this->find_price( $prices, 'adult', 'provider_cost_mxn' ) ?? 0.0;
        $child_cost = $this->find_price( $prices, 'child', 'provider_cost_mxn' ) ?? 0.0;
        $baby_cost  = $this->find_price( $prices, 'baby', 'provider_cost_mxn' ) ?? 0.0;

        $total = ( $adult_cost * $adults ) + ( $child_cost * $children ) + ( $baby_cost * $babies );
        return round( $total, 2 );
    }

    /**
     * Precio mínimo vigente de adulto, sin fecha/horario puntual — para el
     * "desde $X" de una tarjeta de catálogo (flujo Explorar, § 16.46
     * CONTRIBUTING.md). Mismo criterio de vigencia que get_prices_for()
     * (valid_from/valid_until contra hoy), pero sin filtrar por schedule_id:
     * una tarjeta de catálogo no tiene horario elegido todavía. Devuelve
     * null si el tour no tiene ninguna fila 'adult' vigente (ej. modelo de
     * precio por grupo, que usa person_type='group').
     */
    public function min_adult_price( int $tour_id ): ?float {
        global $wpdb;
        $today = current_time( 'Y-m-d' );
        $price = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices
                 WHERE tour_id = %d AND person_type = 'adult'
                   AND ( valid_from  IS NULL OR valid_from  <= %s )
                   AND ( valid_until IS NULL OR valid_until >= %s )",
                $tour_id,
                $today,
                $today
            )
        );
        if ( $price !== null ) {
            return (float) $price;
        }

        // Bug real encontrado 2026-08-25 armando el selector de variantes
        // (§ 16.93 CONTRIBUTING.md, [flow_booking_variants]): un tour con
        // price_model='group' (precio fijo por rango de personas, ej. "la
        // habitación completa cuesta $X sin importar cuántos la ocupan") no
        // tiene NINGUNA fila person_type='adult' — sin este fallback, el
        // "desde $X" quedaba siempre vacío en CUALQUIER lugar que reusara
        // este helper (tarjetas de [flow_tour_list]/Discovery también, no
        // solo el selector nuevo).
        $group_price = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(price_mxn) FROM {$wpdb->prefix}amir_prices
                 WHERE tour_id = %d AND person_type = 'group'
                   AND ( valid_from  IS NULL OR valid_from  <= %s )
                   AND ( valid_until IS NULL OR valid_until >= %s )",
                $tour_id,
                $today,
                $today
            )
        );
        return $group_price !== null ? (float) $group_price : null;
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
                "SELECT person_type, group_min, group_max, price_mxn, provider_cost_mxn
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

    private function find_price( array $prices, string $type, string $column = 'price_mxn' ): ?float {
        foreach ( $prices as $price ) {
            if ( $price->person_type === $type ) {
                return (float) $price->$column;
            }
        }
        return null;
    }

    private function get_tour( int $tour_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, price_model, deposit_enabled, deposit_pct FROM {$wpdb->prefix}amir_tours WHERE id = %d",
                $tour_id
            )
        );
    }

    // ── Tipo de cambio USD ────────────────────────────────────────────────
    // "USD" acá es siempre la moneda de referencia para mostrarle un precio
    // aproximado a turistas extranjeros — la moneda en la que realmente se
    // cobra (Stripe) es la que esté configurada en amir_currency, sea cual sea.

    public function convert_to_usd( float $amount ): float {
        if ( Currency::code() === 'USD' ) {
            return round( $amount, 2 ); // ya está en USD, no hay nada que convertir
        }
        $rate = $this->get_exchange_rate();
        if ( $rate <= 0 ) {
            return 0.0;
        }
        return round( $amount / $rate, 2 );
    }

    public function get_exchange_rate(): float {
        if ( Currency::code() === 'USD' ) {
            return 1.0;
        }

        $mode = get_option( 'amir_usd_rate_mode', 'auto' );

        if ( $mode === 'manual' ) {
            return (float) get_option( 'amir_usd_rate_manual', 17.00 );
        }

        // Modo auto: caché de 4 horas en WP Transients, una por moneda
        // (para no servir una tasa vieja de otra moneda si el operador cambia
        // la configuración).
        $transient_key = 'amir_usd_rate_' . strtolower( Currency::code() );
        $cached        = get_transient( $transient_key );
        if ( $cached !== false ) {
            return (float) $cached;
        }

        $rate = $this->fetch_live_rate();
        if ( $rate > 0 ) {
            set_transient( $transient_key, $rate, 4 * HOUR_IN_SECONDS );
            return $rate;
        }

        // Fallback: usar manual si la API falla
        return (float) get_option( 'amir_usd_rate_manual', 17.00 );
    }

    private function fetch_live_rate(): float {
        // ExchangeRate-API (plan gratuito): USD como base devuelve la tasa
        // contra TODAS las monedas soportadas en una sola consulta, así que
        // alcanza con leer la clave de la moneda configurada.
        $response = wp_remote_get(
            'https://open.er-api.com/v6/latest/USD',
            [ 'timeout' => 5 ]
        );

        if ( is_wp_error( $response ) ) {
            return 0.0;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $rate = $body['rates'][ Currency::code() ] ?? 0.0;

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
    /** @var float suma de add-ons ya incluida en total_mxn (a precio completo, el cupón no la toca) */
    public $addons_mxn = 0.0;
    /**
     * @var int 0-99, % de depósito activo en el tour ("Depósito parcial por
     * tour", Pro Max) — 0 = sin depósito, se cobra el total completo.
     * total_mxn NUNCA cambia de significado por esto, sigue siendo el
     * precio total — deposit_mxn/remaining_mxn son solo para mostrarle al
     * cliente cuánto paga ahora vs. después ANTES de agregar al carrito.
     */
    public $deposit_pct = 0;
    /** @var float monto del depósito (lo que se cobra ahora), 0 si no hay depósito activo */
    public $deposit_mxn = 0.0;
    /** @var float total_mxn - deposit_mxn, 0 si no hay depósito activo */
    public $remaining_mxn = 0.0;

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
            'addons_mxn'    => $this->addons_mxn,
            'deposit_pct'    => $this->deposit_pct,
            'deposit_mxn'    => $this->deposit_mxn,
            'remaining_mxn'  => $this->remaining_mxn,
        ];
    }
}
