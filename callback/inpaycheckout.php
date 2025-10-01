<?php
declare(strict_types=1);

/**
 * iNPAY Checkout Payment Gateway Callback Handler for WHMCS.
 *
 * Handles AJAX verifications and webhook notifications, ensuring
 * idempotent invoice updates and production-ready logging.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;
use Throwable;

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    exit('Module Not Activated');
}

$logEnabled = isset($gatewayParams['gatewayLogs']) && $gatewayParams['gatewayLogs'] === 'on';
$secretKey = trim((string) ($gatewayParams['secretKey'] ?? ''));

/**
 * Conditional logger helper to keep the main flow tidy.
 */
function inpaycheckout_log(bool $enabled, string $moduleName, string $message, string $status = 'Information'): void
{
    if ($enabled) {
        logTransaction($moduleName, $message, $status);
    }
}

/**
 * Check if a transaction reference has already been stored in WHMCS.
 */
function inpaycheckout_transactionExists(string $reference): bool
{
    if ($reference === '') {
        return false;
    }

    try {
        return Capsule::table('tblaccounts')
            ->where('transid', $reference)
            ->exists();
    } catch (Throwable $exception) {
        return false;
    }
}

/**
 * Call iNPAY Checkout to verify a transaction status.
 */
function inpaycheckout_verify_transaction(string $reference, string $secretKey): array
{
    $headers = [
        'Authorization: Bearer ' . $secretKey,
        'Accept: application/json',
    ];

    $endpoints = [
        [
            'method' => 'GET',
            'url' => 'https://api.inpaycheckout.com/api/v1/developer/transaction/status?reference=' . rawurlencode($reference),
            'headers' => $headers,
        ],
        [
            'method' => 'POST',
            'url' => 'https://api.inpaycheckout.com/api/v1/developer/transaction/verify',
            'headers' => array_merge($headers, ['Content-Type: application/json']),
            'payload' => json_encode(['reference' => $reference]),
        ],
    ];

    $lastError = 'Unable to verify transaction';

    foreach ($endpoints as $endpoint) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $endpoint['method']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $endpoint['headers']);

        if (isset($endpoint['payload'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $endpoint['payload']);
        }

        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
        } else {
            curl_setopt($ch, CURLOPT_SSLVERSION, 6);
        }

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $lastError = 'cURL Error: ' . curl_error($ch);
            curl_close($ch);
            continue;
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $lastError = 'API Error: HTTP ' . $httpCode;
            continue;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $lastError = 'Invalid JSON response received';
            continue;
        }

        if (!empty($decoded['success']) && !empty($decoded['data'])) {
            return ['success' => true, 'data' => $decoded['data']];
        }

        $lastError = $decoded['message'] ?? $lastError;
    }

    return ['success' => false, 'error' => $lastError];
}

/**
 * Verify webhook signature according to iNPAY's specification.
 */
function inpaycheckout_verify_webhook_signature(string $payload, string $signature, string $secretKey): bool
{
    if ($payload === '' || $signature === '') {
        return false;
    }

    $cleanSignature = preg_replace('/^sha256=/', '', (string) $signature);
    $expectedSignature = hash_hmac('sha256', $payload, $secretKey);

    return hash_equals($expectedSignature, $cleanSignature);
}

/**
 * Validate webhook timestamp to mitigate replay attempts.
 */
function inpaycheckout_validate_webhook_timestamp($timestamp, int $toleranceMinutes = 5): bool
{
    if ($timestamp === '' || !is_numeric($timestamp)) {
        return false;
    }

    $current = (int) round(microtime(true) * 1000);
    $webhookTime = (int) $timestamp;
    $tolerance = $toleranceMinutes * 60 * 1000;

    return abs($current - $webhookTime) <= $tolerance;
}

/**
 * Persist the payment to the WHMCS invoice when verification confirms success.
 */
