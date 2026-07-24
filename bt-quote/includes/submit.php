<?php
/**
 * BT Quote — quote submission.
 *
 * Ported 1:1 from the WPCode /quote snippet: same param names (your-name,
 * your-email, your-organization, your-phone, your-message), same HTML email
 * to orders@, same response shape. Drop-in replacement, registered with
 * override=true so the plugin wins if the old snippet is still active.
 */
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('boomerts/v1', '/quote', array(
        'methods'             => 'POST',
        'callback'            => 'btq_rest_quote',
        'permission_callback' => '__return_true',
    ), true); // override
});

function btq_rest_quote(WP_REST_Request $request) {
    $params = $request->get_params();

    $name  = sanitize_text_field(isset($params['your-name']) ? $params['your-name'] : '');
    $email = sanitize_email(isset($params['your-email']) ? $params['your-email'] : '');
    $org   = sanitize_text_field(isset($params['your-organization']) ? $params['your-organization'] : '');
    $phone = sanitize_text_field(isset($params['your-phone']) ? $params['your-phone'] : '');

    $to      = btq_quote_email();
    $subject = 'New Quote Request from Website Pricing Tool:';

    $body  = '<div style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #000;">';
    $body .= '<p style="font-size: 18px; margin-bottom: 20px;">New Quote Request from Website Pricing Tool:</p>';

    $body .= '<p><strong>Name:</strong> ' . $name . '<br>';
    $body .= '<strong>Email:</strong> ' . $email . '<br>';
    $body .= '<strong>Organization:</strong> ' . $org . '<br>';
    $body .= '<strong>Phone:</strong> ' . $phone . '</p>';

    $body .= '<p style="margin-top: 25px;"><strong><em>--- ESTIMATE DETAILS ---</em></strong></p>';

    // Bold the labels inside the automated summary block.
    $details = nl2br(sanitize_textarea_field(isset($params['your-message']) ? $params['your-message'] : ''));
    $details = str_replace(
        array('Quantity:', 'Garment:', 'Locations:', 'Price Per Shirt:', 'Est. Total:', 'Message:'),
        array('<strong>Quantity:</strong>', '<strong>Garment:</strong>', '<strong>Locations:</strong>', '<strong>Price Per Shirt:</strong>', '<strong>Est. Total:</strong>', '<strong>Notes:</strong>'),
        $details
    );

    $body .= '<p>' . $details . '</p>';
    $body .= '</div>';

    $headers = array('Content-Type: text/html; charset=UTF-8');
    if ($email !== '') $headers[] = 'Reply-To: ' . $email;

    $sent = wp_mail($to, $subject, $body, $headers);

    if ($sent) {
        return new WP_REST_Response(array('status' => 'success'), 200);
    }
    return new WP_Error('send_failed', 'Email failed to send', array('status' => 500));
}
