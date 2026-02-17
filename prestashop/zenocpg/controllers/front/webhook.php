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

class ZenocpgWebhookModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!isset($body['data'])) {
            $this->respondJson(['error' => 'Missing data'], 400);
        }

        $data = $body['data'];
        $id_cart = $data['orderId'] ?? null;
        $order_status_back = $data['status'] ?? null;
        $verification_token_back = $data['verificationToken'] ?? null;

        if (!$id_cart || !$order_status_back || !$verification_token_back) {
            $this->respondJson(['error' => 'Missing required fields'], 400);
        }

        $status_map = [
            'COMPLETED' => (int) Configuration::get('ZENO_CPG_OS_COMPLETED') ?: (int) Configuration::getGlobalValue('ZENO_PAYMENT_ACCEPTED') ?: (int) Configuration::get('PS_OS_PAYMENT'),
            'EXPIRED' => (int) Configuration::get('ZENO_CPG_OS_EXPIRED') ?: (int) Configuration::getGlobalValue('ZENO_PAYMENT_EXPIRED') ?: (int) Configuration::get('PS_OS_ERROR'),
        ];

        if (!isset($status_map[$order_status_back])) {
            PrestaShopLogger::addLog('Zeno webhook: unknown status "' . pSQL($order_status_back) . '" for cart ' . (int) $id_cart, 2);
            $this->respondJson(['message' => 'Unknown status, ignored'], 200);
        }

        $sql = 'SELECT id_cart FROM ' . _DB_PREFIX_ . 'cart WHERE id_cart = "' . (int) $id_cart . '"';
        $id_cart = Db::getInstance()->getValue($sql);
        if (!$id_cart) {
            $this->respondJson(['error' => 'Cart not found'], 404);
        }

        $cart = new Cart((int) $id_cart);
        $id_customer = $cart->id_customer;
        $customer = new Customer($id_customer);
        $secure_key = $customer->secure_key;
        $verification_token = hash_hmac('sha256', (string) $id_cart, $secure_key);

        if ($verification_token !== $verification_token_back) {
            $this->respondJson(['error' => 'Invalid verification token'], 401);
        }

        $id_order = Order::getIdByCartId((int) $id_cart);
        if (!$id_order) {
            $this->respondJson(['error' => 'Order not found'], 404);
        }

        $this->updateOrderStatus((int) $id_order, $status_map[$order_status_back]);
        $this->respondJson(['success' => true], 200);
    }

    private function respondJson(array $data, int $status_code)
    {
        http_response_code($status_code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public function updateOrderStatus($id_order, $new_status)
    {
        $order = new Order((int) $id_order);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        if ((int) $order->current_state === (int) $new_status) {
            return true;
        }

        $new_history = new OrderHistory();
        $new_history->id_order = (int) $id_order;
        $new_history->changeIdOrderState((int) $new_status, (int) $id_order, true);
        $new_history->add();

        return true;
    }
}
