<?php
/**
 * BT Quote — pricing engine.
 *
 * Ported 1:1 from the WPCode "Server-Side Pricing Endpoint" snippet.
 * Same formulas, same tables, same response shape — drop-in replacement for
 * POST /wp-json/boomerts/v1/price. Registered with override=true so the plugin
 * wins even if the old snippet is still active.
 *
 * The engine is exposed as plain PHP (btq_price) so the catalog, portal, and
 * future quote/checkout code can price server-side without an HTTP round trip.
 */
if (!defined('ABSPATH')) exit;

/**
 * FACTORY DEFAULT pricing tables — the numbers the shop runs on today.
 * Never edited by the admin UI; "Reset to default" always lands back here.
 */
function btq_pricing_defaults() {
    static $t = null;
    if ($t === null) {
        $t = array(
            'PRINT_TIERS' => array(
                array(1,23.75),array(2,36.00),array(3,48.00),array(4,60.00),array(7,98.00),
                array(12,137.28),array(24,158.84),array(36,194.80),array(48,232.18),array(60,274.20),
                array(72,324.07),array(84,357.29),array(96,389.33),array(108,468.23),array(120,502.44),
                array(132,539.62),array(144,574.42),array(156,606.84),array(168,645.20),array(180,682.38),
                array(192,718.37),array(204,886.69),array(216,928.15),array(228,968.43),array(240,1007.52),
                array(252,1045.42),array(264,1086.49),array(276,1126.77),array(288,1166.26),array(300,1265.40),
                array(400,1649.20),array(500,2021.50),array(600,2389.80),array(750,2942.25),array(1000,3823.00),
            ),
            'GMT_DISC' => array(
                array('mx'=>11,  'mult'=>1.00),array('mx'=>23,  'mult'=>0.97),
                array('mx'=>35,  'mult'=>0.95),array('mx'=>47,  'mult'=>0.92),
                array('mx'=>59,  'mult'=>0.90),array('mx'=>71,  'mult'=>0.87),
                array('mx'=>99,  'mult'=>0.84),array('mx'=>199, 'mult'=>0.81),
                array('mx'=>299, 'mult'=>0.70),array('mx'=>9999,'mult'=>0.66),
            ),
            'LOC2_TIERS' => array(
                array(1,8.00),array(12,6.00),array(24,5.00),array(36,4.50),array(48,4.00),
                array(60,3.50),array(84,2.75),array(120,2.10),array(228,2.00),array(324,1.50),array(400,1.25),
            ),
            'LOC3_TIERS' => array(
                array(1,6.00),array(12,4.50),array(24,3.75),array(36,3.38),array(48,3.00),
                array(60,2.63),array(84,2.06),array(120,1.58),array(228,1.50),array(324,1.13),array(400,0.94),
            ),
            'GARMENTS' => array(
                'g5000'=>5.95, 'g8000'=>6.95, 'g64000'=>7.45,
                'g18000'=>15.95, 'g18500'=>20.95, 'bc3001'=>9.45,
                'nl3600'=>8.95, 'c25100'=>7.95,
                'supplied'=>0.00,   // customer supplies garments — decoration cost only
                'custom'=>null,     // retail price passed in via 'retail' param
            ),
            'BREAKS' => array(1,2,3,4,6,12,24,36,48,72,96,120,144,168,192,240,300,500,750,1000),
            // Embroidery: text band prices are the single source of truth.
            // Logo = text * EMB_LOGO_MULT. Hard-to-handle = logo + EMB_HARD_ADDER.
            'EMB_TEXT_TIERS' => array(
                array(1,20.00),array(2,18.00),array(3,16.00),array(4,14.00),array(7,13.00),
                array(12,12.00),array(18,12.00),array(24,9.56),array(36,7.96),array(48,7.67),
                array(60,7.54),array(72,7.50),
            ),
            'EMB_QUOTE_MIN'  => 84,      // 84+ on every embroidery tier is "by quote"
            'EMB_LOGO_MULT'  => 1.55,
            'EMB_HARD_ADDER' => 10.00,
            // Handling per piece (threshold at 100 pcs is fixed).
            'HANDLING_UNDER' => 0.50,
            'HANDLING_OVER'  => 0.25,
        );
    }
    return $t;
}

/**
 * LIVE pricing tables = factory defaults + saved admin overrides
 * (option btq_pricing_overrides, managed on the BT Quote → Pricing page).
 * Only price/multiplier values can be overridden; qty anchors are fixed.
 */
