<?php

/**
 * WHMCS Zeno Crypto Gateway Module
 * @author     Anurag Rathore <anuragr1983@yahoo.com>
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Define module related meta data.
 */
function zenocrypto_MetaData()
{
    return array(
        'DisplayName' => 'Zeno Crypto Gateway',
        'APIVersion' => '1.1', // Use API Version 1.1
    );
}

/**
 * Define gateway configuration options.
 */
function zenocrypto_config()
{
    $setting = Capsule::table('tblpaymentgateways')
        ->where('gateway', 'zenocrypto')
        ->where('setting', 'secret_key')
        ->value('value');

    if (!$setting) {
        $hex = bin2hex(random_bytes(16));
        $uuid = substr($hex, 0, 8) . '-' .
            substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' .
            substr($hex, 20);

        Capsule::table('tblpaymentgateways')->insert([
            'gateway' => 'zenocrypto',
            'setting' => 'secret_key',
            'value'   => $uuid,
        ]);
    }

    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Zeno Crypto Gateway',
        ),
        // a text field type allows for single line text input
        'api_key' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Enter your API key here',
        ),

    );
}

/**
 * Payment link.
 */
function zenocrypto_link($params)
{
    // Gateway Configuration Parameters
    $apiKey = $params['api_key'];
    if (empty($apiKey)) {
        return '<div class="alert alert-danger">Gateway misconfigured: missing API key.</div>';
    }

    $secretKey = $params['secret_key'];
    if (empty($secretKey)) {
        return '<div class="alert alert-danger">Gateway misconfigured: missing secret key.</div>';
    }

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    // System Parameters
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $token = hash_hmac(
        'sha256',
        $invoiceId,
        $secretKey
    );

    // Create a new checkout
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.zenobank.io/api/v1/checkouts",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode([
            'orderId' => $invoiceId,
            'priceAmount' => $amount,
            'priceCurrency' => $currencyCode,
            'webhookUrl' => $systemUrl . '/modules/gateways/callback/' . $moduleName . '.php',
            'verificationToken' => $token,
            'successRedirectUrl' => $returnUrl
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-API-Key: $apiKey",
            // Client metadata headers
            "X-Client-Type: plugin",
            "X-Client-Name: zeno-whmcs",
            "X-Client-Version: 1.0.0",          // e.g. 1.0.0
            "X-Client-Platform: whmcs",
            "X-Client-Platform-Version: " . $whmcsVersion   // e.g. 8.8.0
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($err) {
        return '<div class="alert alert-danger">cURL Error: ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</div>';
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        logTransaction('zenocrypto', $response, 'API error HTTP ' . $httpCode);
        return '<div class="alert alert-danger">Payment gateway returned an error (HTTP ' . $httpCode . '). Please try again.</div>';
    }

    $result = json_decode($response);
    if (!is_object($result) || empty($result->checkoutUrl)) {
        return '<div class="alert alert-danger">Could not create checkout. Please try again.</div>';
    }

    $url = htmlspecialchars($result->checkoutUrl, ENT_QUOTES, 'UTF-8');
    $htmlOutput = '<form method="get" action="' . $url . '">';
    $htmlOutput .= '<input type="submit" value="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
