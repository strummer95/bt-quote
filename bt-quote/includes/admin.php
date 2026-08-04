<?php
/**
 * BT Quote — admin page (status + updates).
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('BT Quote', 'BT Quote', 'manage_options', 'bt-quote', 'btq_admin_page', 'dashicons-calculator', 58);
});

/**
 * Group the BT tools together in the admin sidebar, seated where the highest of
 * them currently sits. Runs late so it wins regardless of what positions the
 * individual plugins/snippets registered with.
 *
 * The order is filterable so a new BT plugin can slot itself in without needing
 * a BT Quote release:
 *
 *   add_filter('bt_menu_group_order', function ($w) { $w[] = 'bt whatever'; return $w; });
 *
 * Entries are lowercase menu-label prefixes, matched from the start of the label.
 */
add_action('admin_menu', 'btq_group_bt_menus', 9999);
function btq_group_bt_menus() {
    global $menu;
    if (!is_array($menu)) return;

    $want = apply_filters('bt_menu_group_order', array(
        'bt accounts', 'bt catalog', 'bt portal', 'bt quote', 'bt transfers', 'dtf studio',
    ));
    $want  = array_values(array_unique(array_filter(array_map('strval', (array) $want))));
    $found = array();                                       // name => [key, item]

    foreach ($menu as $key => $item) {
        if (!isset($item[0])) continue;
        $label = strtolower(trim(wp_strip_all_tags($item[0])));
        foreach ($want as $w) {
            if (strpos($label, $w) === 0) { $found[$w] = array($key, $item); break; }
        }
    }
    if (count($found) < 2) return; // nothing to group

    // Anchor = the top-most current position among the found items.
    $anchor = null;
    foreach ($found as $f) {
        $k = (float) $f[0];
        if ($anchor === null || $k < $anchor) $anchor = $k;
    }

    foreach ($found as $f) unset($menu[$f[0]]);

    $n = 0;
    foreach ($want as $w) {
        if (!isset($found[$w])) continue;
        $k = $anchor + $n * 0.001;
        while (isset($menu["$k"]) || isset($menu[(string) $k])) $k += 0.0001;
        $menu["$k"] = $found[$w][1];
        $n++;
    }
    ksort($menu, SORT_NUMERIC);
}

function btq_admin_page() {
    if (!current_user_can('manage_options')) return;

    $info   = btq_update_manifest();
    $latest = isset($info['version']) ? $info['version'] : '?';

    // Quick engine self-test so a broken deploy is visible at a glance.
    $t1 = btq_price(array('qty'=>24, 'garment'=>'g5000', 'locations'=>1));
    $t2 = btq_price(array('qty'=>12, 'garment'=>'custom', 'retail'=>10, 'method'=>'embroidery', 'embType'=>'logo'));
    $ok = !is_wp_error($t1) && isset($t1['perShirt']) && !is_wp_error($t2) && isset($t2['perShirt']);

    echo '<div class="wrap"><h1>BT Quote</h1>';
    echo '<p>Shared pricing + quote engine. Serves <code>POST /wp-json/boomerts/v1/price</code> and <code>POST /wp-json/boomerts/v1/quote</code> for the Quick Quote page, employee portal, and BT Catalog quote drawer.</p>';

    echo '<h2>Status</h2><table class="widefat" style="max-width:640px"><tbody>';
    echo '<tr><td>Installed version</td><td><strong>' . esc_html(BTQ_VERSION) . '</strong></td></tr>';
    echo '<tr><td>Latest published</td><td>' . esc_html($latest) . '</td></tr>';
    echo '<tr><td>Pricing engine self-test</td><td>' . ($ok ? '<span style="color:#1a7f37;font-weight:700">PASS</span> &nbsp;(24× G5000 print = $' . esc_html(number_format($t1['perShirt'], 2)) . '/ea · 12× emb logo = $' . esc_html(number_format($t2['perShirt'], 2)) . '/ea)' : '<span style="color:#b91c1c;font-weight:700">FAIL</span>') . '</td></tr>';
    echo '<tr><td>Quote submissions email</td><td>' . esc_html(btq_quote_email()) . '</td></tr>';

    $owner = btq_shortcode_owner();
    if ($owner === 'plugin') {
        $sc = '<span style="color:#1a7f37;font-weight:700">Served by this plugin</span>';
    } elseif ($owner === 'other') {
        $sc = '<span style="color:#b91c1c;font-weight:700">Overridden</span> &mdash; another copy (most likely the old '
            . '<code>BT-Quick-Quote-Tool</code> snippet) is registering <code>[bt_quick_quote]</code> and winning. '
            . 'Deactivate it so the plugin serves the tool.';
    } else {
        $sc = '<span style="color:#b91c1c;font-weight:700">Not registered</span>';
    }
    echo '<tr><td><code>[bt_quick_quote]</code></td><td>' . $sc . '</td></tr>';
    echo '</tbody></table>';

    echo '<h2>Prefilled quote links</h2>';
    echo '<p>Add query params to the Quick Quote page URL to open the tool with the boxes already filled in. '
       . 'The address bar also tracks the tool live, so the URL is always an accurate quote &mdash; copy it straight from '
       . 'the bar, or use the <strong>Copy Quote Link</strong> button under the results.</p>';
    echo '<table class="widefat" style="max-width:640px"><tbody>';
    echo '<tr><td><code>qty</code></td><td>1&ndash;1000</td></tr>';
    echo '<tr><td><code>g</code></td><td>garment id (<code>g5000</code>, <code>g18500</code>, &hellip;), or <code>supplied</code> / <code>custom</code></td></tr>';
    echo '<tr><td><code>loc</code></td><td>1&ndash;3 print locations</td></tr>';
    echo '<tr><td><code>m</code></td><td><code>print</code> (default) or <code>emb</code></td></tr>';
    echo '<tr><td><code>et</code></td><td><code>text</code> / <code>logo</code> / <code>hard</code> &mdash; embroidery only</td></tr>';
    echo '<tr><td><code>r</code></td><td>retail dollars, with <code>g=custom</code></td></tr>';
    echo '</tbody></table>';
    echo '<p><code>' . esc_html(home_url('/quote/?qty=48&g=g5000&loc=2')) . '</code><br>';
    echo '<code>' . esc_html(home_url('/quote/?qty=36&g=supplied&m=emb&et=logo')) . '</code></p>';

    // Shared BT panel — same layout, wording and button on every BT plugin.
    bt_admin_updates_panel(array(
        'slug'     => 'bt-quote',
        'version'  => BTQ_VERSION,
        'manifest' => 'btq_update_manifest',
        'flush'    => 'btq_force_update_check',
    ));

    echo '</div>';
}