function inpaycheckout_process_successful_payment(int $invoiceId, string $reference, $txData, string $gatewayModuleName, bool $logEnabled): void
{
    $invoiceId = (int) $invoiceId;
    $reference = (string) $reference;

    if (is_object($txData)) {
        $txData = (array) $txData;
    }

    inpaycheckout_log(
        $logEnabled,
        $gatewayModuleName,
        'Processing payment for invoice ' . $invoiceId . ' with reference ' . $reference,
        'Information'
    );

    if (inpaycheckout_transactionExists($reference)) {
        inpaycheckout_log(
            $logEnabled,
            $gatewayModuleName,
            'Transaction already recorded, skipping duplicate reference ' . $reference,
            'Information'
        );
        return;
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
    checkCbTransID($reference);

    $amountKobo = isset($txData['amount']) ? (int) $txData['amount'] : 0;
    $amount = $amountKobo / 100;

    addInvoicePayment($invoiceId, $reference, $amount, 0, $gatewayModuleName);

    inpaycheckout_log(
        $logEnabled,
        $gatewayModuleName,
        'Payment captured for invoice ' . $invoiceId . ' (' . $reference . ') amount ₦' . number_format($amount, 2),
        'Successful'
    );
}

// Handle AJAX verification coming from the invoice page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_VERIFY_PAYMENT'])) {
    header('Content-Type: application/json');

    $payload = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $reference = trim((string) ($payload['reference'] ?? ''));
    $invoiceId = (int) ($payload['invoice_id'] ?? 0);

    if ($reference === '' || $invoiceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid verification request']);
        exit;
    }

    if (inpaycheckout_transactionExists($reference)) {
        inpaycheckout_log(
            $logEnabled,
            $gatewayModuleName,
            'Verification request skipped, transaction already applied: ' . $reference,
            'Information'
        );
        echo json_encode(['success' => true, 'message' => 'Payment already processed']);
        exit;
    }

    $verification = inpaycheckout_verify_transaction($reference, $secretKey);

    if (!empty($verification['success'])) {
        $txData = $verification['data'];
        $status = strtolower((string) ($txData['status'] ?? ''));
        $verified = $txData['verified'] ?? false;
        $isVerified = $verified === true || $verified === 'true' || $verified === 1 || $verified === '1';

        if ($status === 'completed' && $isVerified) {
            inpaycheckout_process_successful_payment($invoiceId, $reference, $txData, $gatewayModuleName, $logEnabled);
            echo json_encode(['success' => true, 'message' => 'Payment verified and applied']);
            exit;
        }

        inpaycheckout_log(
            $logEnabled,
            $gatewayModuleName,
            'Verification response received but not completed: ' . json_encode($txData),
            'Information'
        );
    } else {
        inpaycheckout_log(
            $logEnabled,
            $gatewayModuleName,
            'Verification failed for reference ' . $reference . ': ' . ($verification['error'] ?? 'Unknown error'),
            'Error'
        );
    }

    echo json_encode(['success' => false, 'message' => $verification['error'] ?? 'Payment not completed yet']);
    exit;
}

// Handle webhook notifications from iNPAY
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = (string) file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
    $eventName = $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '';

    if (!inpaycheckout_validate_webhook_timestamp($timestamp)) {
        inpaycheckout_log($logEnabled, $gatewayModuleName, 'Invalid webhook timestamp: ' . $timestamp, 'Error');
        http_response_code(400);
        exit('Invalid timestamp');
    }

    if (!inpaycheckout_verify_webhook_signature($payload, $signature, $secretKey)) {
        inpaycheckout_log($logEnabled, $gatewayModuleName, 'Invalid webhook signature received.', 'Error');
        http_response_code(401);
        exit('Invalid signature');
    }

    $event = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($event['event'])) {
        http_response_code(400);
        exit('Invalid event payload');
    }

    inpaycheckout_log(
        $logEnabled,
        $gatewayModuleName,
        'Webhook received: ' . $event['event'] . ' (Header: ' . $eventName . ')',
        'Information'
    );

    $completionEvents = [
        'payment.virtual_payid.completed',
        'payment.checkout_payid.completed',
        'payment.virtual_account.completed',
        'payment.checkout_virtual_account.completed',
    ];

    if (in_array($event['event'], $completionEvents, true)) {
        $transactionData = $event['data'] ?? [];
        if (is_object($transactionData)) {
            $transactionData = (array) $transactionData;
        }

        $metadata = $transactionData['metadata'] ?? [];
        if (is_string($metadata)) {
            $decodedMetadata = json_decode($metadata, true);
            $metadata = json_last_error() === JSON_ERROR_NONE ? $decodedMetadata : [];
        } elseif (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        $invoiceId = (int) ($metadata['invoice_id'] ?? 0);
        $whmcsReference = (string) ($metadata['reference'] ?? '');
        $inpayReference = (string) ($transactionData['reference'] ?? '');

        if ($invoiceId <= 0 || $whmcsReference === '') {
            inpaycheckout_log(
                $logEnabled,
                $gatewayModuleName,
                'Webhook missing invoice ID or reference: ' . json_encode($metadata),
                'Error'
            );
        } elseif (!inpaycheckout_transactionExists($whmcsReference)) {
            $verification = inpaycheckout_verify_transaction($inpayReference ?: $whmcsReference, $secretKey);

            if (!empty($verification['success'])) {
                inpaycheckout_process_successful_payment($invoiceId, $whmcsReference, $verification['data'], $gatewayModuleName, $logEnabled);
            } else {
                inpaycheckout_log(
                    $logEnabled,
                    $gatewayModuleName,
                    'Webhook verification failed for reference ' . $whmcsReference . ': ' . ($verification['error'] ?? 'Unknown error'),
                    'Error'
                );
            }
        } else {
            inpaycheckout_log(
                $logEnabled,
                $gatewayModuleName,
                'Webhook skipped duplicate transaction ' . $whmcsReference,
                'Information'
            );
        }
    } else {
        inpaycheckout_log(
            $logEnabled,
            $gatewayModuleName,
            'Unhandled webhook event type: ' . $event['event'],
            'Information'
        );
    }

    http_response_code(200);
    exit('OK');
}

// For GET requests simply acknowledge the callback.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    exit('OK');
}

http_response_code(405);
exit('Method Not Allowed');