function btq_pricing_tables() {
    $t  = btq_pricing_defaults();
    $ov = get_option('btq_pricing_overrides', array());
    if (is_array($ov) && $ov) {
        // Tier tables: override price at each fixed qty anchor, by index.
        foreach (array('PRINT_TIERS', 'LOC2_TIERS', 'LOC3_TIERS', 'EMB_TEXT_TIERS') as $sec) {
            if (!empty($ov[$sec]) && is_array($ov[$sec])) {
                foreach ($ov[$sec] as $i => $price) {
                    if (isset($t[$sec][$i])) $t[$sec][$i][1] = (float) $price;
                }
            }
        }
        // Garment qty-discount multipliers, by index.
        if (!empty($ov['GMT_DISC']) && is_array($ov['GMT_DISC'])) {
            foreach ($ov['GMT_DISC'] as $i => $mult) {
                if (isset($t['GMT_DISC'][$i])) $t['GMT_DISC'][$i]['mult'] = (float) $mult;
            }
        }
        // Garment prices, by key ('supplied' and 'custom' are fixed by design).
        if (!empty($ov['GARMENTS']) && is_array($ov['GARMENTS'])) {
            foreach ($ov['GARMENTS'] as $k => $price) {
                if (isset($t['GARMENTS'][$k]) && $k !== 'supplied' && $k !== 'custom') {
                    $t['GARMENTS'][$k] = (float) $price;
                }
            }
        }
        // Scalars.
        if (isset($ov['EMB_LOGO_MULT']))  $t['EMB_LOGO_MULT']  = (float) $ov['EMB_LOGO_MULT'];
        if (isset($ov['EMB_HARD_ADDER'])) $t['EMB_HARD_ADDER'] = (float) $ov['EMB_HARD_ADDER'];
        if (isset($ov['EMB_QUOTE_MIN']))  $t['EMB_QUOTE_MIN']  = max(1, (int) $ov['EMB_QUOTE_MIN']);
        if (isset($ov['HANDLING_UNDER'])) $t['HANDLING_UNDER'] = (float) $ov['HANDLING_UNDER'];
        if (isset($ov['HANDLING_OVER']))  $t['HANDLING_OVER']  = (float) $ov['HANDLING_OVER'];
    }
    return apply_filters('btq_pricing_tables', $t);
}

/** Step-tier lookup: highest tier whose min qty <= q. */
function btq_tier_lookup($tiers, $q) {
    $rate = $tiers[0][1];
    foreach ($tiers as $tier) {
        if ($q >= $tier[0]) $rate = $tier[1];
        else break;
    }
    return $rate;
}

/** Print lot cost — linear interpolation between tier anchors. */
function btq_print_lot($q) {
    $T = btq_pricing_tables(); $PT = $T['PRINT_TIERS'];
    $q = min(1000, max(1, $q));
    for ($i = 0; $i < count($PT) - 1; $i++) {
        if ($q >= $PT[$i][0] && $q <= $PT[$i+1][0]) {
            $q0=$PT[$i][0];   $t0=$PT[$i][1];
            $q1=$PT[$i+1][0]; $t1=$PT[$i+1][1];
            return $t0 + ($q-$q0)/($q1-$q0)*($t1-$t0);
        }
    }
    return $PT[count($PT)-1][1];
}

function btq_print_per_shirt($q) {
    return btq_print_lot($q) / $q;
}

/** Garment cost after quantity discount curve. */
function btq_gmt_cost($q, $retail) {
    if ($retail <= 0) return 0;
    $T = btq_pricing_tables();
    foreach ($T['GMT_DISC'] as $d) {
        if ($q <= $d['mx']) return $retail * $d['mult'];
    }
    return $retail * 0.66;
}

function btq_handling($q) {
    $T = btq_pricing_tables();
    return $q < 100 ? $T['HANDLING_UNDER'] : $T['HANDLING_OVER'];
}

/** Print per-shirt: print + garment + handling + extra locations. */
function btq_print_calc($q, $retail, $locations) {
    $T = btq_pricing_tables();
    return btq_print_per_shirt($q)
         + btq_gmt_cost($q, $retail)
         + btq_handling($q)
         + ($locations >= 2 ? btq_tier_lookup($T['LOC2_TIERS'], $q) : 0)
         + ($locations >= 3 ? btq_tier_lookup($T['LOC3_TIERS'], $q) : 0);
}

/** Embroidery decoration cost per piece (all-in). Null = "by quote" range. */
function btq_emb_decoration($q, $type) {
    $T = btq_pricing_tables();
    if ($q >= $T['EMB_QUOTE_MIN']) return null;
    $base = btq_tier_lookup($T['EMB_TEXT_TIERS'], $q);
    if ($type === 'logo') return $base * $T['EMB_LOGO_MULT'];
    if ($type === 'hard') return $base * $T['EMB_LOGO_MULT'] + $T['EMB_HARD_ADDER'];
    return $base; // text
}

/** Embroidery per piece = decoration + garment (garment 0 when supplied). Null = by quote. */
function btq_emb_per_shirt($q, $retail, $type) {
    $dec = btq_emb_decoration($q, $type);
    if ($dec === null) return null;
    return $dec + btq_gmt_cost($q, $retail);
}

