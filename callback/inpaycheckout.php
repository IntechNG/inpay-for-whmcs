<?php
/**
 * ********************************************************************** **\
 *                                                                      *
 *   iNPAY Checkout Payment Gateway Callback Handler for WHMCS         *
 *   Version: 1.0.0                                                    *
 *   Build Date: ' . date('d M Y') . '                                  *
 *                                                                      *
 * ********************************************************************** **\
 *                                                                      *
 *   Email: support@inpaycheckout.com                                   *
 *   Website: https://inpaycheckout.com                                 *
 *   GitHub: https://github.com/IntechNG/inpay-for-whmcs                *
 *                                                                      *
\ ********************************************************************** **/

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
    http_response_code(404);
    die("Module Not Activated");
}

// Get secret key
$secretKey = $gatewayParams['secretKey'];

// Process incoming requests - supports both webhook (POST) and direct callback (GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Webhook notification from iNPAY Checkout
    $input = @file_get_contents("php://input");
    $webhookData = json_decode($input);
    
    if (!$webhookData || !isset($webhookData->data->reference)) {
        http_response_code(400);
        die("Invalid webhook data");
    }
    
    // Extract transaction details from webhook payload
    $reference = isset($webhookData->data->metadata->reference) ? $webhookData->data->metadata->reference : $webhookData->data->reference;
    $invoiceId = isset($webhookData->data->metadata->invoice_id) ? (int)$webhookData->data->metadata->invoice_id : null;
    
    // Fallback: extract invoice ID from transaction reference format
    if (!$invoiceId) {
        $referenceParts = explode('_', $reference);
        $invoiceId = isset($referenceParts[0]) ? (int)$referenceParts[0] : null;
    }
    
} else {
    // Direct callback from user redirect after payment
    $invoiceId = filter_input(INPUT_GET, "invoiceid", FILTER_SANITIZE_NUMBER_INT);
    $reference = filter_input(INPUT_GET, "reference", FILTER_SANITIZE_STRING);
}

// Validate required parameters
if (!$invoiceId || !$reference) {
    http_response_code(400);
    die("Invalid parameters");
}

/**
 * Verify iNPAY Checkout transaction status
 *
 * @param string $reference Transaction reference
 * @param string $secretKey Secret key for API authentication
 * @return object Transaction status object
 */
function verifyInpayTransaction($reference, $secretKey)
{
    $ch = curl_init();
    $txStatus = new stdClass();
    
    // Try GET method first (more efficient, as confirmed by your API test)
    curl_setopt($ch, CURLOPT_URL, "https://api.inpaycheckout.com/api/v1/developer/transaction/status?reference=" . rawurlencode($reference));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    
    // Set headers exactly as in your working API test
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . trim($secretKey),
        'Accept: application/json'
    ));
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSLVERSION, 6);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    // Execute the GET request
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $txStatus->error = "cURL Error: " . curl_error($ch);
        curl_close($ch);
        return $txStatus;
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // If GET fails, try POST method as fallback
    if ($httpCode !== 200) {
        curl_setopt($ch, CURLOPT_URL, "https://api.inpaycheckout.com/api/v1/developer/transaction/verify");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        
        // Update headers for POST request
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . trim($secretKey),
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        
        // Prepare POST data
        $postData = json_encode(array('reference' => $reference));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        
        // Execute the POST request
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $txStatus->error = "cURL Error (POST): " . curl_error($ch);
            curl_close($ch);
            return $txStatus;
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    
    curl_close($ch);
    
            if ($httpCode !== 200) {
                $txStatus->error = "API Error: HTTP " . $httpCode . " - Reference: " . $reference;
                return $txStatus;
            }
    
    $body = json_decode($response);
    if (!$body) {
        $txStatus->error = "Invalid JSON response from API";
        return $txStatus;
    }
    
    if (!$body->success) {
        $txStatus->error = "API Error: " . ($body->message ?? 'Unknown error');
        return $txStatus;
    }
    
    return $body->data;
}

/**
 * Verify webhook signature according to iNPAY Checkout documentation
 *
 * @param string $payload Raw request body
 * @param string $signature Webhook signature from header (format: sha256=<hash>)
 * @param string $secretKey Secret key for signature verification
 * @return bool True if signature is valid
 */
