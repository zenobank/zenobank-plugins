<?php
defined('ABSPATH') || exit;

/**
 * Register REST route for Zeno payment webhook.
 * Public by design (Zeno POSTs here); auth via verificationToken in callback.
 */
function zc_edd_register_webhook_route()
{
	add_action(
		'rest_api_init',
		function () {
			register_rest_route(
				'zcpg/v1',
				'/webhook',
				array(
					'methods'             => 'POST',
					'callback'            => 'zc_edd_handle_webhook',
					'permission_callback' => '__return_true',
				)
			);
		}
	);
}

/**
 * Handle Zeno webhook. Auth via verificationToken (checked in callback).
 */
function zc_edd_handle_webhook(WP_REST_Request $request)
{
	$body = $request->get_json_params();
	if (empty($body) || ! is_array($body)) {
		$body = array();
	}

	$order_id        = (int) ($body['data']['orderId'] ?? $request->get_param('order_id') ?? 0);
	$received_token  = isset($body['data']['verificationToken']) ? sanitize_text_field($body['data']['verificationToken']) : sanitize_text_field((string) $request->get_param('verification_token'));
	$status          = isset($body['data']['status']) ? sanitize_text_field($body['data']['status']) : sanitize_text_field((string) $request->get_param('status'));

	if (empty($order_id) || empty($received_token)) {
		return new WP_REST_Response(array('ok' => false, 'error' => 'missing_order_or_token'), 400);
	}

	$expected_token = hash_hmac('sha256', (string) $order_id, edd_get_option('zc_edd_secret_live', ''));

	if (! hash_equals($expected_token, $received_token)) {
		return new WP_REST_Response(array('ok' => false, 'error' => 'invalid_token'), 403);
	}

	$payment = edd_get_payment($order_id);
	if (empty($payment)) {
		return new WP_REST_Response(array('ok' => false, 'error' => 'payment_not_found'), 404);
	}

	if ('COMPLETED' === strtoupper($status)) {
		edd_update_payment_status($order_id, 'complete');
		edd_insert_payment_note($order_id, __('Payment confirmed via Zeno webhook.', 'zeno-crypto-checkout-for-easy-digital-downloads'));
	}

	return new WP_REST_Response(
		array(
			'ok'      => true,
			'order_id' => $order_id,
			'status'   => $status,
		),
		200
	);
}