/**
 * Full pricing calculation. $args:
 *   qty (int), garment (key), locations (int, print), method 'print'|'embroidery',
 *   embType 'text'|'logo'|'hard', retail (float, when garment='custom').
 * Returns the exact response array the old snippet produced, or WP_Error.
 */
function btq_price($args) {
    $T = btq_pricing_tables();

    $qty       = isset($args['qty']) ? intval($args['qty']) : 0;
    $garment   = isset($args['garment']) ? (string) $args['garment'] : '';
    $locations = isset($args['locations']) ? intval($args['locations']) : 0;
    $method    = isset($args['method']) && $args['method'] !== '' ? (string) $args['method'] : 'print';
    $embType   = isset($args['embType']) && $args['embType'] !== '' ? (string) $args['embType'] : 'text';

    if ($qty < 1 || $qty > 1000)
        return new WP_Error('invalid_qty', 'Invalid quantity', array('status' => 400));
    if (!array_key_exists($garment, $T['GARMENTS']))
        return new WP_Error('invalid_garment', 'Invalid garment', array('status' => 400));

    if ($method === 'embroidery') {
        if (!in_array($embType, array('text','logo','hard'), true))
            return new WP_Error('invalid_embtype', 'Invalid embroidery type', array('status' => 400));
    } else {
        if ($locations < 1 || $locations > 3)
            return new WP_Error('invalid_locations', 'Invalid locations', array('status' => 400));
    }

    // Resolve retail price.
    if ($garment === 'custom') {
        $retail = isset($args['retail']) ? floatval($args['retail']) : 0;
        if ($retail < 0) $retail = 0;
    } else {
        $retail = $T['GARMENTS'][$garment]; // 0.00 for supplied, fixed for standard
    }

    // ── Embroidery ──
    if ($method === 'embroidery') {
        $perShirt = btq_emb_per_shirt($qty, $retail, $embType);

        if ($perShirt === null) {
            return array(
                'quote'   => true,
                'method'  => 'embroidery',
                'embType' => $embType,
                'message' => 'Contact us for a quote on orders of ' . $T['EMB_QUOTE_MIN'] . '+',
            );
        }

        $total = $perShirt * $qty;

        $singlePerShirt = btq_emb_per_shirt(1, $retail, $embType);
        $discPct = 0;
        if ($qty > 1 && $singlePerShirt) {
            $discPct = max(0, round((1 - $perShirt / $singlePerShirt) * 100));
        }

        $breaks = array();
        foreach ($T['BREAKS'] as $bq) {
            if ($bq >= $T['EMB_QUOTE_MIN']) continue;
            $bp = btq_emb_per_shirt($bq, $retail, $embType);
            if ($bp === null) continue;
            $breaks[] = array(
                'qty'   => $bq,
                'price' => round($bp, 2),
                'total' => round($bp * $bq, 2),
            );
        }
        // single trailing "by quote" row for the high end
        $breaks[] = array('qty' => $T['EMB_QUOTE_MIN'], 'price' => null, 'total' => null, 'quote' => true);

        return array(
            'perShirt' => round($perShirt, 2),
            'total'    => round($total, 2),
            'discPct'  => $discPct,
            'breaks'   => $breaks,
            'method'   => 'embroidery',
            'embType'  => $embType,
        );
    }

    // ── Print (default) ──
    $perShirt = btq_print_calc($qty, $retail, $locations);
    $total    = $perShirt * $qty;

    $singlePerShirt = btq_print_calc(1, $retail, $locations);
    $discPct = $qty > 1 ? max(0, round((1 - $perShirt / $singlePerShirt) * 100)) : 0;

    $breaks = array();
    foreach ($T['BREAKS'] as $bq) {
        $bp = btq_print_calc($bq, $retail, $locations);
        $breaks[] = array(
            'qty'   => $bq,
            'price' => round($bp, 2),
            'total' => round($bp * $bq, 2),
        );
    }

    return array(
        'perShirt' => round($perShirt, 2),
        'total'    => round($total, 2),
        'discPct'  => $discPct,
        'breaks'   => $breaks,
        'method'   => 'print',
    );
}

/* ── REST: POST /wp-json/boomerts/v1/price (drop-in for the old snippet) ── */
add_action('rest_api_init', function () {
    register_rest_route('boomerts/v1', '/price', array(
        'methods'             => 'POST',
        'callback'            => 'btq_rest_price',
        'permission_callback' => '__return_true',
    ), true); // override: plugin wins if the old snippet is still active
});

function btq_rest_price(WP_REST_Request $request) {
    $res = btq_price(array(
        'qty'       => $request->get_param('qty'),
        'garment'   => sanitize_text_field((string) $request->get_param('garment')),
        'locations' => $request->get_param('locations'),
        'method'    => sanitize_text_field((string) $request->get_param('method')),
        'embType'   => sanitize_text_field((string) $request->get_param('embType')),
        'retail'    => $request->get_param('retail'),
    ));
    if (is_wp_error($res)) return $res;
    return rest_ensure_response($res);
}
