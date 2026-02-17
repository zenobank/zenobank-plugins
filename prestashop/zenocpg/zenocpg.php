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
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

define('ZCPG_API_ENDPOINT', 'https://api.zenobank.io');
define('_WEBHOOK_ROUTE_', 'zcpg/webhook/checkout');
define('_ZENO_DB_TABLE_', 'zenocpg');

class Zenocpg extends PaymentModule
{
    protected $output = '';
    protected $_postErrors = [];

    public function __construct()
    {
        $this->name = 'zenocpg';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Zeno Bank';
        $this->need_instance = 0;
        $this->controllers = ['redirect', 'confirmation'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Zeno Crypto Checkout');
        $this->description = $this->l('Accept Crypto Payments in USDT and USDC across Ethereum, BNB Chain, Arbitrum, Base, Polygon, Solana, and Binance Pay');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Zeno Crypto Checkout module?');

        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        Configuration::updateValue('ZENO_CPG_LIVE_MODE', false);
        Configuration::updateValue('ZENO_CPG_TITLE', 'USDT, USDC, Binance Pay');
        Configuration::updateValue('ZENO_CPG_DESCRIPTION', 'Pay with Crypto');

        $this->addOrderStateZenoWaiting('Awaiting ZENO payment');
        $this->addOrderStateZenoAccepted('ZENO payment accepted');
        $this->addOrderStateZenoExpired('ZENO payment expired');

        Configuration::updateValue('ZENO_CPG_OS_WAITING', (int) Configuration::getGlobalValue('ZENO_WAITING_PAYMENT'));
        Configuration::updateValue('ZENO_CPG_OS_COMPLETED', (int) Configuration::getGlobalValue('ZENO_PAYMENT_ACCEPTED'));
        Configuration::updateValue('ZENO_CPG_OS_EXPIRED', (int) Configuration::getGlobalValue('ZENO_PAYMENT_EXPIRED'));

        require_once __DIR__ . '/sql/install.php';

        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayOrderConfirmation')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('moduleRoutes')
        ;
    }

    public function uninstall()
    {
        Configuration::deleteByName('ZENO_CPG_LIVE_MODE');
        Configuration::deleteByName('ZENO_CPG_TITLE');
        Configuration::deleteByName('ZENO_CPG_DESCRIPTION');
        Configuration::deleteByName('ZENO_CPG_API_KEY');
        Configuration::deleteByName('ZENO_WAITING_PAYMENT');
        Configuration::deleteByName('ZENO_PAYMENT_ACCEPTED');
        Configuration::deleteByName('ZENO_PAYMENT_EXPIRED');
        Configuration::deleteByName('ZENO_CPG_OS_WAITING');
        Configuration::deleteByName('ZENO_CPG_OS_COMPLETED');
        Configuration::deleteByName('ZENO_CPG_OS_EXPIRED');

        return parent::uninstall();
    }

    /**
     * Load the configuration form
     */
    public function getContent()
    {
        /* If values have been submitted in the form, process. */
        if (((bool) Tools::isSubmit('submitZenocpgModule')) == true) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->output .= $this->displayError($err);
                }
            }
        } else {
            $this->output .= '<br />';
        }

        if (!Configuration::get('ZENO_CPG_API_KEY')) {
            $this->output .= $this->displayWarning($this->l('Please enter your API Key to start accepting payments. Get your API key at https://dashboard.zenobank.io/'));
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $this->output .= $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $this->output . $this->renderForm();
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('submitZenocpgModule')) {
            $apiKeyValue = Tools::getValue('ZENO_CPG_API_KEY');
            if (!$apiKeyValue && !Configuration::get('ZENO_CPG_API_KEY')) {
                $this->_postErrors[] = $this->trans(
                    'API Key are required.',
                    [],
                    'Modules.Zenocpg.Admin'
                );
            }
            if (!Tools::getValue('ZENO_CPG_TITLE')) {
                $this->_postErrors[] = $this->trans(
                    'Title is required.',
                    [],
                    'Modules.Zenocpg.Admin'
                );
            }
            if (!Tools::getValue('ZENO_CPG_DESCRIPTION')) {
                $this->_postErrors[] = $this->trans(
                    'Description is required.',
                    [],
                    'Modules.Zenocpg.Admin'
                );
            }
        }
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitZenocpgModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm(), $this->getAdvancedConfigForm()]);
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Zeno Gateway'),
                        'name' => 'ZENO_CPG_LIVE_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('When active, the Zeno crypto payment option will be shown at checkout.'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Active'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Inactive'),
                            ],
                        ],
                    ],
                    [
                        'col' => 5,
                        'type' => 'password',
                        'name' => 'ZENO_CPG_API_KEY',
                        'placeholder' => 'Enter your API key',
                        'label' => $this->l('API Key'),
                        'desc' => $this->l('Get your API key here: ') . '<a href="https://dashboard.zenobank.io/" target="_blank">https://dashboard.zenobank.io/</a>',
                        'required' => true,
                    ],
                    [
                        'col' => 5,
                        'type' => 'text',
                        'desc' => $this->l('Text that the customer sees at the checkout.'),
                        'name' => 'ZENO_CPG_TITLE',
                        'label' => $this->l('Title'),
                        'required' => true,
                    ],
                    [
                        'col' => 5,
                        'line' => 5,
                        'type' => 'text',
                        'desc' => $this->l('Text that the customer sees at the checkout.'),
                        'name' => 'ZENO_CPG_DESCRIPTION',
                        'label' => $this->l('Description'),
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    protected function getAdvancedConfigForm()
    {
        $order_states = $this->getOrderStateOptions();

        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Advanced'),
                    'icon' => 'icon-cog',
                ],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => $this->l('Order created status'),
                        'name' => 'ZENO_CPG_OS_WAITING',
                        'desc' => $this->l('Order status when the order is created and awaiting payment.'),
                        'options' => [
                            'query' => $order_states,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Payment completed status'),
                        'name' => 'ZENO_CPG_OS_COMPLETED',
                        'desc' => $this->l('Order status when the payment has been completed.'),
                        'options' => [
                            'query' => $order_states,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Checkout expired status'),
                        'name' => 'ZENO_CPG_OS_EXPIRED',
                        'desc' => $this->l('Order status when the checkout has expired.'),
                        'options' => [
                            'query' => $order_states,
                            'id' => 'id_order_state',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    protected function getOrderStateOptions()
    {
        return OrderState::getOrderStates((int) $this->context->language->id);
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return [
            'ZENO_CPG_LIVE_MODE' => Configuration::get('ZENO_CPG_LIVE_MODE', null),
            'ZENO_CPG_TITLE' => Configuration::get('ZENO_CPG_TITLE', null),
            'ZENO_CPG_DESCRIPTION' => Configuration::get('ZENO_CPG_DESCRIPTION', null),
            'ZENO_CPG_API_KEY' => Configuration::get('ZENO_CPG_API_KEY', null),
            'ZENO_CPG_OS_WAITING' => Configuration::get('ZENO_CPG_OS_WAITING') ?: Configuration::getGlobalValue('ZENO_WAITING_PAYMENT'),
            'ZENO_CPG_OS_COMPLETED' => Configuration::get('ZENO_CPG_OS_COMPLETED') ?: Configuration::getGlobalValue('ZENO_PAYMENT_ACCEPTED'),
            'ZENO_CPG_OS_EXPIRED' => Configuration::get('ZENO_CPG_OS_EXPIRED') ?: Configuration::getGlobalValue('ZENO_PAYMENT_EXPIRED'),
        ];
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            if ($key === 'ZENO_CPG_API_KEY') {
                $value = Tools::getValue($key);
                if ($value) {
                    Configuration::updateValue($key, $value);
                }
                continue;
            }
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be loaded in the BO.
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (Tools::getValue('configure') == $this->name) {
            $this->context->controller->addJS($this->_path . 'views/js/back.js');
            $this->context->controller->addCSS($this->_path . 'views/css/back.css');
        }
    }

    /**
     * Add the CSS & JavaScript files you want to be added on the FO.
     */
    public function hookDisplayHeader()
    {
        $this->context->controller->addJS($this->_path . '/views/js/front.js');
        $this->context->controller->addCSS($this->_path . '/views/css/front.css');
    }

    /**
     * This hook is used to display the order confirmation page.
     */
    public function hookDisplayPaymentReturn($params)
    {
        if ($this->active == false) {
            return;
        }

        $order = $params['objOrder'];

        $totalToPaid = $params['order']->getOrdersTotalPaid();

        $this->smarty->assign(
            [
            'id_order' => $order->id,
            'reference' => $order->reference,
            'params' => $params,
            'total' => $this->context->getCurrentLocale()->formatPrice($totalToPaid, (new Currency($params['order']->id_currency))->iso_code),
            ]
        );

        return $this->display(__FILE__, 'views/templates/hook/confirmation.tpl');
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }
        // check Enable Zeno Gateway
        if (!Configuration::get('ZENO_CPG_LIVE_MODE')) {
            return [];
        }
        // check API key
        if (Configuration::get('ZENO_CPG_API_KEY') == '') {
            return [];
        }
        if (!$this->checkCurrency($params['cart'])) {
            return [];
        }

        $paymentOptions = new PaymentOption();
        $paymentOptions->setModuleName($this->name);
        $paymentOptions->setCallToActionText(Configuration::get('ZENO_CPG_TITLE', null) != '' ? Configuration::get('ZENO_CPG_TITLE', null) : $this->l('USDT, USDC, Binance pay'));
        $paymentOptions->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true));
        $paymentOptions->setAdditionalInformation(Configuration::get('ZENO_CPG_DESCRIPTION', null) != '' ? Configuration::get('ZENO_CPG_DESCRIPTION', null) : $this->l('Pay with crypto'));
        $payment_image = _MODULE_DIR_ . $this->name . '/checkout-logo.png';
        $paymentOptions->setLogo($payment_image);

        return [$paymentOptions];
    }

    public function hookDisplayOrderConfirmation()
    {
        /* Place your code here. */
    }

    public function hookModuleRoutes($params)
    {
        return [
            'module-zcpg-webhook' => [
                'controller' => 'webhook',
                'rule' => _WEBHOOK_ROUTE_,
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'zenocpg',
                ],
            ],
        ];
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function addOrderStateZenoWaiting($state_name)
    {
        $state_exist = false;
        $states = OrderState::getOrderStates((int) $this->context->language->id);

        // check if order state exist
        foreach ($states as $state) {
            if (in_array($state_name, $state)) {
                $state_exist = true;
                break;
            }
        }

        // If the state does not exist, we create it.
        if (!$state_exist) {
            // create new order state
            $order_state = new OrderState();
            $order_state->color = '#34209E';
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->template = [];
            $order_state->name = [];
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                $order_state->name[$language['id_lang']] = $state_name;
            }

            // Update object
            $order_state->add();
            Configuration::updateGlobalValue('ZENO_WAITING_PAYMENT', (int) $order_state->id);
        }
        return true;
    }

    public function addOrderStateZenoExpired($state_name)
    {
        $state_exist = false;
        $states = OrderState::getOrderStates((int) $this->context->language->id);

        foreach ($states as $state) {
            if (in_array($state_name, $state)) {
                $state_exist = true;
                break;
            }
        }

        if (!$state_exist) {
            $order_state = new OrderState();
            $order_state->color = '#DC143C';
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->template = [];
            $order_state->name = [];
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                $order_state->name[$language['id_lang']] = $state_name;
            }
            $order_state->add();
            Configuration::updateGlobalValue('ZENO_PAYMENT_EXPIRED', (int) $order_state->id);
        }
        return true;
    }

    public function addOrderStateZenoAccepted($state_name)
    {
        $state_exist = false;
        $states = OrderState::getOrderStates((int) $this->context->language->id);

        // check if order state exist
        foreach ($states as $state) {
            if (in_array($state_name, $state)) {
                $state_exist = true;
                break;
            }
        }

        // If the state does not exist, we create it.
        if (!$state_exist) {
            // create new order state
            $order_state = new OrderState();
            $order_state->color = '#3498D8';
            $order_state->send_email = false;
            $order_state->module_name = $this->name;
            $order_state->template = [];
            $order_state->name = [];
            $languages = Language::getLanguages(false);
            foreach ($languages as $language) {
                $order_state->name[$language['id_lang']] = $state_name;
            }
            // Update object
            $order_state->add();
            Configuration::updateGlobalValue('ZENO_PAYMENT_ACCEPTED', (int) $order_state->id);
        }
        return true;
    }
}
