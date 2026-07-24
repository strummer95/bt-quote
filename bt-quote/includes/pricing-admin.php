<?php
/**
 * BT Quote — Pricing editor (BT Quote → Pricing).
 *
 * Factory defaults live in code (btq_pricing_defaults) and are never touched.
 * Edits save as overrides in option btq_pricing_overrides; live pricing =
 * defaults + overrides. Reset (per section or everything) deletes overrides.
 * Percent tools adjust the on-screen fields; nothing changes until Save.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page('bt-quote', 'Pricing', 'Pricing', 'manage_options', 'bt-quote-pricing', 'btq_pricing_page');
});

/** Sections eligible for reset, mapped to the override keys they clear. */
function btq_pricing_sections() {
    return array(
        'print'    => array('PRINT_TIERS'),
        'garments' => array('GARMENTS'),
        'gmtdisc'  => array('GMT_DISC'),
        'locs'     => array('LOC2_TIERS', 'LOC3_TIERS'),
        'handling' => array('HANDLING_UNDER', 'HANDLING_OVER'),
        'emb'      => array('EMB_TEXT_TIERS', 'EMB_LOGO_MULT', 'EMB_HARD_ADDER', 'EMB_QUOTE_MIN'),
    );
}

function btq_pricing_page() {
    if (!current_user_can('manage_options')) return;

    $D    = btq_pricing_defaults();
    $secs = btq_pricing_sections();
    $msg  = '';

    // ── Reset actions ──
    if (isset($_POST['btq_reset_all'])) {
        check_admin_referer('btq_pricing');
        delete_option('btq_pricing_overrides');
        $msg = 'All pricing reset to factory defaults.';
    } elseif (isset($_POST['btq_reset_section'])) {
        check_admin_referer('btq_pricing');
        $sec = sanitize_key($_POST['btq_reset_section']);
        if (isset($secs[$sec])) {
            $ov = get_option('btq_pricing_overrides', array());
            foreach ($secs[$sec] as $k) unset($ov[$k]);
            if ($ov) update_option('btq_pricing_overrides', $ov); else delete_option('btq_pricing_overrides');
            $msg = 'Section reset to factory defaults.';
        }
    }

    // ── Save ──
    if (isset($_POST['btq_save_pricing'])) {
        check_admin_referer('btq_pricing');
        $ov = array();

        $num = function ($v, $dec = 2) {
            $v = trim((string) wp_unslash($v));
            if ($v === '' || !is_numeric($v)) return null;
            return round((float) $v, $dec);
        };

        // Tier tables (prices only; qty anchors fixed). Store only diffs vs default.
        foreach (array('PRINT_TIERS', 'LOC2_TIERS', 'LOC3_TIERS', 'EMB_TEXT_TIERS') as $sec) {
            if (empty($_POST[$sec]) || !is_array($_POST[$sec])) continue;
            foreach ($_POST[$sec] as $i => $val) {
                $i = (int) $i;
                if (!isset($D[$sec][$i])) continue;
                $v = $num($val);
                if ($v === null || $v < 0) continue;
                if (abs($v - $D[$sec][$i][1]) > 0.0001) $ov[$sec][$i] = $v;
            }
        }
        // GMT discount multipliers (4 decimals so e.g. 0.665 survives).
        if (!empty($_POST['GMT_DISC']) && is_array($_POST['GMT_DISC'])) {
            foreach ($_POST['GMT_DISC'] as $i => $val) {
                $i = (int) $i;
                if (!isset($D['GMT_DISC'][$i])) continue;
                $v = $num($val, 4);
                if ($v === null || $v <= 0 || $v > 2) continue;
                if (abs($v - $D['GMT_DISC'][$i]['mult']) > 0.00001) $ov['GMT_DISC'][$i] = $v;
            }
        }
        // Garment prices.
        if (!empty($_POST['GARMENTS']) && is_array($_POST['GARMENTS'])) {
            foreach ($_POST['GARMENTS'] as $k => $val) {
                $k = sanitize_key($k);
                if (!isset($D['GARMENTS'][$k]) || $k === 'supplied' || $k === 'custom') continue;
                $v = $num($val);
                if ($v === null || $v < 0) continue;
                if (abs($v - $D['GARMENTS'][$k]) > 0.0001) $ov['GARMENTS'][$k] = $v;
            }
        }
        // Scalars.
        foreach (array('EMB_LOGO_MULT' => 4, 'EMB_HARD_ADDER' => 2, 'HANDLING_UNDER' => 2, 'HANDLING_OVER' => 2) as $k => $dec) {
            if (!isset($_POST[$k])) continue;
            $v = $num($_POST[$k], $dec);
            if ($v === null || $v < 0) continue;
            if (abs($v - $D[$k]) > 0.00001) $ov[$k] = $v;
        }
        if (isset($_POST['EMB_QUOTE_MIN'])) {
            $v = (int) $_POST['EMB_QUOTE_MIN'];
            if ($v >= 1 && $v !== (int) $D['EMB_QUOTE_MIN']) $ov['EMB_QUOTE_MIN'] = $v;
        }

        if ($ov) update_option('btq_pricing_overrides', $ov); else delete_option('btq_pricing_overrides');
        $msg = $ov ? 'Pricing saved. Values that differ from factory default are highlighted.' : 'Pricing saved — everything matches factory defaults.';
    }

    $T    = btq_pricing_tables();               // live (post-save) values
    $ovnw = get_option('btq_pricing_overrides', array());

    // Live samples so a bad edit is obvious immediately.
    $s1 = btq_price(array('qty' => 24,  'garment' => 'g5000', 'locations' => 1));
    $s2 = btq_price(array('qty' => 48,  'garment' => 'g5000', 'locations' => 2));
    $s3 = btq_price(array('qty' => 12,  'garment' => 'supplied', 'method' => 'embroidery', 'embType' => 'text'));
    $s4 = btq_price(array('qty' => 144, 'garment' => 'g18000', 'locations' => 1));

    $mod = function ($sec) use ($ovnw, $secs) {
        foreach ($secs[$sec] as $k) if (isset($ovnw[$k])) return true;
        return false;
    };
    $badge = function ($sec) use ($mod) {
        return $mod($sec) ? ' <span class="btq-mod">MODIFIED</span>' : '';
    };
    $resetBtn = function ($sec) use ($mod) {
        if (!$mod($sec)) return '';
        return '<button class="button btq-resetsec" name="btq_reset_section" value="' . esc_attr($sec) . '" onclick="return confirm(\'Reset this section to factory defaults?\')">Reset section to default</button>';
    };
    // Field with default-diff highlight + title showing the factory number.
    $fld = function ($name, $val, $def, $step = '0.01') {
        $diff = abs((float) $val - (float) $def) > 0.00001;
        return '<input type="number" step="' . esc_attr($step) . '" min="0" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '"'
             . ' data-def="' . esc_attr($def) . '" title="Factory default: ' . esc_attr($def) . '"'
             . ' class="btq-num' . ($diff ? ' btq-diff' : '') . '">';
    };

    echo '<div class="wrap"><h1>BT Quote — Pricing</h1>';
    if ($msg) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';

    echo '<style>
      .btq-num{width:92px;font-size:14px}
      .btq-diff{border-color:#e535ab;background:#fdf0f8;font-weight:600}
      .btq-mod{background:#e535ab;color:#fff;font-size:11px;font-weight:700;letter-spacing:.06em;border-radius:999px;padding:2px 9px;vertical-align:middle}
      .btq-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:0 0 18px;max-width:1080px}
      .btq-card h2{margin:0 0 4px;font-size:16px}
      .btq-card .desc{color:#646970;margin:0 0 12px;font-size:13px}
      .btq-grid{display:flex;flex-wrap:wrap;gap:8px 14px}
      .btq-cell{display:flex;flex-direction:column;gap:2px}
      .btq-cell label{font-size:12px;color:#646970;font-weight:600}
      .btq-tools{display:flex;align-items:center;gap:8px;margin-top:12px;flex-wrap:wrap}
      .btq-tools input[type=number]{width:70px}
      .btq-samples td{font-size:14px;padding:6px 12px}
      .btq-sticky{position:sticky;bottom:0;background:#f0f0f1;padding:12px 0;margin-top:8px;border-top:1px solid #dcdcde;max-width:1080px}
    </style>';

    echo '<p style="max-width:900px">Factory defaults are locked in the plugin — anything here is an override on top of them, and <b>Reset</b> always returns to today\'s pricing exactly. Pink fields differ from default (hover any field to see its factory number). Percent tools change the fields on screen; nothing is live until you hit <b>Save Pricing</b>.</p>';

    // Live samples.
    echo '<div class="btq-card"><h2>Live samples (current saved pricing)</h2><table class="btq-samples"><tbody>';
    echo '<tr><td>24 × Gildan 5000, 1 location</td><td><b>$' . esc_html(number_format($s1['perShirt'], 2)) . '/ea</b> · $' . esc_html(number_format($s1['total'], 2)) . '</td></tr>';
    echo '<tr><td>48 × Gildan 5000, 2 locations</td><td><b>$' . esc_html(number_format($s2['perShirt'], 2)) . '/ea</b> · $' . esc_html(number_format($s2['total'], 2)) . '</td></tr>';
    echo '<tr><td>12 × customer-supplied, embroidery text</td><td><b>$' . esc_html(number_format($s3['perShirt'], 2)) . '/ea</b> · $' . esc_html(number_format($s3['total'], 2)) . '</td></tr>';
    echo '<tr><td>144 × Gildan 18000, 1 location</td><td><b>$' . esc_html(number_format($s4['perShirt'], 2)) . '/ea</b> · $' . esc_html(number_format($s4['total'], 2)) . '</td></tr>';
    echo '</tbody></table></div>';

    echo '<form method="post" id="btqPricingForm">';
    wp_nonce_field('btq_pricing');

    // Global percent tool.
    echo '<div class="btq-card"><h2>Adjust everything</h2><p class="desc">Applies to every dollar field on this page (print tiers, garments, location adders, handling, embroidery tiers + hard-to-handle adder). Multipliers and the by-quote minimum are left alone.</p>';
    echo '<div class="btq-tools"><input type="number" step="0.1" id="btqGlobalPct" value="5"> % &nbsp;<button type="button" class="button" data-pctsec="__all" data-dir="1">Increase all</button> <button type="button" class="button" data-pctsec="__all" data-dir="-1">Decrease all</button></div></div>';

    // ── Screen print tiers ──
    echo '<div class="btq-card"><h2>Screen Print — lot cost by quantity' . $badge('print') . '</h2><p class="desc">Total lot cost at each quantity anchor; per-shirt print cost is interpolated between anchors and divided by qty.</p><div class="btq-grid">';
    foreach ($T['PRINT_TIERS'] as $i => $row) {
        echo '<div class="btq-cell"><label>' . (int) $row[0] . ' pcs</label>' . $fld("PRINT_TIERS[$i]", $row[1], $D['PRINT_TIERS'][$i][1]) . '</div>';
    }
    echo '</div><div class="btq-tools"><input type="number" step="0.1" class="btq-pct" data-for="print" value="5"> % <button type="button" class="button" data-pctsec="print" data-dir="1">Increase section</button> <button type="button" class="button" data-pctsec="print" data-dir="-1">Decrease section</button> ' . $resetBtn('print') . '</div></div>';

    // ── Garments ──
    echo '<div class="btq-card"><h2>Garment prices' . $badge('garments') . '</h2><p class="desc">Base garment retail before the quantity discount curve. (Customer-supplied is always $0; catalog items pass their own price.)</p><div class="btq-grid">';
    $glabels = array('g5000'=>'Gildan 5000','g8000'=>'Gildan 8000','g64000'=>'Gildan 64000','g18000'=>'Gildan 18000','g18500'=>'Gildan 18500','bc3001'=>'Bella+Canvas 3001','nl3600'=>'Next Level 3600','c25100'=>'C2 Sport 5100');
    foreach ($T['GARMENTS'] as $k => $price) {
        if ($k === 'supplied' || $k === 'custom') continue;
        $lab = isset($glabels[$k]) ? $glabels[$k] : strtoupper($k);
        echo '<div class="btq-cell"><label>' . esc_html($lab) . '</label>' . $fld("GARMENTS[$k]", $price, $D['GARMENTS'][$k]) . '</div>';
    }
    echo '</div><div class="btq-tools"><input type="number" step="0.1" class="btq-pct" data-for="garments" value="5"> % <button type="button" class="button" data-pctsec="garments" data-dir="1">Increase section</button> <button type="button" class="button" data-pctsec="garments" data-dir="-1">Decrease section</button> ' . $resetBtn('garments') . '</div></div>';

    // ── GMT discount curve ──
    echo '<div class="btq-card"><h2>Garment quantity-discount multipliers' . $badge('gmtdisc') . '</h2><p class="desc">Garment price is multiplied by these at each quantity range (1.00 = full price, 0.90 = 10% off). Percent tools skip these on purpose — edit individually.</p><div class="btq-grid">';
    $prev = 1;
    foreach ($T['GMT_DISC'] as $i => $row) {
        $lab = $prev . '–' . ($row['mx'] >= 9999 ? '∞' : $row['mx']);
        $prev = $row['mx'] + 1;
        echo '<div class="btq-cell"><label>' . esc_html($lab) . ' pcs</label>' . $fld("GMT_DISC[$i]", $row['mult'], $D['GMT_DISC'][$i]['mult'], '0.0001') . '</div>';
    }
    echo '</div><div class="btq-tools">' . $resetBtn('gmtdisc') . '</div></div>';

    // ── Location adders ──
    echo '<div class="btq-card"><h2>Extra print locations — per-shirt adders' . $badge('locs') . '</h2><p class="desc">Added per shirt when a 2nd / 3rd print location is selected, stepped by quantity.</p>';
    echo '<p style="margin:6px 0 4px;font-weight:600;font-size:13px">2nd location</p><div class="btq-grid">';
    foreach ($T['LOC2_TIERS'] as $i => $row) {
        echo '<div class="btq-cell"><label>' . (int) $row[0] . '+</label>' . $fld("LOC2_TIERS[$i]", $row[1], $D['LOC2_TIERS'][$i][1]) . '</div>';
    }
    echo '</div><p style="margin:12px 0 4px;font-weight:600;font-size:13px">3rd location</p><div class="btq-grid">';
    foreach ($T['LOC3_TIERS'] as $i => $row) {
        echo '<div class="btq-cell"><label>' . (int) $row[0] . '+</label>' . $fld("LOC3_TIERS[$i]", $row[1], $D['LOC3_TIERS'][$i][1]) . '</div>';
    }
    echo '</div><div class="btq-tools"><input type="number" step="0.1" class="btq-pct" data-for="locs" value="5"> % <button type="button" class="button" data-pctsec="locs" data-dir="1">Increase section</button> <button type="button" class="button" data-pctsec="locs" data-dir="-1">Decrease section</button> ' . $resetBtn('locs') . '</div></div>';

    // ── Handling ──
    echo '<div class="btq-card"><h2>Handling per piece' . $badge('handling') . '</h2><div class="btq-grid">';
    echo '<div class="btq-cell"><label>Under 100 pcs</label>' . $fld('HANDLING_UNDER', $T['HANDLING_UNDER'], $D['HANDLING_UNDER']) . '</div>';
    echo '<div class="btq-cell"><label>100+ pcs</label>' . $fld('HANDLING_OVER', $T['HANDLING_OVER'], $D['HANDLING_OVER']) . '</div>';
    echo '</div><div class="btq-tools">' . $resetBtn('handling') . '</div></div>';

    // ── Embroidery ──
    echo '<div class="btq-card"><h2>Embroidery' . $badge('emb') . '</h2><p class="desc">Text/names band is the source of truth. Logo = text × multiplier. Hard-to-handle = logo + adder. Orders at/above the by-quote minimum return "by quote."</p>';
    echo '<p style="margin:6px 0 4px;font-weight:600;font-size:13px">Text / names per piece</p><div class="btq-grid">';
    foreach ($T['EMB_TEXT_TIERS'] as $i => $row) {
        echo '<div class="btq-cell"><label>' . (int) $row[0] . '+</label>' . $fld("EMB_TEXT_TIERS[$i]", $row[1], $D['EMB_TEXT_TIERS'][$i][1]) . '</div>';
    }
    echo '</div><div class="btq-grid" style="margin-top:12px">';
    echo '<div class="btq-cell"><label>Logo multiplier</label>' . $fld('EMB_LOGO_MULT', $T['EMB_LOGO_MULT'], $D['EMB_LOGO_MULT'], '0.01') . '</div>';
    echo '<div class="btq-cell"><label>Hard-to-handle adder ($)</label>' . $fld('EMB_HARD_ADDER', $T['EMB_HARD_ADDER'], $D['EMB_HARD_ADDER']) . '</div>';
    echo '<div class="btq-cell"><label>By-quote minimum (pcs)</label><input type="number" step="1" min="1" name="EMB_QUOTE_MIN" value="' . esc_attr($T['EMB_QUOTE_MIN']) . '" data-def="' . esc_attr($D['EMB_QUOTE_MIN']) . '" title="Factory default: ' . esc_attr($D['EMB_QUOTE_MIN']) . '" class="btq-num' . ((int) $T['EMB_QUOTE_MIN'] !== (int) $D['EMB_QUOTE_MIN'] ? ' btq-diff' : '') . '"></div>';
    echo '</div><div class="btq-tools"><input type="number" step="0.1" class="btq-pct" data-for="emb" value="5"> % <button type="button" class="button" data-pctsec="emb" data-dir="1">Increase section</button> <button type="button" class="button" data-pctsec="emb" data-dir="-1">Decrease section</button> ' . $resetBtn('emb') . '</div></div>';

    // Save / reset all.
    echo '<div class="btq-sticky"><button class="button button-primary button-hero" name="btq_save_pricing" value="1">Save Pricing</button> &nbsp; ';
    echo '<button class="button" name="btq_reset_all" value="1" onclick="return confirm(\'Reset ALL pricing to factory defaults? Every override will be removed.\')">Reset everything to default</button></div>';

    echo '</form>';

    // Percent tools: adjust on-screen values only (Save makes them live).
    // Section -> which input name prefixes it touches. Dollar fields only.
    echo '<script>
    (function(){
      var map = {
        print:    ["PRINT_TIERS["],
        garments: ["GARMENTS["],
        locs:     ["LOC2_TIERS[", "LOC3_TIERS["],
        emb:      ["EMB_TEXT_TIERS[", "EMB_HARD_ADDER"],
        __all:    ["PRINT_TIERS[", "GARMENTS[", "LOC2_TIERS[", "LOC3_TIERS[", "EMB_TEXT_TIERS[", "EMB_HARD_ADDER", "HANDLING_UNDER", "HANDLING_OVER"]
      };
      function pctFor(sec){
        if (sec === "__all") return parseFloat(document.getElementById("btqGlobalPct").value) || 0;
        var el = document.querySelector(".btq-pct[data-for=\'" + sec + "\']");
        return el ? (parseFloat(el.value) || 0) : 0;
      }
      document.querySelectorAll("[data-pctsec]").forEach(function(btn){
        btn.addEventListener("click", function(){
          var sec = btn.getAttribute("data-pctsec"), dir = parseInt(btn.getAttribute("data-dir"), 10);
          var pct = pctFor(sec); if (!pct) return;
          var mult = 1 + dir * pct / 100; if (mult <= 0) return;
          var prefixes = map[sec] || [];
          document.querySelectorAll("#btqPricingForm input.btq-num").forEach(function(inp){
            var n = inp.getAttribute("name") || "";
            var hit = prefixes.some(function(p){ return n.indexOf(p) === 0; });
            if (!hit) return;
            var v = parseFloat(inp.value); if (isNaN(v)) return;
            inp.value = (v * mult).toFixed(2);
            var def = parseFloat(inp.getAttribute("data-def"));
            inp.classList.toggle("btq-diff", Math.abs(parseFloat(inp.value) - def) > 0.00001);
          });
        });
      });
      // Keep the pink diff highlight live as fields are typed in.
      document.querySelectorAll("#btqPricingForm input.btq-num").forEach(function(inp){
        inp.addEventListener("input", function(){
          var def = parseFloat(inp.getAttribute("data-def"));
          var v = parseFloat(inp.value);
          inp.classList.toggle("btq-diff", !isNaN(v) && Math.abs(v - def) > 0.00001);
        });
      });
    })();
    </script>';

    echo '</div>';
}
