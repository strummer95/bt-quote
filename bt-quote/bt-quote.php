<?php
/*
Plugin Name: BT Quote
Plugin URI: https://boomerts.com
Description: Boomer T's shared pricing + quote engine. One pricing brain for the Quick Quote page, employee portal, and BT Catalog quote drawer. Registers POST /wp-json/boomerts/v1/price and /quote, and the [bt_quick_quote] Quick Quote tool.
Version: 0.4.0
Author: Duck and Rabbit Co.
*/

if (!defined('ABSPATH')) exit;

define('BTQ_VERSION', '0.4.0');
define('BTQ_DIR', plugin_dir_path(__FILE__));
define('BTQ_URL', plugin_dir_url(__FILE__));
define('BTQ_FILE', __FILE__);

/** Where quote submissions are emailed. Filterable for future admin setting. */
function btq_quote_email() {
    return apply_filters('btq_quote_email', 'orders@boomerts.com');
}

require_once BTQ_DIR . 'includes/pricing.php';
require_once BTQ_DIR . 'includes/submit.php';
require_once BTQ_DIR . 'includes/shortcode.php';
require_once BTQ_DIR . 'includes/bt-admin.php';
require_once BTQ_DIR . 'includes/admin.php';
require_once BTQ_DIR . 'includes/pricing-admin.php';
require_once BTQ_DIR . 'includes/updater.php';
