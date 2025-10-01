<?php
declare(strict_types=1);

/**
 * iNPAY Checkout Payment Gateway Module for WHMCS.
 *
 * Production-ready module with strong validation, logging and documentation.
 */

if (!defined('WHMCS')) {
    exit('This file cannot be accessed directly');
}

/**
 * Resolve the public system URL that WHMCS is reachable on.
 */
function inpaycheckout_get_system_url(): string
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $url = '';

    if (class_exists('\\WHMCS\\Config\\Setting')) {
        try {
            $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
        } catch (\Throwable $throwable) {
            // Fall back to legacy helpers below.
        }
    }

    if ($url === '' && function_exists('config')) {
        $url = (string) config('SystemURL');
    }

    if ($url === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scheme = $https ? 'https://' : 'http://';

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = trim(str_replace('\\', '/', dirname($script)), '/');

        $url = $scheme . $host . ($dir !== '' ? '/' . $dir : '');
    }

    $cached = rtrim($url, '/');

    return $cached;
}

/**
 * Build the callback URL with optional query parameters.
 */
function inpaycheckout_build_callback_url(array $query = []): string
{
    $url = inpaycheckout_get_system_url() . '/modules/gateways/callback/inpaycheckout.php';

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/**
 * Define iNPAY Checkout gateway configuration.
 */
function inpaycheckout_config(): array
{
    $callbackUrl = inpaycheckout_build_callback_url();

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'iNPAY Checkout (PayID & Bank Transfer)',
        ],
        'webhookUrl' => [
            'FriendlyName' => 'Webhook URL',
            'Type' => 'text',
            'Description' => 'Copy this URL into your iNPAY Dashboard → Settings → Webhooks.',
            'Default' => $callbackUrl,
            'ReadOnly' => true,
        ],
        'gatewayLogs' => [
            'FriendlyName' => 'Enable Gateway Logs',
            'Type' => 'yesno',
            'Description' => 'Turn on detailed logging while testing or troubleshooting.',
            'Default' => '0',
        ],
        'secretKey' => [
            'FriendlyName' => 'Secret Key',
            'Type' => 'password',
            'Size' => '64',
            'Description' => 'Secret key from your iNPAY Checkout dashboard.',
            'Default' => '',
        ],
        'publicKey' => [
            'FriendlyName' => 'Public Key',
            'Type' => 'text',
            'Size' => '64',
            'Description' => 'Public key from your iNPAY Checkout dashboard.',
            'Default' => '',
        ],
    ];
}

/**
 * Normalise invoice amount strings into numeric floating point values.
 */
function inpaycheckout_normalize_amount($rawAmount): float
{
    $value = str_replace(' ', '', (string) $rawAmount);

    $sanitised = filter_var(
        $value,
        FILTER_SANITIZE_NUMBER_FLOAT,
        FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND
    );

    if ($sanitised === '' || $sanitised === false) {
        return 0.0;
    }

    $hasComma = strpos($sanitised, ',') !== false;
    $hasDot = strpos($sanitised, '.') !== false;

    if ($hasComma && !$hasDot) {
        $sanitised = str_replace(',', '.', $sanitised);
    } else {
        $sanitised = str_replace(',', '', $sanitised);
    }

    return (float) $sanitised;
}

/**
 * Render the payment button and embed the iNPAY Checkout integration.
 */
