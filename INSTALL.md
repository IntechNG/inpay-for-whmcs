# Installation Guide - iNPAY Checkout WHMCS Gateway

This guide will walk you through installing and configuring the iNPAY Checkout payment gateway for WHMCS.

## Prerequisites

Before you begin, ensure you have:

- ✅ WHMCS 7.0 or higher installed
- ✅ PHP 7.4 or higher
- ✅ Valid SSL certificate (required for production)
- ✅ iNPAY Checkout account with KYC Tier 2 or higher
- ✅ WHMCS installation with NGN currency support
- ✅ Admin access to your WHMCS installation

## Step 1: Download the Gateway

### Option A: Download ZIP File
1. Go to the [releases page](https://github.com/IntechNG/inpay-for-whmcs/releases)
2. Download the latest release ZIP file
3. Extract the ZIP file to your local computer

### Option B: Clone Repository
```bash
git clone https://github.com/IntechNG/inpay-for-whmcs.git
```

## Step 2: Upload Files

### Using File Manager (cPanel/Plesk)
1. Log in to your hosting control panel
2. Navigate to your WHMCS root directory
3. Go to `modules/gateways/` folder
4. **Upload `inpaycheckout.php`** to this directory
5. **Navigate to or create `callback` folder** inside `gateways/`
6. **Upload `callback/inpaycheckout.php`** to the `callback` folder

### Using FTP/SFTP
1. Connect to your server using FTP/SFTP client
2. Navigate to your WHMCS root directory
3. Go to `modules/gateways/` folder
4. **Upload `inpaycheckout.php`** to this folder
5. **Navigate to or create `callback` folder** inside `gateways/`
6. **Upload `callback/inpaycheckout.php`** to the `callback` folder

### ⚠️ Important Upload Instructions
**Do NOT upload the entire project folder!** Upload the individual files to their correct locations:

- `inpaycheckout.php` → `/modules/gateways/inpaycheckout.php`
- `callback/inpaycheckout.php` → `/modules/gateways/callback/inpaycheckout.php`

### Final Directory Structure
```
your-whmcs-root/
├── modules/
│   └── gateways/
│       ├── inpaycheckout.php          ← Upload here
│       ├── callback/
│       │   └── inpaycheckout.php      ← Upload here
│       ├── paypal.php                 ← Other existing gateways
│       └── stripe.php
```

## Step 3: Set File Permissions

Set the following permissions on the uploaded files:

```bash
# Gateway file
chmod 644 modules/gateways/inpaycheckout.php

# Callback file
chmod 644 modules/gateways/callback/inpaycheckout.php
```

## Step 4: Activate Gateway in WHMCS

1. **Log in to WHMCS Admin Panel**
   - Go to your WHMCS admin URL
   - Log in with admin credentials

2. **Navigate to Payment Gateways**
   - Go to **Setup → Payments → Payment Gateways**
   - Or navigate directly to `/admin/configgateways.php`

3. **Find iNPAY Gateway**
   - Look for **"iNPAY Checkout (PayID & Bank Transfer)"** in the list
   - Click the **Activate** link next to it

4. **Verify Activation**
   - You should see a success message
   - The gateway should now show as "Active"

## Step 5: Configure API Keys

### Get Your iNPAY Checkout API Keys

1. **Log in to iNPAY Checkout Dashboard**
   - Go to [dashboard.inpaycheckout.com](https://dashboard.inpaycheckout.com)
   - Log in with your iNPAY Checkout account credentials

2. **Navigate to Webhooks**
   - Go to **Settings → Webhooks**
   - You'll see your API keys here

3. **Copy Your Keys**
   - Public Key: `pk_live_...`
   - Secret Key: `sk_live_...`

### Configure in WHMCS

1. **Open Gateway Configuration**
   - In WHMCS, click **Configure** next to the iNPAY gateway

2. **Enter Your API Keys**
   - **Public Key**: Paste your public key from iNPAY Checkout Dashboard
   - **Secret Key**: Paste your secret key from iNPAY Checkout Dashboard

3. **Enable Logging** (Recommended)
   - Check **Gateway Logs** for debugging purposes

4. **Save Configuration**
   - Click **Save Changes**

## Step 6: Set Up Webhook

### Copy Webhook URL

1. **Get Webhook URL**
   - In the WHMCS gateway configuration
   - Copy the **Webhook URL** (it should look like):
   ```
   https://yourdomain.com/modules/gateways/callback/inpaycheckout.php
   ```

### Configure in iNPAY Checkout Dashboard

1. **Go to Webhook Settings**
   - In iNPAY Checkout Dashboard, go to **Settings → Webhooks**
   - Scroll down to **Webhook URL** section

2. **Enter Webhook URL**
   - Paste the webhook URL from WHMCS
   - Select these events to receive:
     - ✅ `payment.virtual_payid.completed`
     - ✅ `payment.checkout_payid.completed`
     - ✅ `payment.virtual_account.completed`
     - ✅ `payment.checkout_virtual_account.completed`
     - ✅ `payment.virtual_payid.failed`
     - ✅ `payment.checkout_payid.failed`
     - ✅ `payment.virtual_account.failed`
     - ✅ `payment.checkout_virtual_account.failed`

3. **Save Webhook Settings**
   - Click **Save** to save the webhook configuration

## Step 7: Test the Integration

### Testing Setup

1. **Use Live Mode**
   - Use your live API keys from iNPAY Checkout Dashboard

2. **Create Test Invoice**
   - Create a test invoice in WHMCS
   - Set amount to a small test value (e.g., ₦100)

3. **Test Payment Flow**
   - Go to the invoice view
   - Click **Pay Now**
   - Select iNPAY as payment method
   - Use test PayID numbers from iNPAY Checkout

### Test Scenarios

Test these scenarios to ensure everything works:

- ✅ **Successful Payment**: Use a valid test PayID
- ✅ **Failed Payment**: Use an invalid test PayID
- ✅ **Cancelled Payment**: Start payment then cancel
- ✅ **NGN Currency**: Test with Nigerian Naira
- ✅ **Mobile Devices**: Test on mobile devices

## Step 8: Go Live

### Before Going Live

1. **Complete Business Verification**
   - Ensure your iNPAY Checkout account is fully verified
   - Complete KYC requirements

2. **Test Thoroughly**
   - Test all payment scenarios with small amounts
   - Verify webhook functionality
   - Check error handling

3. **Verify Configuration**
   - Ensure live API keys are entered correctly
   - Verify webhook URL uses HTTPS
   - Test with small amounts first

### Production Checklist

- ✅ SSL certificate installed and working
- ✅ Live API keys configured
- ✅ API keys configured correctly
- ✅ Webhook URL configured in iNPAY Dashboard
- ✅ Gateway logs enabled for monitoring
- ✅ NGN currency tested
- ✅ Mobile compatibility verified

## Troubleshooting

### Common Installation Issues

#### Gateway Not Appearing
- **Check file location**: Ensure files are in correct directories
- **Check permissions**: Files should be readable (644)
- **Check WHMCS version**: Ensure compatibility with your WHMCS version

#### Configuration Errors
- **API Keys**: Verify keys are copied correctly (no extra spaces)
- **Test Mode**: Ensure test mode matches your key type
- **Webhook URL**: Verify URL is accessible from internet

#### Payment Issues
- **Check logs**: Enable gateway logs and review errors
- **Test mode**: Use test mode to isolate issues
- **Browser console**: Check for JavaScript errors

### Getting Help

1. **Check Gateway Logs**
   - Go to **Utilities → Logs → Gateway Log**
   - Filter by **inpay** to see relevant entries

2. **Contact Support**
   - **WHMCS Support**: [support.whmcs.com](https://support.whmcs.com)
   - **iNPAY Checkout Support**: [support@inpaycheckout.com](mailto:support@inpaycheckout.com)

3. **Community Support**
   - **GitHub Issues**: [github.com/IntechNG/inpay-for-whmcs/issues](https://github.com/IntechNG/inpay-for-whmcs/issues)

## Security Best Practices

### Production Security

1. **Use HTTPS**: Ensure all communications use SSL
2. **Secure API Keys**: Keep secret keys confidential
3. **Regular Updates**: Keep the gateway updated
4. **Monitor Logs**: Regularly check gateway logs
5. **Backup Configuration**: Backup your gateway configuration

### File Permissions

```bash
# Secure file permissions
chmod 644 modules/gateways/inpaycheckout.php
chmod 644 modules/gateways/callback/inpaycheckout.php
```

## Next Steps

After successful installation:

1. **Monitor Transactions**: Keep an eye on payment processing
2. **Update Regularly**: Check for gateway updates
3. **Backup Configuration**: Save your configuration settings
4. **Train Staff**: Ensure your team knows how to use the gateway

## Support

- **Documentation**: [GitHub Wiki](https://github.com/IntechNG/inpay-for-whmcs/wiki)
- **Email Support**: [support@inpaycheckout.com](mailto:support@inpaycheckout.com)
- **Community**: [GitHub Issues](https://github.com/IntechNG/inpay-for-whmcs/issues)

---

**Congratulations!** You've successfully installed the iNPAY Checkout payment gateway for WHMCS. Your customers can now make secure payments using PayID and bank transfers.
