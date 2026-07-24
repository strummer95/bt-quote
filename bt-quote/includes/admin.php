<?php
/**
 * BT Quote — admin page (status + updates).
 */
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('BT Quote', 'BT Quote', 'manage_options', 'bt-quote', 'btq_admin_page', 'dashicons-calculator', 58);
});

function btq_admin_page() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['btq_check_updates'])) {
        check_admin_referer('btq_admin');
        btq_force_update_check();
        echo '<div class="notice notice-success is-dismissible"><p>Update check refreshed — see <a href="' . esc_url(admin_url('plugins.php')) . '">Plugins</a> for any available update.</p></div>';
    }

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
    echo '</tbody></table>';

    echo '<h2>Updates</h2>';
    echo '<form method="post">';
    wp_nonce_field('btq_admin');
    echo '<p><button class="button button-primary" name="btq_check_updates" value="1">Check now</button> &nbsp;Then update from the Plugins screen as usual.</p>';
    echo '</form>';

    echo '</div>';
}
