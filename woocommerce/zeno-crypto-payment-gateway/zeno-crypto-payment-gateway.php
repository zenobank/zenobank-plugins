<?php

/**
 * Plugin Name: Zeno Crypto Checkout
 * Description: Accept Crypto Payments in USDT and USDC across Ethereum, BNB Chain, Arbitrum, Base, Polygon, Solana, and Binance Pay
 * Version: 1.1.0
 * Author: Zeno Bank
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL2
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('ZCPG_PLUGIN_FILE', __FILE__);
define('ZCPG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZCPG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZCPG_VERSION', '1.1.0');
define('ZCPG_API_ENDPOINT', 'https://api.zenobank.io');


// Load WooCommerce classes and register gateway + endpoints
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {

        if (!class_exists('WC_Payment_Gateway')) return;

        require_once ZCPG_PLUGIN_DIR . 'includes/class-zcpg-gateway.php';
        require_once ZCPG_PLUGIN_DIR . 'includes/class-zcpg-webhook.php';

        // Webhook REST
        (new ZCPG_Webhook())->register();

        // Return URL (?wc-api=zcpg_return)
        add_action('woocommerce_api_zcpg_return', ['ZCPG_Gateway', 'handle_return']);
    } else {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('Zeno Crypto Payment Gateway requires WooCommerce to be installed and active.', 'zeno-crypto-payment-gateway');
            echo '</p></div>';
        });

        // Desactivar el plugin
        deactivate_plugins(plugin_basename(__FILE__));
    }
}, 11);

/*Add gateway*/
add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'ZCPG_Gateway';
    return $gateways;
});

// Show the gateway only to administrators when the mode is "test".
add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if (is_admin()) {
        return $gateways;
    }

    if (empty($gateways['zcpg_gateway'])) {
        return $gateways;
    }

    $settings  = get_option('woocommerce_zcpg_gateway_settings', []);
    $test_mode = isset($settings['test_mode']) && 'yes' === $settings['test_mode'];

    if ($test_mode && ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
        unset($gateways['zcpg_gateway']);
    }

    return $gateways;
}, 20);

add_action('woocommerce_blocks_loaded', function () {
    if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        require_once ZCPG_PLUGIN_DIR . 'includes/class-zcpg-blocks.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new ZCPG_Blocks_Gateway());
            }
        );
    }
});

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});



// Generate secrets automatically when the plugin is activated
register_activation_hook(__FILE__, function () {
    $option_key = 'woocommerce_zcpg_gateway_settings';
    $settings   = get_option($option_key, []);

    // Generate secrets if they don't exist
    if (empty($settings['secret_test'])) {
        $settings['secret_test'] = wp_generate_password(32, false, false);
    }
    if (empty($settings['secret_live'])) {
        $settings['secret_live'] = wp_generate_password(32, false, false);
    }

    update_option($option_key, $settings);
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'zcpg_gateway_action_links');

function zcpg_gateway_action_links($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=zcpg_gateway') . '">'
        . __('Settings', 'zeno-crypto-payment-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