function inpaycheckout_link(array $params): string
{
    $gatewayModuleName = basename(__FILE__, '.php');
    $shouldLog = isset($params['gatewayLogs']) && $params['gatewayLogs'] === 'on';

    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $currencyCode = strtoupper((string) ($params['currency'] ?? ''));

    if ($currencyCode !== 'NGN') {
        return '<div class="alert alert-danger">iNPAY Checkout only supports invoices denominated in NGN.</div>';
    }

    $amountNormalised = inpaycheckout_normalize_amount($params['amount'] ?? 0);
    $amountInKobo = (int) round($amountNormalised * 100);

    if ($shouldLog) {
        logTransaction(
            $gatewayModuleName,
            sprintf(
                'Preparing payment: invoice=%d, currency=%s, amount_raw="%s", amount_normalised=%.2f, amount_kobo=%d',
                $invoiceId,
                $currencyCode,
                (string) ($params['amount'] ?? ''),
                $amountNormalised,
                $amountInKobo
            ),
            'Information'
        );
    }

    if ($amountInKobo <= 0) {
        if ($shouldLog) {
            logTransaction(
                $gatewayModuleName,
                'Aborting payment render due to invalid amount. Raw amount: ' . (string) ($params['amount'] ?? ''),
                'Error'
            );
        }

        return '<div class="alert alert-danger">Invalid invoice amount detected. Please contact support.</div>';
    }

    $publicKey = trim((string) ($params['publicKey'] ?? ''));
    if ($publicKey === '') {
        if ($shouldLog) {
            logTransaction($gatewayModuleName, 'Public key not configured; payment button suppressed.', 'Error');
        }

        return '<div class="alert alert-danger">Payment gateway misconfiguration detected. Please contact support.</div>';
    }

    $txnRef = $invoiceId . '_' . time() . '_' . substr(md5(uniqid((string) $invoiceId, true)), 0, 8);

    $systemUrl = rtrim((string) ($params['systemurl'] ?? ''), '/');
    if ($systemUrl === '') {
        $systemUrl = inpaycheckout_get_system_url();
    }

    $callbackBase = $systemUrl . '/modules/gateways/callback/inpaycheckout.php';
    $callbackUrl = $callbackBase . '?' . http_build_query([
        'invoiceid' => $invoiceId,
        'reference' => $txnRef,
    ]);

    $customer = [
        'email' => trim((string) ($params['clientdetails']['email'] ?? '')),
        'firstName' => trim((string) ($params['clientdetails']['firstname'] ?? '')),
        'lastName' => trim((string) ($params['clientdetails']['lastname'] ?? '')),
        'phone' => trim((string) ($params['clientdetails']['phonenumber'] ?? '')),
    ];

    $metadata = [
        'invoice_id' => $invoiceId,
        'gateway' => 'whmcs',
        'reference' => $txnRef,
        'phone' => $customer['phone'],
        'callback_url' => $callbackUrl,
    ];

    $buttonId = 'inpay-pay-btn-' . $invoiceId;
    $jsConfig = [
        'buttonId' => $buttonId,
        'publicKey' => $publicKey,
        'amountInKobo' => $amountInKobo,
        'reference' => $txnRef,
        'invoiceId' => $invoiceId,
        'verifyUrl' => $callbackBase,
        'maxVerificationAttempts' => 6,
        'customer' => $customer,
        'metadata' => $metadata,
    ];

    if ($shouldLog) {
        logTransaction(
            $gatewayModuleName,
            'Checkout payload: ' . json_encode(array_merge($metadata, ['amount_kobo' => $amountInKobo])),
            'Information'
        );
    }

    ob_start();
    ?>
<div class="inpay-payment-container">
    <button type="button" id="<?php echo htmlspecialchars($buttonId, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary btn-block">
        Pay Now
    </button>
</div>
<script>
(function() {
    var config = <?php echo json_encode($jsConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function initialiseIntegration() {
        if (typeof Promise === 'undefined') {
            var unsupportedButton = document.getElementById(config.buttonId);
            if (unsupportedButton) {
                unsupportedButton.disabled = true;
                unsupportedButton.textContent = 'Browser not supported';
            }
            console.error('Promise API unavailable; cannot initialise iNPAY Checkout.');
            return;
        }

        var button = document.getElementById(config.buttonId);
        if (!button) {
            return;
        }

        var sdkUrl = "https://js.inpaycheckout.com/v1/inline.js";
        var sdkPromise = null;

        function setButtonState(disabled, text) {
            button.disabled = !!disabled;
            if (text) {
                button.textContent = text;
            }
        }

        function loadSdk() {
            if (window.iNPAY && typeof window.iNPAY.InpayCheckout === "function") {
                return Promise.resolve(window.iNPAY.InpayCheckout);
            }

            if (!sdkPromise) {
                sdkPromise = new Promise(function(resolve, reject) {
                    var script = document.createElement("script");
                    script.src = sdkUrl;
                    script.async = true;
                    script.onload = function() {
                        if (window.iNPAY && typeof window.iNPAY.InpayCheckout === "function") {
                            resolve(window.iNPAY.InpayCheckout);
                        } else {
                            reject(new Error('iNPAY Checkout script loaded but constructor missing.'));
                        }
                    };
                    script.onerror = function() {
                        reject(new Error('Unable to load iNPAY Checkout script.'));
                    };
                    document.head.appendChild(script);
                });
            }

            return sdkPromise;
        }

        function verifyPayment(attempt) {
            attempt = attempt || 1;

            if (attempt > config.maxVerificationAttempts) {
                window.location.reload();
                return;
            }

            setButtonState(true, 'Verifying payment... (' + attempt + '/' + config.maxVerificationAttempts + ')');

            var payload = JSON.stringify({
                reference: config.reference,
                invoice_id: config.invoiceId
            });

            var fetchOptions = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Verify-Payment': 'true'
                },
                body: payload
            };

            var request = window.fetch ? window.fetch(config.verifyUrl, fetchOptions) : new Promise(function(resolve, reject) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', config.verifyUrl, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-Verify-Payment', 'true');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            resolve({
                                json: function() {
                                    try {
                                        return Promise.resolve(JSON.parse(xhr.responseText || '{}'));
                                    } catch (error) {
                                        return Promise.resolve({ success: false });
                                    }
                                }
                            });
                        } else {
                            reject(new Error('HTTP ' + xhr.status));
                        }
                    }
                };
                xhr.onerror = function() { reject(new Error('Network error')); };
                xhr.send(payload);
            });

            request
                .then(function(response) { return response.json(); })
                .then(function(result) {
                    if (result && result.success) {
                        window.location.reload();
                    } else {
                        setTimeout(function() { verifyPayment(attempt + 1); }, 2000);
                    }
                })
                .catch(function() {
                    setTimeout(function() { verifyPayment(attempt + 1); }, 3000);
                });
        }

        button.addEventListener('click', function() {
            if (!config.amountInKobo || config.amountInKobo <= 0) {
                alert('Invalid payment amount. Please contact support.');
                return;
            }

            setButtonState(true, 'Loading...');

            loadSdk()
                .then(function(Checkout) {
                    var checkout = new Checkout();
                    checkout.checkout({
                        apiKey: config.publicKey,
                        amount: config.amountInKobo,
                        email: config.customer.email,
                        firstName: config.customer.firstName,
                        lastName: config.customer.lastName,
                        metadata: JSON.stringify(config.metadata),
                        onSuccess: function(reference) {
                            if (reference) {
                                if (typeof reference === 'object' && reference.reference) {
                                    config.reference = reference.reference;
                                } else {
                                    config.reference = reference;
                                }
                            }
                            verifyPayment(1);
                        },
                        onFailure: function(error) {
                            setButtonState(false, 'Pay Now');
                            alert('Payment failed: ' + (error && error.message ? error.message : 'Unknown error'));
                        },
                        onExpired: function() {
                            setButtonState(false, 'Pay Now');
                            alert('Payment session expired. Please try again.');
                        },
                        onClose: function() {
                            setButtonState(false, 'Pay Now');
                        },
                        onError: function(error) {
                            setButtonState(false, 'Pay Now');
                            alert('Payment error: ' + (error && error.message ? error.message : 'Unknown error'));
                        }
                    });
                })
                .catch(function(error) {
                    setButtonState(false, 'Pay Now');
                    alert(error && error.message ? error.message : 'Unable to initialise payment.');
                });
        });
    }

    if (typeof Promise === 'undefined') {
        var polyfill = document.createElement('script');
        polyfill.src = 'https://cdn.jsdelivr.net/npm/promise-polyfill@8/dist/polyfill.min.js';
        polyfill.onload = initialiseIntegration;
        polyfill.onerror = initialiseIntegration;
        document.head.appendChild(polyfill);
    } else {
        initialiseIntegration();
    }
})();
</script>
<?php
    return trim(ob_get_clean());
}
