# iNPAY Checkout Payment Gateway for WHMCS

A secure, feature-rich payment gateway module for WHMCS that integrates with iNPAY Checkout's payment system, supporting payID and bank transfers.

## Features

- ✅ **Inline Checkout**: Seamless payment experience with iNPAY Checkout's modal interface
- ✅ **Multiple Payment Methods**: Support for payID and bank transfers
- ✅ **NGN Support**: Nigerian Naira (NGN) payments
- ✅ **Webhook Security**: HMAC SHA-256 signature verification
- ✅ **Production Ready**: Live mode support for production use
- ✅ **Comprehensive Logging**: Detailed transaction logs for debugging
- ✅ **Error Handling**: Robust error handling with user-friendly messages
- ✅ **Mobile Responsive**: Works perfectly on all devices
- ✅ **Security First**: Industry-standard security practices

## Requirements

- WHMCS 7.0 or higher
- PHP 7.4 or higher
- iNPAY Checkout account with KYC Tier 2 or higher
- WHMCS installation with NGN currency support
- Valid SSL certificate (required for production)

## Installation

### 1. Download and Extract

```bash
# Clone the repository
git clone https://github.com/IntechNG/inpay-for-whmcs.git

# Or download the ZIP file and extract it
```

### 2. Upload Files

**Important**: Upload the files to the correct locations in your WHMCS installation:

1. **Upload `inpaycheckout.php`** to `/modules/gateways/`
2. **Upload `callback/inpaycheckout.php`** to `/modules/gateways/callback/`

**⚠️ Do NOT upload the entire project folder!** Upload individual files to their correct locations.

**Final Structure Should Look Like:**
```
your-whmcs-root/
├── modules/
│   └── gateways/
│       ├── inpaycheckout.php          ← Upload here
│       ├── callback/
│       │   └── inpaycheckout.php      ← Upload here
│       ├── paypal.php                 ← Other gateways
│       └── stripe.php
```

### 3. Activate Gateway

1. Log in to your WHMCS admin panel
2. Navigate to **Setup → Payments → Payment Gateways**
3. Find **iNPAY Checkout (PayID & Bank Transfer)** in the list
4. Click **Activate**
5. Click **Configure** to set up your API keys

## Configuration

### 1. Get Your API Keys

