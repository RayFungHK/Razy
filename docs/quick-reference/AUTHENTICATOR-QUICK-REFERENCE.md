# Authenticator Quick Reference

Quick lookup for the Razy TOTP/HOTP two-factor authentication class.

---

## Import

```php
use Razy\Authenticator;
```

---

## Secret Generation

| Method | Return | Description |
|--------|--------|-------------|
| `Authenticator::generateSecret($length = 20)` | `string` | Generate Base32-encoded secret (min 16 bytes) |
| `Authenticator::generateBackupCodes($count = 8, $length = 8)` | `array` | Generate single-use recovery codes |

---

## TOTP (Time-Based)

| Method | Return | Description |
|--------|--------|-------------|
| `Authenticator::getCode($secret, $digits?, $period?, $algo?, $timestamp?)` | `string` | Generate TOTP code |
| `Authenticator::verifyCode($secret, $code, $digits?, $period?, $algo?, $window?, $timestamp?)` | `bool` | Verify TOTP code with clock drift |

### Defaults

| Parameter | Default | Valid Range |
|-----------|---------|-------------|
| `$digits` | 6 | 6–8 |
| `$period` | 30 | ≥ 1 second |
| `$algorithm` | `'sha1'` | `sha1`, `sha256`, `sha512` |
| `$window` | 1 | ≥ 0 (periods checked: ±window) |

---

## HOTP (Counter-Based)

| Method | Return | Description |
|--------|--------|-------------|
| `Authenticator::getHotpCode($secret, $counter, $digits?, $algo?)` | `string` | Generate HOTP code |
| `Authenticator::verifyHotpCode($secret, $code, $counter, $digits?, $algo?, $window?)` | `int\|false` | Verify HOTP; returns matched counter or `false` |

### HOTP Defaults

| Parameter | Default |
|-----------|---------|
| `$digits` | 6 |
| `$algorithm` | `'sha1'` |
| `$window` | 5 (look-ahead) |

---

## Provisioning URIs

| Method | Return | Description |
|--------|--------|-------------|
| `Authenticator::getProvisioningUri($secret, $account, $issuer, ...)` | `string` | `otpauth://totp/...` URI |
| `Authenticator::getHotpProvisioningUri($secret, $account, $issuer, $counter, ...)` | `string` | `otpauth://hotp/...` URI |
| `Authenticator::getQrCodeDataUri($uri, $size?, $fg?, $bg?)` | `string` | Google Chart API QR URL |

---

## Base32 Encoding

| Method | Return | Description |
|--------|--------|-------------|
| `Authenticator::base32Encode($data)` | `string` | Binary → Base32 (RFC 4648, no padding) |
| `Authenticator::base32Decode($encoded)` | `string` | Base32 → binary (case-insensitive) |

---

## Quick Setup

```php
// Setup
$secret = Authenticator::generateSecret();
$uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');
$qr = Authenticator::getQrCodeDataUri($uri);
$backups = Authenticator::generateBackupCodes();

// Verify
$valid = Authenticator::verifyCode($secret, $_POST['code']);
```

---

## Error Conditions

| Error | Cause |
|-------|-------|
| `InvalidArgumentException: at least 16 bytes` | `generateSecret()` with length < 16 |
| `InvalidArgumentException: Digits must be between 6 and 8` | Digits outside 6–8 range |
| `InvalidArgumentException: Period must be at least 1 second` | Period ≤ 0 |
| `InvalidArgumentException: Unsupported algorithm` | Algorithm not in `sha1`, `sha256`, `sha512` |
| `InvalidArgumentException: Invalid Base32 character` | `base32Decode()` with invalid input |

---

## Algorithm Compatibility

| Algorithm | Google Auth | Microsoft Auth | Authy | 1Password |
|-----------|:-----------:|:--------------:|:-----:|:---------:|
| `sha1` | ✅ | ✅ | ✅ | ✅ |
| `sha256` | ✅ | ✅ | ✅ | ✅ |
| `sha512` | ❌ | ✅ | ✅ | ✅ |
