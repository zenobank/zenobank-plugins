<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

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
$secretKey = decrypt($secretKey);

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

// Validate invoice ID
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Idempotency: if this transaction was already recorded, return 200 OK
$existing = Capsule::table('tblaccounts')
    ->where('transid', $transactionId)
    ->where('gateway', $gatewayModuleName)
    ->first();

if ($existing) {
    logTransaction($gatewayParams['name'], $rawBody, 'Duplicate webhook (already processed)');
    http_response_code(200);
    exit('OK');
}

logTransaction($gatewayParams['name'], $rawBody, 'Success');

addInvoicePayment(
    $invoiceId,
    $transactionId,
    $paymentAmount,
    $paymentFee,
    $gatewayModuleName
);

// Clean up cached checkout for this invoice
try {
    Capsule::table('mod_zenocrypto_checkouts')
        ->where('invoice_id', $invoiceId)
        ->delete();
} catch (\Exception $e) {
    // Non-fatal
}

http_response_code(200);
exit('OK');
