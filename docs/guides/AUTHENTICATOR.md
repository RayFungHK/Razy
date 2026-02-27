# Authenticator (2FA) Guide

Complete guide to Razy's TOTP/HOTP two-factor authentication — secret generation, code verification, provisioning URIs, backup codes, and integration patterns.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Getting Started](#getting-started)
4. [TOTP (Time-Based)](#totp-time-based)
   - [Generate a Code](#generate-a-code)
   - [Verify a Code](#verify-a-code)
   - [Clock Drift Tolerance](#clock-drift-tolerance)
5. [HOTP (Counter-Based)](#hotp-counter-based)
6. [Provisioning URIs](#provisioning-uris)
7. [QR Code Integration](#qr-code-integration)
8. [Backup Codes](#backup-codes)
9. [Algorithm Options](#algorithm-options)
10. [Integration Patterns](#integration-patterns)
11. [Security Best Practices](#security-best-practices)
12. [Troubleshooting](#troubleshooting)

---

## Overview

The `Authenticator` class provides RFC 6238 (TOTP) and RFC 4226 (HOTP) compliant one-time password generation and verification. It works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, and any other standards-compliant authenticator app.

### Key Features

- **TOTP & HOTP**: Time-based and counter-based one-time passwords
- **RFC Compliant**: Full RFC 6238 / RFC 4226 implementation
- **Zero Dependencies**: Pure PHP — no external libraries required
- **Multiple Algorithms**: SHA-1, SHA-256, SHA-512
- **Clock Drift Tolerance**: Configurable window for time synchronization
- **QR Provisioning**: `otpauth://` URI generation for authenticator app setup
- **Backup Codes**: Generate recovery codes for account access
- **Timing-Safe**: Uses `hash_equals()` to prevent timing attacks

### Standards

| Standard | Description |
|----------|-------------|
| RFC 6238 | TOTP: Time-Based One-Time Password Algorithm |
| RFC 4226 | HOTP: HMAC-Based One-Time Password Algorithm |
| RFC 4648 | Base32 encoding for secret keys |

---

## Architecture

The `Authenticator` is a static utility class (no instantiation needed), following the same pattern as `Crypt`, `YAML`, and `Cache`.

```
Authenticator (Static Class)
    ├── generateSecret(length?)          → Base32 secret
    ├── generateBackupCodes(count?, len?) → recovery codes
    │
    ├── getCode(secret, ...)             → TOTP code
    ├── verifyCode(secret, code, ...)    → bool
    │
    ├── getHotpCode(secret, counter, ...)      → HOTP code
    ├── verifyHotpCode(secret, code, counter)  → int|false
    │
    ├── getProvisioningUri(secret, account, issuer)     → otpauth://totp/...
    ├── getHotpProvisioningUri(secret, account, issuer) → otpauth://hotp/...
    ├── getQrCodeDataUri(uri, size?)                    → QR image URL
    │
    ├── base32Encode(data)   → string
    └── base32Decode(data)   → string
```

---

## Getting Started

### Basic 2FA Setup (TOTP)

```php
use Razy\Authenticator;

// 1. Generate a secret for the user (store this securely)
$secret = Authenticator::generateSecret();

// 2. Create a provisioning URI for the authenticator app
$uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');

// 3. Display as QR code
$qrUrl = Authenticator::getQrCodeDataUri($uri);
echo '<img src="' . htmlspecialchars($qrUrl) . '" alt="Scan with authenticator app">';

// 4. Generate backup codes (store alongside the secret)
$backupCodes = Authenticator::generateBackupCodes();
```

### Verifying a Code at Login

```php
use Razy\Authenticator;

// User submits their 6-digit code from the authenticator app
$userCode = $_POST['totp_code'];
$userSecret = $storedSecretFromDatabase;

if (Authenticator::verifyCode($userSecret, $userCode)) {
    // ✅ 2FA verified — grant access
    grantAccess();
} else {
    // ❌ Invalid code
    showError('Invalid authentication code. Please try again.');
}
```

---

## TOTP (Time-Based)

TOTP generates codes that change every 30 seconds (configurable). This is the most common 2FA method used by Google Authenticator and similar apps.

### Generate a Code

```php
// Current code (uses current time)
$code = Authenticator::getCode($secret);

// Code at a specific timestamp
$code = Authenticator::getCode($secret, 6, 30, 'sha1', 1234567890);

// 8-digit code
$code = Authenticator::getCode($secret, 8);

// 60-second period
$code = Authenticator::getCode($secret, 6, 60);
```

### Verify a Code

```php
// Default verification (window=1, checks ±1 period)
$valid = Authenticator::verifyCode($secret, $userInput);

// Custom window (check ±3 periods for generous tolerance)
$valid = Authenticator::verifyCode($secret, $userInput, 6, 30, 'sha1', 3);

// 8-digit codes with SHA-256
$valid = Authenticator::verifyCode($secret, $userInput, 8, 30, 'sha256');
```

### Clock Drift Tolerance

The `$window` parameter controls how many adjacent time periods are checked. With the default 30-second period:

| Window | Periods Checked | Time Range |
|--------|----------------|------------|
| 0 | Current only | ±0 seconds |
| 1 (default) | Current ± 1 | ±30 seconds |
| 2 | Current ± 2 | ±60 seconds |
| 3 | Current ± 3 | ±90 seconds |

A window of 1 is recommended for most use cases. Increase if users frequently report expired codes.

---

## HOTP (Counter-Based)

HOTP uses an incrementing counter instead of time. The server and client must stay in sync.

```php
// Generate HOTP code at counter = 5
$code = Authenticator::getHotpCode($secret, 5);

// Verify with look-ahead window
$counter = 5; // Last known counter
$result = Authenticator::verifyHotpCode($secret, $userInput, $counter);

if ($result !== false) {
    // $result is the matched counter value
    // Store $result + 1 as the new counter
    $newCounter = $result + 1;
    saveCounter($userId, $newCounter);
}
```

The default HOTP look-ahead window is 5 (checks counters 0 through +5 from the current value). This handles cases where the user generated codes without submitting them.

---

## Provisioning URIs

Provisioning URIs follow the [Key URI Format](https://github.com/google/google-authenticator/wiki/Key-Uri-Format) used by authenticator apps.

### TOTP URI

```php
$uri = Authenticator::getProvisioningUri(
    $secret,          // Base32 secret
    'user@example.com', // Account label
    'MyApp',          // Issuer (your app name)
    6,                // Digits (optional, default: 6)
    30,               // Period (optional, default: 30)
    'sha1'            // Algorithm (optional, default: sha1)
);
// Result: otpauth://totp/MyApp:user%40example.com?secret=...&issuer=MyApp&algorithm=SHA1&digits=6&period=30
```

### HOTP URI

```php
$uri = Authenticator::getHotpProvisioningUri(
    $secret,
    'user@example.com',
    'MyApp',
    0     // Initial counter
);
// Result: otpauth://hotp/MyApp:user%40example.com?secret=...&issuer=MyApp&algorithm=SHA1&digits=6&counter=0
```

---

## QR Code Integration

### Google Chart API (Built-in)

```php
$uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');
$qrUrl = Authenticator::getQrCodeDataUri($uri, 250);

echo '<img src="' . htmlspecialchars($qrUrl) . '" width="250" height="250">';
```

### Manual Entry Fallback

Always provide a manual entry option alongside the QR code:

```php
echo '<p>Can\'t scan? Enter this key manually:</p>';
echo '<code>' . htmlspecialchars($secret) . '</code>';
```

### Using a PHP QR Library

For self-hosted QR code generation (no external API dependency):

```php
// Using chillerlan/php-qrcode
use chillerlan\QRCode\QRCode;

$uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');
$qr = (new QRCode)->render($uri);
echo '<img src="' . $qr . '">';
```

---

## Backup Codes

Backup codes are single-use recovery tokens for when the authenticator device is unavailable.

```php
// Generate 8 backup codes (default)
$codes = Authenticator::generateBackupCodes();
// ['A7KM3X2P', 'B9NQ4R5T', ...]

// Custom: 10 codes, 10 characters each
$codes = Authenticator::generateBackupCodes(10, 10);

// Display to the user (one-time only!)
foreach ($codes as $code) {
    echo $code . "\n";
}
```

### Storage Pattern

```php
// When setting up 2FA:
$backupCodes = Authenticator::generateBackupCodes();

// Hash each code before storing
$hashedCodes = array_map(fn($code) => password_hash($code, PASSWORD_BCRYPT), $backupCodes);
saveBackupCodes($userId, $hashedCodes);

// When verifying a backup code:
$storedCodes = getBackupCodes($userId);
foreach ($storedCodes as $index => $hashedCode) {
    if (password_verify($userInput, $hashedCode)) {
        // Remove used code
        unset($storedCodes[$index]);
        saveBackupCodes($userId, array_values($storedCodes));
        grantAccess();
        break;
    }
}
```

---

## Algorithm Options

| Algorithm | HMAC Size | Compatibility |
|-----------|-----------|---------------|
| `sha1` (default) | 160-bit | All authenticator apps |
| `sha256` | 256-bit | Most modern apps |
| `sha512` | 512-bit | Limited app support |

**Recommendation**: Use `sha1` for maximum compatibility unless you have specific security requirements and know your users' authenticator apps support SHA-256/512.

---

## Integration Patterns

### Database Schema

```sql
CREATE TABLE user_2fa (
    user_id     INT PRIMARY KEY,
    secret      VARCHAR(64) NOT NULL,      -- Base32 encoded secret
    is_enabled  TINYINT(1) DEFAULT 0,      -- Whether 2FA is active
    backup_codes TEXT,                       -- JSON array of hashed backup codes
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2FA Setup Flow

```php
use Razy\Authenticator;

// Step 1: Generate and temporarily store the secret
$secret = Authenticator::generateSecret();
$_SESSION['pending_2fa_secret'] = $secret;

// Step 2: Show QR code and manual key to user
$uri = Authenticator::getProvisioningUri($secret, $user->email, 'MyApp');
$qrUrl = Authenticator::getQrCodeDataUri($uri);

// Step 3: User enters the code from their app to confirm setup
if (Authenticator::verifyCode($secret, $_POST['verification_code'])) {
    // Save secret to database
    $backupCodes = Authenticator::generateBackupCodes();
    save2FA($user->id, $secret, $backupCodes);
    unset($_SESSION['pending_2fa_secret']);
}
```

### Login Verification Flow

```php
use Razy\Authenticator;

// After password verification, check if 2FA is enabled
$twoFA = get2FA($user->id);
if ($twoFA && $twoFA->is_enabled) {
    // Show 2FA input form
    if (isset($_POST['totp_code'])) {
        if (Authenticator::verifyCode($twoFA->secret, $_POST['totp_code'])) {
            completeLogin($user);
        } else {
            showError('Invalid authentication code.');
        }
    }
}
```

---

## Security Best Practices

1. **Store secrets securely**: Encrypt the Base32 secret at rest using `Crypt::encrypt()`.
2. **Use HTTPS only**: Never transmit secrets or codes over unencrypted connections.
3. **Rate limit verification**: Limit code verification attempts (e.g., 5 attempts per minute).
4. **Track used codes**: For TOTP, consider recording the last-used counter to prevent replay attacks within the time window.
5. **Hash backup codes**: Store backup codes as bcrypt/argon2 hashes, never in plaintext.
6. **Invalidate used backup codes**: Remove each backup code after single use.
7. **Require re-authentication**: Before enabling or disabling 2FA, verify the user's password.
8. **Show secret only once**: Display the QR code / manual key only during initial setup.
9. **Log 2FA events**: Record 2FA enable/disable/use for audit trails.
10. **Offer multiple recovery options**: Backup codes, trusted devices, or admin recovery.

---

## Troubleshooting

### Code Always Rejected

- **Clock drift**: Increase the verification window (`$window` parameter). Most issues are resolved with window=2.
- **Time zone**: TOTP uses Unix timestamps (UTC). Ensure the server clock is synchronized with NTP.
- **Wrong secret**: Verify the stored secret matches what was provisioned.

### QR Code Won't Scan

- Ensure the `otpauth://` URI is properly URL-encoded.
- Try manual entry of the secret key as a fallback.
- Check that the QR code image has enough contrast and resolution.

### HOTP Counter Desync

- Increase the look-ahead window when verifying.
- Always store the new counter value after successful verification (`$result + 1`).
- Provide a re-sync mechanism that checks a larger counter range.

### Base32 Errors

- Secrets must contain only uppercase letters A-Z and digits 2-7.
- Leading/trailing whitespace is not valid — `trim()` before decoding.
- Padding characters (`=`) are optional and stripped automatically.
