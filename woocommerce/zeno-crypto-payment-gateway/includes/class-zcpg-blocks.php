<?php
if (! defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class ZCPG_Blocks_Gateway extends AbstractPaymentMethodType
{

    protected $name = 'zcpg_gateway';
    protected $settings = [];

    public function initialize()
    {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
    }

    public function is_active()
    {
        if (! isset($this->settings['enabled']) || 'yes' !== $this->settings['enabled']) {
            return false;
        }

        $test_mode = isset($this->settings['test_mode']) && 'yes' === $this->settings['test_mode'];

        // When test mode is enabled, only admins should see the gateway.
        if ($test_mode && ! current_user_can('manage_woocommerce') && ! current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    public function get_payment_method_data()
    {
        $icon = $this->get_icon_url();

        return [
            'title'       => $this->settings['title'] ?? __('USDT, USDC, Binance Pay', 'zeno-crypto-payment-gateway'),
            'description' => $this->settings['description'] ?? '',
            'supports'    => ['products', 'block'],
            'icon'        => $icon,
            'testMode'    => isset($this->settings['test_mode']) && 'yes' === $this->settings['test_mode'],
            'isAdmin'     => current_user_can('manage_woocommerce') || current_user_can('manage_options'),
        ];
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'zcpg-blocks',
            plugins_url('assets/js/blocks.js', __FILE__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element'],
            '1.1.0',
            true
        );
        return ['zcpg-blocks'];
    }

    /**
     * Resolve the icon URL for block-based checkout.
     *
     * Mirrors the logic in the main gateway class.
     *
     * @return string
     */
    private function get_icon_url(): string
    {
        $logo_mode    = $this->settings['logo_mode'] ?? 'default';
        $custom_logo  = $this->settings['custom_logo_url'] ?? '';
        $default_logo = ZCPG_PLUGIN_URL . 'assets/checkout-logo.png';
        $alt_2        = ZCPG_PLUGIN_URL . 'assets/checkout-logo-2.png';
        $alt_3        = ZCPG_PLUGIN_URL . 'assets/checkout-logo-3.png';

        switch ($logo_mode) {
            case 'none':
                return '';
            case 'custom':
                $url = esc_url_raw((string) $custom_logo);
                return $url ?: $default_logo;
            case 'default2':
                return $alt_2;
            case 'default3':
                return $alt_3;
            case 'default':
            default:
                return $default_logo;
        }
    }
}
