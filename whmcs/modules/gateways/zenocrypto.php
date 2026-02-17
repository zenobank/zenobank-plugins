<?php


if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Define module related meta data.
 */
function zenocrypto_MetaData()
{
    return [
        'DisplayName' => 'Zeno Crypto Gateway',
        'APIVersion' => '1.1',
    ];
}

/**
 * Define gateway configuration options.
 * Also handles one-time setup (secret key + cache table) since
 * payment gateways don't have activate/deactivate hooks.
 */
function zenocrypto_config()
{
    // One-time setup: generate secret key if missing
    try {
        $existing = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'zenocrypto')
            ->where('setting', 'secret_key')
            ->value('value');

        if (!$existing) {
            $hex = bin2hex(random_bytes(16));
            $uuid = substr($hex, 0, 8) . '-' .
                substr($hex, 8, 4) . '-' .
                substr($hex, 12, 4) . '-' .
                substr($hex, 16, 4) . '-' .
                substr($hex, 20);

            Capsule::table('tblpaymentgateways')->insert([
                'gateway' => 'zenocrypto',
                'setting' => 'secret_key',
                'value'   => encrypt($uuid),
            ]);
        }

        // One-time setup: create checkouts cache table
        if (!Capsule::schema()->hasTable('mod_zenocrypto_checkouts')) {
            Capsule::schema()->create('mod_zenocrypto_checkouts', function ($table) {
                $table->unsignedInteger('invoice_id')->primary();
                $table->string('checkout_url', 512);
                $table->decimal('amount', 16, 8);
                $table->string('currency', 10);
                $table->timestamp('created_at')->useCurrent();
            });
        }
    } catch (\Exception $e) {
        // Silently fail during gateway discovery
    }

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'USDT, USDC, Binance Pay',
        ],
        'api_key' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
            'Description' => '<a href="https://dashboard.zenobank.io/" target="_blank">Get your API key here</a>',
        ],
    ];
}

/**
 * Payment link.
 */
function zenocrypto_link($params)
{
    // Gateway Configuration Parameters
    $apiKey = $params['api_key'];
    if (empty($apiKey)) {
        return '';
    }

    $secretKey = $params['secret_key'];
    if (empty($secretKey)) {
        // Lazy init: secret key wasn't created yet (admin never opened config)
        zenocrypto_config();
        $secretKey = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'zenocrypto')
            ->where('setting', 'secret_key')
            ->value('value');
        if (empty($secretKey)) {
            return '';
        }
    }
    $secretKey = decrypt($secretKey);

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

    // Check for a cached checkout URL for this invoice
    try {
        $cached = Capsule::table('mod_zenocrypto_checkouts')
            ->where('invoice_id', $invoiceId)
            ->first();

        // Reuse cached checkout if amount and currency haven't changed
        if ($cached
            && bccomp((string) $cached->amount, (string) $amount, 8) === 0
            && $cached->currency === $currencyCode
        ) {
            $url = htmlspecialchars($cached->checkout_url, ENT_QUOTES, 'UTF-8');
            $htmlOutput = '<form method="get" action="' . $url . '">';
            $htmlOutput .= '<input type="submit" value="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" />';
            $htmlOutput .= '</form>';
            return $htmlOutput;
        }
    } catch (\Exception $e) {
        // If cache lookup fails, continue to create a new checkout
    }

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
            'webhookUrl' => rtrim($systemUrl, '/') . '/modules/gateways/callback/' . $moduleName . '.php',
            'verificationToken' => $token,
            'successRedirectUrl' => $returnUrl
        ]),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-API-Key: $apiKey",
            "X-Client-Type: plugin",
            "X-Client-Name: zeno-whmcs",
            "X-Client-Version: 1.0.0",
            "X-Client-Platform: whmcs",
            "X-Client-Platform-Version: $whmcsVersion"
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
        logTransaction('zenocrypto', $response, "API error HTTP $httpCode");
        return '<div class="alert alert-danger">Payment gateway returned an error (HTTP ' . $httpCode . '). Please try again.</div>';
    }

    $result = json_decode($response);
    if (!is_object($result) || empty($result->checkoutUrl)) {
        return '<div class="alert alert-danger">Could not create checkout. Please try again.</div>';
    }

    // Cache the checkout URL for this invoice
    try {
        Capsule::table('mod_zenocrypto_checkouts')->updateOrInsert(
            ['invoice_id' => $invoiceId],
            [
                'checkout_url' => $result->checkoutUrl,
                'amount' => $amount,
                'currency' => $currencyCode,
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    } catch (\Exception $e) {
        // Cache write failure is non-fatal
    }

    $url = htmlspecialchars($result->checkoutUrl, ENT_QUOTES, 'UTF-8');
    $htmlOutput = '<form method="get" action="' . $url . '">';
    $htmlOutput .= '<input type="submit" value="' . htmlspecialchars($langPayNow, ENT_QUOTES, 'UTF-8') . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
