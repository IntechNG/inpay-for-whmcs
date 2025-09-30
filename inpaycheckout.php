<?php
/**
 * ********************************************************************** **\
 *                                                                      *
 *   iNPAY Checkout Payment Gateway Module for WHMCS                   *
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

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Define iNPAY Checkout gateway configuration.
 *
 * @return array Gateway configuration parameters
 */
function inpaycheckout_config()
{
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    $baseUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
    
    // Simple and reliable webhook URL generation
    $callbackUrl = $baseUrl . '/members/modules/gateways/callback/inpaycheckout.php';
    
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'iNPAY Checkout (PayID & Bank Transfer)'
        ),
        'webhook' => array(
            'FriendlyName' => 'Webhook URL',
            'Type' => 'yesno',
            'Description' => 'Copy this URL to your iNPAY Dashboard → Settings → Webhooks: <code>' . $callbackUrl . '</code>',
            'Default' => "'" . $callbackUrl . "'",
        ),
        'gatewayLogs' => array(
            'FriendlyName' => 'Gateway logs',
            'Type' => 'yesno',
            'Description' => 'Tick to enable gateway logs for debugging',
            'Default' => '0'
        ),
        'secretKey' => array(
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '64',
            'Description' => 'Your secret key from iNPAY Checkout Dashboard',
            'Default' => 'sk_live_xxx'
        ),
        'publicKey' => array(
            'FriendlyName' => 'Public Key',
            'Type' => 'text',
            'Size' => '64',
            'Description' => 'Your public key from iNPAY Checkout Dashboard',
            'Default' => 'pk_live_xxx'
        )
    );
}

/**
 * Generate the payment button and integrate iNPAY Checkout modal.
 *
 * @param array $params Gateway Configuration Parameters
 * @return string HTML for the payment button
 */
function inpaycheckout_link($params)
{
    // Get parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currency = $params['currency'];
    $email = $params['clientdetails']['email'];
    $firstName = $params['clientdetails']['firstname'];
    $lastName = $params['clientdetails']['lastname'];
    $phone = $params['clientdetails']['phonenumber'];
    
    // Validate currency
    if ($currency !== 'NGN') {
        return '<div class="alert alert-danger">iNPAY Checkout only supports NGN currency.</div>';
    }
    
    // Generate transaction reference
    $txnRef = $invoiceId . '_' . time() . '_' . substr(md5(uniqid()), 0, 8);
    
    // Convert amount to kobo
    $amountInKobo = intval(floatval($amount) * 100);
    
    // Get API key
    $publicKey = $params['publicKey'];
    
    // Generate callback URL - simple and reliable
    $isSSL = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443);
    $baseUrl = 'http' . ($isSSL ? 's' : '') . '://' . $_SERVER['HTTP_HOST'];
    $callbackUrl = $baseUrl . '/members/modules/gateways/callback/inpaycheckout.php?' . 
        http_build_query(array(
            'invoiceid' => $invoiceId,
            'reference' => $txnRef
        ));

    // Simple payment button with minimal JavaScript
    $code = '<div class="inpay-payment-container">
        <button type="button" id="inpay-pay-btn" style="padding: 12px 25px; border-radius: 6px; background: #2563eb; color: #fff; border: none; font-size: 16px; cursor: pointer; width: 100%; max-width: 300px;">
            Pay Now
        </button>
    </div>
    
    <script>
        document.getElementById("inpay-pay-btn").addEventListener("click", function() {
            var button = this;
            button.disabled = true;
            button.textContent = "Loading...";
            
            // Initialize iNPAY Checkout SDK
            if (!window.iNPAY || typeof window.iNPAY.InpayCheckout === "undefined") {
                var script = document.createElement("script");
                script.src = "https://js.inpaycheckout.com/v1/inline.js";
                script.onload = function() {
                    setTimeout(function() {
                        initializePayment();
                    }, 1000);
                };
                script.onerror = function() {
                    button.disabled = false;
                    button.textContent = "Pay Now";
                    alert("Failed to load payment system. Please try again.");
                };
                document.head.appendChild(script);
            } else {
                initializePayment();
            }
            
            function initializePayment() {
                try {
                    // Verify iNPAY SDK is available and initialize checkout
                    if (window.iNPAY && typeof window.iNPAY.InpayCheckout === "function") {
                        var inpay = new window.iNPAY.InpayCheckout();
                        inpay.checkout({
                        apiKey: ' . json_encode(trim($publicKey)) . ',
                        amount: ' . $amountInKobo . ',
                        currency: ' . json_encode($currency) . ',
                        email: ' . json_encode(trim($email)) . ',
                        firstName: ' . json_encode(trim($firstName)) . ',
                        lastName: ' . json_encode(trim($lastName)) . ',
                        metadata: JSON.stringify({
                            invoice_id: ' . json_encode($invoiceId) . ',
                            gateway: "whmcs",
                            reference: ' . json_encode($txnRef) . ',
                            phone: ' . json_encode(trim($phone)) . '
                        }),
                        onClose: function() {
                            button.disabled = false;
                            button.textContent = "Pay Now";
                            // Simply refresh the page to check payment status
                            setTimeout(function() {
                                window.location.reload();
                            }, 3000);
                        },
                        onSuccess: function(response) {
                            // Payment successful - refresh page
                            window.location.reload();
                        },
                        onError: function(error) {
                            button.disabled = false;
                            button.textContent = "Pay Now";
                            alert("Payment failed: " + (error.message || "Unknown error"));
                        }
                    });
                    } else {
                        throw new Error("iNPAY Checkout script not available");
                    }
                } catch (error) {
                    button.disabled = false;
                    button.textContent = "Pay Now";
                    alert("Payment system error: " + error.message);
                }
            }
        });
    </script>';
    
    return $code;
}
