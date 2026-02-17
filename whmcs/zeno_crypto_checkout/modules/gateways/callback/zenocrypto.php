<?php

/**
 * WHMCS Zeno Crypto Gateway Module
 * @author     Anurag Rathore <anuragr1983@yahoo.com>
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    http_response_code(500);
    exit('Module not activated');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Retrieve data returned in payment gateway callback
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Debug
//logActivity('Raw Data: '.$rawBody, 0);

if (!isset($data['data']) || !is_array($data['data'])) {
    logTransaction($gatewayParams['name'], $rawBody, 'Invalid payload: missing data object');
    http_response_code(400);
    exit('Invalid payload');
}

$status = $data['data']['status'] ?? '';

if ($status !== 'COMPLETED') {
    logTransaction($gatewayParams['name'], $rawBody, 'Ignored: status ' . $status);
    http_response_code(200);
    exit('OK');
}

$requiredFields = ['orderId', 'id', 'verificationToken'];
foreach ($requiredFields as $field) {
    if (!isset($data['data'][$field])) {
        logTransaction($gatewayParams['name'], $rawBody, 'Missing required field: ' . $field);
        http_response_code(400);
        exit('Missing required field: ' . $field);
    }
}

$invoiceId = $data['data']['orderId'];
$transactionId = $data['data']['id'];
$paymentAmount = $data['data']['priceAmount'] ?? '';
$currencyCode = $data['data']['priceCurrency'] ?? '';
$paymentFee = 0;
$hash = $data['data']['verificationToken'];

/**
 * Validate callback authenticity.
 */
$secretKey = $gatewayParams['secret_key'];

if (empty($secretKey)) {
    logTransaction($gatewayParams['name'], $rawBody, 'Missing secret key');
    http_response_code(500);
    exit('Gateway misconfigured: missing secret key');
}

$expectedToken = hash_hmac(
    'sha256',
    $invoiceId,
    $secretKey
);

if (!hash_equals($expectedToken, (string) $hash)) {
    logTransaction($gatewayParams['name'], $rawBody, 'Verification Token Failure');
    http_response_code(403);
    exit('Verification token mismatch');
}

/**
 * Validate Callback Invoice ID.
 *
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 *
 * Performs a die upon encountering an invalid Invoice ID.
 *
 * Returns a normalised invoice ID.
 *
 * @param int $invoiceId Invoice ID
 * @param string $gatewayName Gateway Name
 */
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given
 * transaction number.
 *
 * Performs a die upon encountering a duplicate.
 *
 * @param string $transactionId Unique Transaction ID
 */
checkCbTransID($transactionId);

/**
 * Log Transaction.
 *
 * Add an entry to the Gateway Log for debugging purposes.
 *
 * The debug data can be a string or an array. In the case of an
 * array it will be
 *
 * @param string $gatewayName        Display label
 * @param string|array $debugData    Data to log
 * @param string $transactionStatus  Status
 */
logTransaction($gatewayParams['name'], $rawBody, 'Success');

/**
 * Add Invoice Payment.
 *
 * Applies a payment transaction entry to the given invoice ID.
 *
 * @param int $invoiceId         Invoice ID
 * @param string $transactionId  Transaction ID
 * @param float $paymentAmount   Amount paid (defaults to full balance)
 * @param float $paymentFee      Payment fee (optional)
 * @param string $gatewayModule  Gateway module name
 */
addInvoicePayment(
    $invoiceId,
    $transactionId,
    $paymentAmount,
    $paymentFee,
    $gatewayModuleName
);
http_response_code(200);
exit('OK');