function verifyWebhookSignature($payload, $signature, $secretKey)
{
    if (empty($signature) || empty($payload)) {
        return false;
    }
    
    try {
        // Step 1: Extract the clean signature (remove 'sha256=' prefix)
        $cleanSignature = preg_replace('/^sha256=/', '', $signature);
        
        // Step 2: Generate expected signature using your secret key
        $expectedSignature = hash_hmac('sha256', $payload, $secretKey);
        
        // Step 3: Compare signatures securely using timing-safe comparison
        return hash_equals($cleanSignature, $expectedSignature);
        
    } catch (Exception $error) {
        error_log('Webhook signature verification error: ' . $error->getMessage());
        return false;
    }
}

/**
 * Validate webhook timestamp to prevent replay attacks
 *
 * @param string $timestamp Timestamp from webhook header
 * @return bool True if timestamp is valid (within 5 minutes)
 */
function validateWebhookTimestamp($timestamp)
{
    if (empty($timestamp)) {
        return false;
    }
    
    $now = time() * 1000; // Convert to milliseconds
    $webhookTime = intval($timestamp);
    
    // Allow 5 minutes tolerance for clock skew
    $tolerance = 5 * 60 * 1000; // 5 minutes in milliseconds
    
    return abs($now - $webhookTime) <= $tolerance;
}

// Process webhook notifications from iNPAY Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = @file_get_contents("php://input");
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
    $event = $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '';
    
    // Security: validate timestamp to prevent replay attacks
    if (!validateWebhookTimestamp($timestamp)) {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            logTransaction($gatewayModuleName, "Invalid webhook timestamp: " . $timestamp, "Unsuccessful");
        }
        http_response_code(400);
        die("Invalid timestamp");
    }
    
    // Security: verify webhook signature authenticity
    if (!verifyWebhookSignature($input, $signature, $secretKey)) {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            logTransaction($gatewayModuleName, "Invalid webhook signature", "Unsuccessful");
        }
        http_response_code(401);
        die("Invalid signature");
    }
    
    $event = json_decode($input);
    if (!$event || !isset($event->event)) {
        http_response_code(400);
        die("Invalid event data");
    }
    
    // Log webhook event
            if ($gatewayParams['gatewayLogs'] == 'on') {
                logTransaction($gatewayModuleName, "Webhook event received: " . $event->event, "Information");
            }
    
    // Process payment completion events
    switch ($event->event) {
        case 'payment.virtual_payid.completed':
        case 'payment.checkout_payid.completed':
        case 'payment.virtual_account.completed':
        case 'payment.checkout_virtual_account.completed':
            $transactionData = $event->data;
            $inpayReference = $transactionData->reference ?? '';
            
                    if ($gatewayParams['gatewayLogs'] == 'on') {
                        logTransaction($gatewayModuleName, "Payment completion webhook received for reference: " . $inpayReference, "Successful");
                    }
            
            // Extract WHMCS transaction details from webhook metadata
            $whmcsReference = $transactionData->metadata->reference ?? null;
            $invoiceId = $transactionData->metadata->invoice_id ?? null;
            
            // Fallback: parse invoice ID from transaction reference format
            if (!$whmcsReference || !$invoiceId) {
                $referenceParts = explode('_', $inpayReference);
                if (count($referenceParts) >= 2) {
                    $invoiceId = (int) $referenceParts[0];
                    $whmcsReference = $inpayReference;
                }
            }
            
            if ($invoiceId && $whmcsReference) {
                // Verify transaction status with iNPAY API for additional security
                $txStatus = verifyInpayTransaction($inpayReference, $secretKey);
                
                if (!$txStatus->error && $txStatus->status === 'completed') {
                    $txStatus->reference = $whmcsReference;
                    
                    // Prevent duplicate payment processing
                    $existingPayment = checkCbTransID($whmcsReference);
                    
                    if (!$existingPayment) {
                        processSuccessfulPayment($invoiceId, $whmcsReference, $txStatus, $gatewayParams, $gatewayModuleName);
                        
                                if ($gatewayParams['gatewayLogs'] == 'on') {
                                    logTransaction($gatewayModuleName, "Payment successfully processed via webhook for reference: " . $whmcsReference, "Successful");
                                }
                    } else {
                        if ($gatewayParams['gatewayLogs'] == 'on') {
                            logTransaction($gatewayModuleName, "Payment already processed for reference: " . $whmcsReference . " (duplicate prevented)", "Information");
                        }
                    }
                        } else {
                            if ($gatewayParams['gatewayLogs'] == 'on') {
                                logTransaction($gatewayModuleName, "Webhook received but API verification failed for reference: " . $inpayReference, "Unsuccessful");
                            }
                        }
            }
            break;
            
        case 'payment.virtual_payid.failed':
        case 'payment.checkout_payid.failed':
        case 'payment.virtual_account.failed':
        case 'payment.checkout_virtual_account.failed':
            $transactionData = $event->data;
            $reference = $transactionData->reference ?? '';
            
                    if ($gatewayParams['gatewayLogs'] == 'on') {
                        logTransaction($gatewayModuleName, "Payment failure webhook received for reference: " . $reference, "Unsuccessful");
                    }
            break;
            
        case 'payment.virtual_payid.cancelled':
        case 'payment.checkout_payid.cancelled':
        case 'payment.virtual_account.cancelled':
        case 'payment.checkout_virtual_account.cancelled':
            $transactionData = $event->data;
            $reference = $transactionData->reference ?? '';
            
                    if ($gatewayParams['gatewayLogs'] == 'on') {
                        logTransaction($gatewayModuleName, "Payment cancellation webhook received for reference: " . $reference, "Unsuccessful");
                    }
            break;
            
        default:
            if ($gatewayParams['gatewayLogs'] == 'on') {
                logTransaction($gatewayModuleName, "Unhandled webhook event: " . $event->event, "Information");
            }
            break;
    }
    
    http_response_code(200);
    die("OK");
}

