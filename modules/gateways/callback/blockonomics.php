<?php

// Require libraries needed for gateway module functions.
require '../../../init.php';
require '../../../includes/gatewayfunctions.php';
require '../../../includes/invoicefunctions.php';

require '../blockonomics/blockonomics.php';

use Blockonomics\Blockonomics;

// Init Blockonomics class
$blockonomics = new Blockonomics();

$gatewayModuleName = 'blockonomics';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    exit('Module Not Activated');
}

require_once $blockonomics->getLangFilePath();

// Retrieve data returned in payment gateway callback
$secret = htmlspecialchars($_GET['secret']);
$status = htmlspecialchars($_GET['status']);
$addr = htmlspecialchars($_GET['addr']);
$value = htmlspecialchars($_GET['value']);
$txid = htmlspecialchars($_GET['txid']);

/**
 * Validate callback authenticity.
 */
$secret_value = $blockonomics->getCallbackSecret();

if ($secret_value != $secret) {
    $transactionStatus = $_BLOCKLANG['error']['secret'];
    $success = false;

    echo $transactionStatus;
    exit();
}

$order = $blockonomics->getOrderByAddress($addr);
$invoiceId = $order['order_id'];
$bits = $order['bits'];

// If this is test transaction, generate new transaction ID
if ($txid == 'WarningThisIsAGeneratedTestPaymentAndNotARealBitcoinTransaction') {
    $txid = 'WarningThisIsATestTransaction_' . $addr;
}

$confirmations = $blockonomics->getConfirmations();

$blockonomics_currency_code = $order['blockonomics_currency'];
$blockonomics_currency = $blockonomics->getSupportedCurrencies()[$blockonomics_currency_code];
if ($blockonomics_currency_code == 'btc') {
    $subdomain = 'www';
} else {
    $subdomain = $blockonomics_currency_code;
}

$systemUrl = \App::getSystemURL();
if ($status < $confirmations) {
    $invoiceNote = '<b>' . $_BLOCKLANG['invoiceNote']['waiting'] . ' <img src="' . $systemUrl . 'modules/gateways/blockonomics/assets/img/' . $blockonomics_currency_code . '.png" style="max-width: 20px;"> ' . $blockonomics_currency->name . ' ' . $_BLOCKLANG['invoiceNote']['network'] . "</b>\r\r" .
    $blockonomics_currency->name . " transaction id:\r" .
        '<a target="_blank" href="https://' . $subdomain . ".blockonomics.co/api/tx?txid=$txid&addr=$addr\">$txid</a>";

    $blockonomics->updateOrderInDb($addr, $txid, $status, $value);
    $blockonomics->updateInvoiceNote($invoiceId, $invoiceNote);

    exit();
}

$expected = $bits / 1.0e8;
$paid = $value / 1.0e8;

$underpayment_slack = $blockonomics->getUnderpaymentSlack() / 100 * $bits;
if ($value < $bits - $underpayment_slack) {
    $btcAmount = $paid;
} else {
    $btcAmount = $expected;
}
$percentPaid = $btcAmount / $expected * 100;
$paymentAmount = $blockonomics->convertPercentPaidToInvoiceCurrency($order, $percentPaid);
$blockonomics->updateInvoiceNote($invoiceId, null);
$blockonomics->updateOrderInDb($addr, $txid, $status, $value);

/**
 * Validate Callback Invoice ID.
 *
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 *
 * Performs a exit upon encountering an invalid Invoice ID.
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
 * Performs a exit upon encountering a duplicate.
 *
 * @param string $transactionId Unique Transaction ID
 */

if ($blockonomics->checkIfTransactionExists($blockonomics_currency_code . ' - ' . $txid)) {
    exit();
}

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
logTransaction($gatewayParams['name'], $_GET, 'Successful');

$paymentFee = 0;

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
    $blockonomics_currency_code . ' - ' . $txid,
    $paymentAmount,
    $paymentFee,
    $gatewayModuleName
);