1. Log in to your [iNPAY Checkout Dashboard](https://dashboard.inpaycheckout.com)
2. Navigate to **Settings → Webhooks**
3. Copy your **Public Key** and **Secret Key**

### 2. Configure in WHMCS

In the iNPAY Checkout gateway configuration:

#### Required Settings:
- **Public Key**: Your public key from iNPAY Checkout Dashboard
- **Secret Key**: Your secret key from iNPAY Checkout Dashboard

#### Optional Settings:
- **Gateway Logs**: Enable for debugging (recommended during setup)
- **Show on Order Form**: Controls visibility on checkout page

### 3. Set Up Webhook

1. Copy the **Webhook URL** from the WHMCS configuration
2. In your iNPAY Checkout Dashboard, go to **Settings → Webhooks**
3. Paste the webhook URL in the **Webhook URL** field
4. Select these events to receive:
   - `payment.virtual_payid.completed`
   - `payment.checkout_payid.completed`
   - `payment.virtual_account.completed`
   - `payment.checkout_virtual_account.completed`
   - `payment.virtual_payid.failed`
   - `payment.checkout_payid.failed`
   - `payment.virtual_account.failed`
   - `payment.checkout_virtual_account.failed`

## Supported Currencies

| Currency | Code | Status |
|----------|------|--------|
| Nigerian Naira | NGN | ✅ Supported |

**Note**: Currently only NGN (Nigerian Naira) is supported. Additional currencies will be added in future updates.



## Security Features

### Webhook Verification

All webhook requests are verified using HMAC SHA-256 signatures (format: `sha256=<hash>`) to ensure authenticity. The gateway also validates webhook timestamps to prevent replay attacks.

### Input Sanitization

All user inputs are properly sanitized and validated before processing.

### SSL Requirements

- Production environments require valid SSL certificates
- All API communications use HTTPS
- Webhook URLs must use HTTPS in production

### Error Handling

- Comprehensive error logging
- User-friendly error messages
- Graceful fallback mechanisms

## Troubleshooting

### Common Issues

#### 1. Gateway Not Appearing
- Ensure files are uploaded to correct directories
- Check file permissions (should be 644)
- Verify WHMCS version compatibility

#### 2. Payment Modal Not Loading
- Check browser console for JavaScript errors
- Verify public key is correct
- Ensure HTTPS is enabled

#### 3. Webhook Not Working
- Verify webhook URL is accessible
- Check signature verification
- Review gateway logs for errors

#### 4. Currency Not Supported
- Check if your currency is in the supported list
- Contact support for additional currency requests

### Debug Mode

Enable **Gateway Logs** in the configuration to see detailed transaction information:

1. Go to **Utilities → Logs → Gateway Log**
2. Filter by **iNPAY** to see relevant entries
3. Review logs for error details

### Getting Help

1. **Check Gateway Logs**: Enable logging and review error messages
2. **Test with Small Amounts**: Use small test amounts to isolate issues
3. **Contact Support**: 
   - WHMCS Support: [support.whmcs.com](https://support.whmcs.com)
   - iNPAY Checkout Support: [support@inpaycheckout.com](mailto:support@inpaycheckout.com)

## API Reference

### Transaction Verification

The gateway verifies transactions using the iNPAY Checkout API:

```
GET https://api.inpaycheckout.com/api/v1/developer/transaction/status?reference={reference}
Authorization: Bearer {secret_key}
```

**Response Format:**
```json
{
  "success": true,
  "message": "Transaction status retrieved successfully",
  "data": {
    "reference": "unique-ref-123",
    "type": "virtual_payid",
    "payId": "mydyn456.dpid",
    "status": "completed",
    "verified": true,
    "customerEmail": "customer@example.com",
    "customerName": "Customer",
    "createdAt": "2025-09-14T10:00:00.000Z",
    "updatedAt": "2025-09-14T10:15:00.000Z"
  }
}
```

The gateway checks for `status: "completed"` to determine successful payments.

### Webhook Events

The gateway handles these iNPAY Checkout webhook events:

**Payment Completed Events:**
- `payment.virtual_payid.completed` - Virtual PayID payment completed
- `payment.checkout_payid.completed` - Checkout PayID payment completed
- `payment.virtual_account.completed` - Virtual account payment completed
- `payment.checkout_virtual_account.completed` - Checkout virtual account payment completed

**Payment Failed Events:**
- `payment.virtual_payid.failed` - Virtual PayID payment failed
- `payment.checkout_payid.failed` - Checkout PayID payment failed
- `payment.virtual_account.failed` - Virtual account payment failed
- `payment.checkout_virtual_account.failed` - Checkout virtual account payment failed

### Transaction Reference Format

Transaction references follow this format:
```
{invoice_id}_{timestamp}_{random_string}
```

Example: `123_1640995200_a1b2c3d4`

## Contributing

We welcome contributions! Please follow these guidelines:

### Development Setup

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

### Code Standards

- Follow PSR-12 coding standards
- Add comments for complex logic
- Include error handling
- Write tests for new features

### Pull Request Process

1. Ensure all tests pass
2. Update documentation if needed
3. Add changelog entry
4. Request review from maintainers

## Changelog

### Version 1.0.0
- Initial release
- Support for PayID and bank transfers
- NGN currency support
- Webhook security
- Comprehensive logging
- Mobile responsive design

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

- **Documentation**: [GitHub Wiki](https://github.com/IntechNG/inpay-for-whmcs/wiki)
- **Issues**: [GitHub Issues](https://github.com/IntechNG/inpay-for-whmcs/issues)
- **Email**: [support@inpaycheckout.com](mailto:support@inpaycheckout.com)
- **Website**: [inpaycheckout.com](https://inpaycheckout.com)

## Acknowledgments

- Built following WHMCS best practices
- Inspired by the Paystack WHMCS integration
- Community feedback and contributions
