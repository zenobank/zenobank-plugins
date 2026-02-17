<?php
/**
 * 2007-2026 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2026 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class ZenocpgRedirectModuleFrontController extends ModuleFrontController
{
    /**
     * Do whatever you have to before redirecting the customer on the website of your payment processor.
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        $id_cart = $cart->id;
        $id_currency = $cart->id_currency;
        $currency = Currency::getIsoCodeById($id_currency);
        $amount = $cart->getOrderTotal(true, Cart::BOTH);
        $id_customer = $cart->id_customer;
        $customer = new Customer($id_customer);
        $secure_key = $customer->secure_key;
        $version = $this->module->version;

        $verification_token = hash_hmac('sha256', (string) $id_cart, $secure_key);
        $success_url = $this->context->link->getModuleLink($this->module->name, 'confirmation', ['cart_id' => $id_cart], true);
        $no_payment_url = $this->context->link->getPageLink('order');
        $webhook_url = $this->context->link->getBaseLink() . _WEBHOOK_ROUTE_;

        $payload = json_encode([
            'version' => (string) $version,
            'platform' => 'prestashop',
            'priceAmount' => (string) $amount,
            'priceCurrency' => (string) $currency,
            'orderId' => (string) $id_cart,
            'successRedirectUrl' => (string) $success_url,
            'verificationToken' => (string) $verification_token,
            'webhookUrl' => (string) $webhook_url,
        ]);

        $headers = [
            'x-api-key: ' . (string) Configuration::get('ZENO_CPG_API_KEY', null),
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Client-Type: plugin',
            'X-Client-Name: zeno-prestashop',
            'X-Client-Version: ' . (string) $version,
            'X-Client-Platform: prestashop',
            'X-Client-Platform-Version: ' . (string) _PS_VERSION_,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, ZCPG_API_ENDPOINT . '/api/v1/checkouts');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $curl_error = curl_errno($ch);
        curl_close($ch);

        if ($curl_error || !$response) {
            Tools::redirect($no_payment_url);
            return;
        }

        $body = json_decode($response, true);
        $payment_url = isset($body['checkoutUrl']) ? (string) $body['checkoutUrl'] : '';
        $id_zeno_payment = isset($body['id']) ? (string) $body['id'] : '';

        if (!$payment_url) {
            Tools::redirect($no_payment_url);
            return;
        }

        // Checkout created successfully, now create the order
        $payment_status = (int) Configuration::get('ZENO_CPG_OS_WAITING') ?: (int) Configuration::getGlobalValue('ZENO_WAITING_PAYMENT');

        $this->module->validateOrder(
            (int) $id_cart,
            $payment_status,
            (float) $amount,
            $this->module->displayName,
            null,
            [],
            (int) $id_currency,
            false,
            $secure_key
        );

        // Save zeno payment reference
        $current_date = date('Y-m-d H:i:s');
        $query_find = 'SELECT id_cart FROM `' . _DB_PREFIX_ . _ZENO_DB_TABLE_ . '` WHERE id_cart = ' . (int) $id_cart;
        $exists = Db::getInstance()->getValue($query_find);

        if (!$exists) {
            Db::getInstance()->execute(
                "INSERT INTO `" . _DB_PREFIX_ . _ZENO_DB_TABLE_ . "` (id_cart, id_zeno_payment, date_created) VALUES ('" . (int) $id_cart . "', '" . pSQL($id_zeno_payment) . "', '" . pSQL($current_date) . "')"
            );
        } else {
            Db::getInstance()->execute(
                "UPDATE `" . _DB_PREFIX_ . _ZENO_DB_TABLE_ . "` SET id_zeno_payment = '" . pSQL($id_zeno_payment) . "', date_created = '" . pSQL($current_date) . "' WHERE id_cart = '" . (int) $id_cart . "'"
            );
        }

        Tools::redirect($payment_url);
    }
}
