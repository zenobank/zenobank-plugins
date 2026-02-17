<?php
defined('ABSPATH') || exit;

/**
 * Register gateway with EDD.
 */
add_filter(
	'edd_payment_gateways',
	function ($gateways) {

		$title = edd_get_option('znccedd_title', __('USDT, USDC', 'zeno-crypto-checkout-for-easy-digital-downloads'));
		$title = is_string($title) ? trim($title) : __('USDT, USDC', 'zeno-crypto-checkout-for-easy-digital-downloads');

		$gateways[ZNCCEDD_GATEWAY_ID] = array(
			'admin_label'    => __('Zeno Crypto Checkout', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			'checkout_label' => $title,
		);

		return $gateways;
	}
);

add_filter(
	'edd_accepted_payment_icons',
	function ($icons) {
		$icons[ZNCCEDD_PLUGIN_URL . 'images/checkout-logo.png'] = __('Zeno', 'zeno-crypto-checkout-for-easy-digital-downloads');
		return $icons;
	}
);

add_filter('edd_gateway_settings_url_znccedd', function () {
	return admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=znccedd');
});

/**
 * Remove CC form for this gateway (EDD standard pattern).
 */
add_action('edd_' . ZNCCEDD_GATEWAY_ID . '_cc_form', '__return_false');

/**
 * Hide global CC fields when Zeno is chosen (prevents card fields UI).
 */
add_filter(
	'edd_show_cc_fields',
	function ($show) {
		$chosen = function_exists('edd_get_chosen_gateway') ? edd_get_chosen_gateway() : '';
		if (ZNCCEDD_GATEWAY_ID === $chosen) {
			return false;
		}
		return $show;
	},
	10
);

/**
 * Register the Zeno gateway subsection.
 *
 * @param array $gateway_sections Current gateway tab subsections.
 * @return array
 */
function znccedd_register_zeno_gateway_section($gateway_sections)
{
	$gateway_sections['znccedd'] = __('Zeno', 'zeno-crypto-checkout-for-easy-digital-downloads');
	return $gateway_sections;
}
add_filter('edd_settings_sections_gateways', 'znccedd_register_zeno_gateway_section', 1, 1);


/**
 * Register Zeno settings under the "Zeno" subsection.
 *
 * @param array $gateway_settings Gateway tab settings.
 * @return array
 */
function znccedd_register_zeno_gateway_settings($gateway_settings)
{

	$secret = edd_get_option('znccedd_secret_live', '');

	$zeno_settings = array(
		'znccedd_settings_header' => array(
			'id'   => 'znccedd_settings_header',
			'name' => '<h3>' . esc_html__('Zeno Crypto Gateway', 'zeno-crypto-checkout-for-easy-digital-downloads') . '</h3>',
			'type' => 'header',
		),

		'znccedd_api_key_live'    => array(
			'id'   => 'znccedd_api_key_live',
			'name' => esc_html__('API Key', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			'desc' => wp_kses_post(
				sprintf(
					/* translators: %s: dashboard URL */
					__('Get your API key here: %s', 'zeno-crypto-checkout-for-easy-digital-downloads'),
					'<a href="' . esc_url('https://dashboard.zenobank.io/') . '" target="_blank" rel="noopener noreferrer">dashboard.zenobank.io</a>'
				)
			),
			'type' => 'password',
			'size' => 'regular',
		),

		'znccedd_title'           => array(
			'id'   => 'znccedd_title',
			'name' => esc_html__('Title', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			'desc' => esc_html__('Text shown to customers at checkout.', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			'type' => 'text',
			'std'  => esc_html__('USDT, USDC', 'zeno-crypto-checkout-for-easy-digital-downloads'),
		),
	);

	/**
	 * Allow devs to filter settings.
	 */
	$zeno_settings = apply_filters('znccedd_settings', $zeno_settings);

	// Put all Zeno settings under the "znccedd" subsection.
	$gateway_settings['znccedd'] = $zeno_settings;

	return $gateway_settings;
}
add_filter('edd_settings_gateways', 'znccedd_register_zeno_gateway_settings', 1, 1);

add_filter('edd_is_gateway_setup_znccedd', function ($is_setup) {
	$api_key = znccedd_api_key();
	return ('' !== $api_key);
});

/**
 * API key validation for EDD settings.
 *
 * Rules:
 * 1) If Zeno gateway is enabled OR being enabled, API key cannot be empty.
 * 2) If Zeno gateway is disabled, allow empty API key (no error).
 * 3) If Zeno gateway is enabled and user tries to clear API key, block it (keep previous key + error).
 *
 * @param array $input Settings being saved.
 * @return array
 */
function znccedd_validate_api_key_on_save($input)
{

	$current_key = edd_get_option('znccedd_api_key_live', '');
	$current_key = is_string($current_key) ? trim($current_key) : '';

	$incoming_key = null; // null = not submitted.
	if (array_key_exists('znccedd_api_key_live', $input)) {
		$incoming_key = trim((string) $input['znccedd_api_key_live']);
	}

	$enabled_gateways = array();
	if (isset($input['gateways']) && is_array($input['gateways'])) {
		$enabled_gateways = $input['gateways'];
	}

	$zeno_was_enabled   = edd_is_gateway_active(ZNCCEDD_GATEWAY_ID);
	$zeno_will_be_enabled = in_array(ZNCCEDD_GATEWAY_ID, $enabled_gateways, true);

	// If API key field not submitted, do nothing.
	if (null === $incoming_key) {
		return $input;
	}

	/**
	 * If gateway is DISABLED (both currently and after save), allow empty API key.
	 * No error. Save as-is (empty allowed).
	 */
	if (! $zeno_was_enabled && ! $zeno_will_be_enabled) {
		$input['znccedd_api_key_live'] = $incoming_key; // empty OK.
		return $input;
	}

	/**
	 * From here: gateway is enabled now OR being enabled.
	 * Empty key is NOT allowed.
	 */
	if ('' === $incoming_key) {

		// Keep previous key so it is not overwritten with empty.
		$input['znccedd_api_key_live'] = $current_key;

		// If they tried to enable it without a key, prevent enabling.
		if ($zeno_will_be_enabled && '' === $current_key) {
			$input['gateways'] = array_values(
				array_diff($enabled_gateways, array(ZNCCEDD_GATEWAY_ID))
			);
		}

		return $input;
	}

	// Non-empty key: save it.
	$input['znccedd_api_key_live'] = $incoming_key;

	return $input;
}
add_filter('edd_settings_gateways_sanitize', 'znccedd_validate_api_key_on_save', 10, 1);


/**
 * Allow redirect to Zeno hosted checkout domain (CRITICAL).
 */
function znccedd_allow_redirect_to_zeno($hosts)
{
	$hosts[] = 'pay.zenobank.io';
	return $hosts;
}

/**
 * Helpers.
 */
function znccedd_api_key()
{
	$key = edd_get_option('znccedd_api_key_live', '');
	return is_string($key) ? trim($key) : '';
}

function znccedd_secret()
{
	$secret = edd_get_option('znccedd_secret_live', '');
	return is_string($secret) ? $secret : '';
}

function znccedd_verification_token($order_id)
{
	$secret = znccedd_secret();
	if (empty($secret)) {
		return '';
	}
	return hash_hmac('sha256', (string) $order_id,  $secret);
}

function znccedd_validate_gateway()
{
	$api_key = znccedd_api_key();
	if (ZNCCEDD_GATEWAY_ID == edd_get_chosen_gateway() && empty($api_key)) {
		edd_set_error('znccedd_missing_api_key', esc_html__('Payment gateway is not configured. Please contact site admin.', 'zeno-crypto-checkout-for-easy-digital-downloads'));
	}
}
add_action('edd_pre_process_purchase', 'znccedd_validate_gateway', 1);

/**
 * Process gateway purchase like EDD PayPal gateway.
 */
function znccedd_process_purchase($purchase_data)
{

	if (! wp_verify_nonce($purchase_data['gateway_nonce'], 'edd-gateway')) {
		wp_die(
			esc_html__('Nonce verification has failed', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			esc_html__('Error', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			array('response' => 403)
		);
	}

	$api_key = znccedd_api_key();

	if (empty($api_key)) {
		edd_record_gateway_error(
			__('Payment Error', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			__('Zeno gateway is enabled but API key is missing.', 'zeno-crypto-checkout-for-easy-digital-downloads')
		);

		edd_set_error('znccedd_missing_api_key', __('Payment gateway is not configured. Please contact site admin.', 'zeno-crypto-checkout-for-easy-digital-downloads'));
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		return;
	}

	$payment_data = array(
		'price'        => $purchase_data['price'],
		'date'         => $purchase_data['date'],
		'user_email'   => $purchase_data['user_email'],
		'purchase_key' => $purchase_data['purchase_key'],
		'currency'     => edd_get_currency(),
		'downloads'    => $purchase_data['downloads'],
		'user_info'    => $purchase_data['user_info'],
		'cart_details' => $purchase_data['cart_details'],
		'gateway'      => ZNCCEDD_GATEWAY_ID,
		'status'       => 'pending',
	);

	$payment_id = edd_insert_payment($payment_data);

	if (! $payment_id) {
		edd_record_gateway_error(
			__('Payment Error', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			/* translators: %s: payment data json */
			sprintf(__('Payment creation failed. Data: %s', 'zeno-crypto-checkout-for-easy-digital-downloads'), wp_json_encode($payment_data)),
			$payment_id
		);

		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		return;
	}

	// Save resume payment in session like PayPal does (optional but helpful).
	if (isset(EDD()->session)) {
		EDD()->session->set('edd_resume_payment', $payment_id);
	}

	$amount   = edd_get_payment_amount($payment_id);
	$currency = edd_get_payment_currency_code($payment_id);

	$verification_token = znccedd_verification_token($payment_id);

	$success_url = add_query_arg(
		array(
			'payment_key' => edd_get_payment_key($payment_id),
		),
		edd_get_success_page_uri()
	);

	$webhook_url = rest_url('znccedd/v1/webhook');

	$payload = array(
		'version'            => ZNCCEDD_VERSION,
		'platform'           => 'easy-digital-downloads',
		'priceAmount'        => $amount,
		'priceCurrency'      => $currency,
		'orderId'            => (string) $payment_id,
		'successRedirectUrl' => $success_url,
		'verificationToken'  => $verification_token,
		'webhookUrl'         => $webhook_url,
	);

	$response = wp_remote_post(
		trailingslashit(ZNCCEDD_API_ENDPOINT) . 'api/v1/checkouts',
		array(
			'headers' => array(
				'x-api-key'    => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',

				// Zeno client identification headers
				'X-Client-Type'              => 'plugin',
				'X-Client-Name'              => 'zeno-edd',
				'X-Client-Version'           => ZNCCEDD_VERSION,
				'X-Client-Platform'          => 'WordPress',
				'X-Client-Platform-Version'  => get_bloginfo('version'),
			),
			'body'    => wp_json_encode($payload),
			'timeout' => 25,
		)
	);

	if (is_wp_error($response)) {
		edd_record_gateway_error(
			__('Payment Error', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			'Zeno API WP_Error: ' . $response->get_error_message(),
			$payment_id
		);

		edd_update_payment_status($payment_id, 'failed');
		edd_set_error('znccedd_api_error', 'Zeno API WP_Error: ' . $response->get_error_message());
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		return;
	}

	$body        = json_decode(wp_remote_retrieve_body($response), true);
	$checkout_url = isset($body['checkoutUrl']) ? (string) $body['checkoutUrl'] : '';

	if (empty($checkout_url)) {
		edd_record_gateway_error(
			__('Payment Error', 'zeno-crypto-checkout-for-easy-digital-downloads'),
			'Zeno API missing checkoutUrl. Response: ' . wp_remote_retrieve_body($response),
			$payment_id
		);

		edd_update_payment_status($payment_id, 'failed');
		edd_set_error('znccedd_api_error', 'Zeno API Error: Invalid API key');
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		return;
	}

	edd_update_payment_meta($payment_id, '_znccedd_payment_url', esc_url_raw($checkout_url));

	// IMPORTANT: allow pay.zenobank.io redirect and redirect using EDD helper.
	add_filter('allowed_redirect_hosts', 'znccedd_allow_redirect_to_zeno', 10, 1);

	edd_redirect($checkout_url);
	exit;
}
add_action('edd_gateway_' . ZNCCEDD_GATEWAY_ID, 'znccedd_process_purchase');
