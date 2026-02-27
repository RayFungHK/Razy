# Razy\Authenticator

## Summary
- Static utility class for TOTP/HOTP two-factor authentication (2FA).
- Implements RFC 6238 (TOTP) and RFC 4226 (HOTP) standards.
- Compatible with Google Authenticator, Microsoft Authenticator, Authy, 1Password.
- Zero external dependencies — pure PHP using `hash_hmac`, `random_bytes`.
- Timing-safe code verification using `hash_equals()`.

## Key Methods

### Secret Generation

| Method | Return | Description |
|--------|--------|-------------|
| `generateSecret(int $length = 20)` | `string` | Generate cryptographically secure Base32 secret (min 16 bytes) |
| `generateBackupCodes(int $count = 8, int $length = 8)` | `array<string>` | Generate single-use alphanumeric recovery codes |

### TOTP (Time-Based One-Time Password)

| Method | Return | Description |
|--------|--------|-------------|
| `getCode(string $secret, int $digits = 6, int $period = 30, string $algorithm = 'sha1', ?int $timestamp = null)` | `string` | Generate TOTP code for current or specified time |
| `verifyCode(string $secret, string $code, int $digits = 6, int $period = 30, string $algorithm = 'sha1', int $window = 1, ?int $timestamp = null)` | `bool` | Verify TOTP code with clock drift tolerance |

### HOTP (HMAC-Based One-Time Password)

| Method | Return | Description |
|--------|--------|-------------|
| `getHotpCode(string $secret, int $counter, int $digits = 6, string $algorithm = 'sha1')` | `string` | Generate HOTP code for a counter value |
| `verifyHotpCode(string $secret, string $code, int $counter, int $digits = 6, string $algorithm = 'sha1', int $window = 5)` | `int\|false` | Verify HOTP code with look-ahead; returns matched counter or false |

### Provisioning

| Method | Return | Description |
|--------|--------|-------------|
| `getProvisioningUri(string $secret, string $account, string $issuer, int $digits = 6, int $period = 30, string $algorithm = 'sha1')` | `string` | Generate `otpauth://totp/` URI for authenticator app setup |
| `getHotpProvisioningUri(string $secret, string $account, string $issuer, int $counter = 0, int $digits = 6, string $algorithm = 'sha1')` | `string` | Generate `otpauth://hotp/` URI for counter-based setup |
| `getQrCodeDataUri(string $uri, int $size = 200, string $fgColor = '#000000', string $bgColor = '#ffffff')` | `string` | Generate Google Chart API URL for QR code image |

### Base32 Encoding

| Method | Return | Description |
|--------|--------|-------------|
| `base32Encode(string $data)` | `string` | Encode binary data to Base32 (RFC 4648, no padding) |
| `base32Decode(string $encoded)` | `string` | Decode Base32 string to binary (case-insensitive, strips padding) |

## Usage Example

```php
use Razy\Authenticator;

// Generate a secret and provisioning URI
$secret = Authenticator::generateSecret();
$uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');
$qrUrl = Authenticator::getQrCodeDataUri($uri);

// Display QR code
echo '<img src="' . htmlspecialchars($qrUrl) . '">';

// Verify user's code
$valid = Authenticator::verifyCode($secret, $_POST['totp_code']);

// Generate backup codes
$backups = Authenticator::generateBackupCodes();
```

## Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `DEFAULT_DIGITS` | `6` | Default OTP code length |
| `DEFAULT_PERIOD` | `30` | Default TOTP period in seconds |
| `DEFAULT_WINDOW` | `1` | Default clock drift tolerance |
| `DEFAULT_ALGORITHM` | `'sha1'` | Default hash algorithm |
| `DEFAULT_SECRET_LENGTH` | `20` | Default secret length in bytes |
| `SUPPORTED_ALGORITHMS` | `['sha1', 'sha256', 'sha512']` | Allowed hash algorithms |

## Usage Notes
- The `generateSecret()` method requires a minimum of 16 bytes (128 bits) per NIST guidelines
- TOTP `verifyCode()` checks the current period ± `$window` periods for clock drift
- HOTP `verifyHotpCode()` returns the matched counter value; store `$result + 1` as the new counter
- All code comparisons use `hash_equals()` to prevent timing attacks
- The `getQrCodeDataUri()` method returns a Google Chart API URL; for self-hosted QR generation, use a PHP QR library (e.g., `chillerlan/php-qrcode`) with the URI from `getProvisioningUri()`
- Base32 decoding is case-insensitive and automatically strips padding characters