// Handle direct callback (GET requests)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check if payment was already processed by webhook
    $existingPayment = checkCbTransID($reference);
    
    if ($existingPayment) {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            logTransaction($gatewayModuleName, "Payment already processed via webhook for reference: " . $reference, "Information");
        }
    } else {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            logTransaction($gatewayModuleName, "GET callback received for reference: " . $reference . " (payment processing pending)", "Information");
        }
    }
    
    // Return success response
    http_response_code(200);
    die("OK");
}

/**
 * Process successful payment
 *
 * @param int $invoiceId Invoice ID
 * @param string $reference Transaction reference
 * @param object $txStatus Transaction status object
 * @param array $gatewayParams Gateway configuration
 * @param string $gatewayModuleName Gateway module name
 */
function processSuccessfulPayment($invoiceId, $reference, $txStatus, $gatewayParams, $gatewayModuleName)
{
    try {
        /**
         * Validate Callback Invoice ID.
         */
        $invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
        
        /**
         * Check Callback Transaction ID.
         */
        checkCbTransID($reference);
        
        // Amount is already in kobo from iNPAY Checkout webhook
        $amount = floatval($txStatus->amount ?? 0) / 100;
        
        // Handle currency conversion if needed
        if ($gatewayParams['convertto']) {
            $result = select_query(
                "tblclients", 
                "tblinvoices.invoicenum,tblclients.currency,tblcurrencies.code", 
                array("tblinvoices.id" => $invoiceId), 
                "", "", "", 
                "tblinvoices ON tblinvoices.userid=tblclients.id INNER JOIN tblcurrencies ON tblcurrencies.id=tblclients.currency"
            );
            $data = mysql_fetch_array($result);
            $invoiceCurrencyId = $data['currency'];
            
            $convertToAmount = convertCurrency($amount, $gatewayParams['convertto'], $invoiceCurrencyId);
            $amount = format_as_currency($convertToAmount);
        }
        
        /**
         * Add Invoice Payment.
         */
        addInvoicePayment($invoiceId, $reference, $amount, 0, $gatewayModuleName);
        
        if ($gatewayParams['gatewayLogs'] == 'on') {
            logTransaction($gatewayModuleName, "Payment added successfully for invoice " . $invoiceId, "Successful");
        }
        
    } catch (Exception $e) {
        if ($gatewayParams['gatewayLogs'] == 'on') {
            logTransaction($gatewayModuleName, "Payment processing error: " . $e->getMessage(), "Unsuccessful");
        }
    }
}

// Redirect user back to invoice page
$isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
$invoiceUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] .
    substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/')) .
    '/../../../viewinvoice.php?id=' . rawurlencode($invoiceId);

// Add success/error parameter for user feedback
$redirectUrl = $invoiceUrl . ($success ? '&paymentsuccess=1' : '&paymentfailed=1');

header('Location: ' . $redirectUrl);
die('<meta http-equiv="refresh" content="0;url=' . $redirectUrl . '" />
Redirecting to <a href="' . $redirectUrl . '">invoice page</a>...');
