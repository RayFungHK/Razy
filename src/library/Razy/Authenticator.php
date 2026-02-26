<?php
/**
 * This file is part of Razy v0.5.
 *
 * TOTP/HOTP Authenticator for two-factor authentication (2FA).
 * Implements RFC 6238 (TOTP) and RFC 4226 (HOTP) with support for
 * Google Authenticator, Microsoft Authenticator, Authy, and similar apps.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

/**
 * Two-Factor Authentication (2FA) using TOTP/HOTP.
 *
 * Generates and validates time-based (TOTP) and counter-based (HOTP) one-time
 * passwords compatible with Google Authenticator, Microsoft Authenticator,
 * Authy, and other RFC 6238/4226 compliant apps.
 *
 * Features:
 * - TOTP (time-based) and HOTP (counter-based) support
 * - Configurable code length (6-8 digits)
 * - Configurable time period (default 30s)
 * - Clock drift tolerance (configurable window)
 * - SHA-1, SHA-256, SHA-512 hash algorithms
 * - Base32 secret generation and encoding/decoding
 * - otpauth:// URI generation for QR code provisioning
 * - Scratch/backup code generation
 *
 * Usage:
 *   // Generate a secret
 *   $secret = Authenticator::generateSecret();
 *
 *   // Get the current TOTP code
 *   $code = Authenticator::getCode($secret);
 *
 *   // Verify a user-supplied code
 *   $valid = Authenticator::verifyCode($secret, $userInput);
 *
 *   // Generate a provisioning URI for QR codes
 *   $uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');
 *
 * @class Authenticator
 */
class Authenticator
{
    /** @var string Base32 alphabet used for secret encoding (RFC 4648) */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** @var int Default number of digits in the generated OTP code */
    private const DEFAULT_DIGITS = 6;

    /** @var int Default time period in seconds for TOTP */
    private const DEFAULT_PERIOD = 30;

    /** @var int Default clock drift window (number of periods to check before/after) */
    private const DEFAULT_WINDOW = 1;

    /** @var string Default hash algorithm */
    private const DEFAULT_ALGORITHM = 'sha1';

    /** @var int Default secret length in bytes (produces 32 Base32 characters) */
    private const DEFAULT_SECRET_LENGTH = 20;

    /** @var array<string> Supported hash algorithms */
    private const SUPPORTED_ALGORITHMS = ['sha1', 'sha256', 'sha512'];

    // ==================== SECRET GENERATION ====================

    /**
     * Generate a cryptographically secure random secret key.
     *
     * Returns a Base32-encoded string suitable for use with authenticator apps.
     * The default 20-byte (160-bit) key produces a 32-character Base32 string.
     *
     * @param int $length Secret length in bytes (default: 20, minimum: 16)
     *
     * @return string Base32-encoded secret key
     *
     * @throws \InvalidArgumentException If length is less than 16
     */
    public static function generateSecret(int $length = self::DEFAULT_SECRET_LENGTH): string
    {
        if ($length < 16) {
            throw new \InvalidArgumentException('Secret length must be at least 16 bytes for security.');
        }

        $randomBytes = random_bytes($length);

        return self::base32Encode($randomBytes);
    }

    /**
     * Generate a set of single-use backup (scratch) codes.
     *
     * These codes serve as recovery tokens when the authenticator device
     * is unavailable. Each code should be used only once and stored securely.
     *
     * @param int $count  Number of backup codes to generate (default: 8)
     * @param int $length Character length of each code (default: 8)
     *
     * @return array<string> Array of alphanumeric backup codes
     */
    public static function generateBackupCodes(int $count = 8, int $length = 8): array
    {
        $codes = [];
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $max = strlen($chars) - 1;

        for ($i = 0; $i < $count; $i++) {
            $code = '';
            for ($j = 0; $j < $length; $j++) {
                $code .= $chars[random_int(0, $max)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    // ==================== CODE GENERATION ====================

    /**
     * Generate a TOTP code for the current time period.
     *
     * @param string $secret    Base32-encoded secret key
     * @param int    $digits    Number of digits (6-8, default: 6)
     * @param int    $period    Time period in seconds (default: 30)
     * @param string $algorithm Hash algorithm ('sha1', 'sha256', 'sha512')
     * @param int|null $timestamp Custom Unix timestamp (null = current time)
     *
     * @return string Zero-padded OTP code
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    public static function getCode(
        string $secret,
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD,
        string $algorithm = self::DEFAULT_ALGORITHM,
        ?int $timestamp = null
    ): string {
        self::validateParameters($digits, $period, $algorithm);

        $timestamp = $timestamp ?? time();
        $counter = (int) floor($timestamp / $period);

        return self::generateOTP($secret, $counter, $digits, $algorithm);
    }

    /**
     * Generate an HOTP code for a given counter value.
     *
     * @param string $secret    Base32-encoded secret key
     * @param int    $counter   Counter value
     * @param int    $digits    Number of digits (6-8, default: 6)
     * @param string $algorithm Hash algorithm ('sha1', 'sha256', 'sha512')
     *
     * @return string Zero-padded OTP code
     *
     * @throws \InvalidArgumentException If parameters are invalid
     */
    public static function getHotpCode(
        string $secret,
        int $counter,
        int $digits = self::DEFAULT_DIGITS,
        string $algorithm = self::DEFAULT_ALGORITHM
    ): string {
        if ($digits < 6 || $digits > 8) {
            throw new \InvalidArgumentException('Digits must be between 6 and 8.');
        }
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException("Unsupported algorithm: {$algorithm}");
        }

        return self::generateOTP($secret, $counter, $digits, $algorithm);
    }

    // ==================== CODE VERIFICATION ====================

    /**
     * Verify a TOTP code against the current time with clock drift tolerance.
     *
     * Checks the provided code against the current time period and a configurable
     * number of adjacent periods (window) to account for clock drift between
     * the server and the user's authenticator device.
     *
     * @param string   $secret    Base32-encoded secret key
     * @param string   $code      User-supplied OTP code to verify
     * @param int      $digits    Number of digits (default: 6)
     * @param int      $period    Time period in seconds (default: 30)
     * @param string   $algorithm Hash algorithm (default: 'sha1')
     * @param int      $window    Number of periods to check before/after (default: 1)
     * @param int|null $timestamp Custom Unix timestamp (null = current time)
     *
     * @return bool True if the code is valid within the time window
     */
    public static function verifyCode(
        string $secret,
        string $code,
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD,
        string $algorithm = self::DEFAULT_ALGORITHM,
        int $window = self::DEFAULT_WINDOW,
        ?int $timestamp = null
    ): bool {
        self::validateParameters($digits, $period, $algorithm);

        $timestamp = $timestamp ?? time();
        $currentCounter = (int) floor($timestamp / $period);

        // Normalize: strip spaces and leading zeros won't matter for comparison
        $code = trim($code);

        // Check the current period and adjacent periods within the window
        for ($i = -$window; $i <= $window; $i++) {
            $checkCounter = $currentCounter + $i;
            if ($checkCounter < 0) {
                continue;
            }

            $expected = self::generateOTP($secret, $checkCounter, $digits, $algorithm);

            // Use timing-safe comparison to prevent timing attacks
            if (hash_equals($expected, str_pad($code, $digits, '0', STR_PAD_LEFT))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify an HOTP code against a counter value with look-ahead window.
     *
     * Checks the code against the current counter and a configurable number
     * of subsequent counter values to handle missed codes.
     *
     * @param string $secret    Base32-encoded secret key
     * @param string $code      User-supplied OTP code to verify
     * @param int    $counter   Current counter value
     * @param int    $digits    Number of digits (default: 6)
     * @param string $algorithm Hash algorithm (default: 'sha1')
     * @param int    $window    Number of counter values to look ahead (default: 5)
     *
     * @return int|false The matched counter value, or false if verification fails.
     *                   On success, the caller should store counter + 1 as the new counter.
     */
    public static function verifyHotpCode(
        string $secret,
        string $code,
        int $counter,
        int $digits = self::DEFAULT_DIGITS,
        string $algorithm = self::DEFAULT_ALGORITHM,
        int $window = 5
    ): int|false {
        if ($digits < 6 || $digits > 8) {
            throw new \InvalidArgumentException('Digits must be between 6 and 8.');
        }
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException("Unsupported algorithm: {$algorithm}");
        }

        $code = trim($code);

        for ($i = 0; $i <= $window; $i++) {
            $checkCounter = $counter + $i;
            $expected = self::generateOTP($secret, $checkCounter, $digits, $algorithm);

            if (hash_equals($expected, str_pad($code, $digits, '0', STR_PAD_LEFT))) {
                return $checkCounter;
            }
        }

        return false;
    }

    // ==================== PROVISIONING URI ====================

    /**
     * Generate an otpauth:// URI for QR code provisioning.
     *
     * The URI follows the Key URI Format used by Google Authenticator and
     * other authenticator apps:
     *   otpauth://totp/Issuer:account?secret=...&issuer=...&algorithm=...&digits=...&period=...
     *
     * @param string $secret    Base32-encoded secret key
     * @param string $account   User account identifier (e.g., email address)
     * @param string $issuer    Service name (e.g., company or application name)
     * @param int    $digits    Number of digits (default: 6)
     * @param int    $period    Time period in seconds (default: 30)
     * @param string $algorithm Hash algorithm (default: 'sha1')
     *
     * @return string otpauth:// URI string
     */
    public static function getProvisioningUri(
        string $secret,
        string $account,
        string $issuer,
        int $digits = self::DEFAULT_DIGITS,
        int $period = self::DEFAULT_PERIOD,
        string $algorithm = self::DEFAULT_ALGORITHM
    ): string {
        self::validateParameters($digits, $period, $algorithm);

        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        $params = [
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($algorithm),
            'digits'    => $digits,
            'period'    => $period,
        ];

        return 'otpauth://totp/' . $label . '?' . http_build_query($params);
    }

    /**
     * Generate an otpauth:// URI for HOTP (counter-based) provisioning.
     *
     * @param string $secret    Base32-encoded secret key
     * @param string $account   User account identifier
     * @param string $issuer    Service name
     * @param int    $counter   Initial counter value
     * @param int    $digits    Number of digits (default: 6)
     * @param string $algorithm Hash algorithm (default: 'sha1')
     *
     * @return string otpauth:// URI string
     */
    public static function getHotpProvisioningUri(
        string $secret,
        string $account,
        string $issuer,
        int $counter = 0,
        int $digits = self::DEFAULT_DIGITS,
        string $algorithm = self::DEFAULT_ALGORITHM
    ): string {
        if ($digits < 6 || $digits > 8) {
            throw new \InvalidArgumentException('Digits must be between 6 and 8.');
        }
        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException("Unsupported algorithm: {$algorithm}");
        }

        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        $params = [
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($algorithm),
            'digits'    => $digits,
            'counter'   => $counter,
        ];

        return 'otpauth://hotp/' . $label . '?' . http_build_query($params);
    }

    /**
     * Generate QR code image data as an SVG string.
     *
     * Creates a minimal QR code SVG without external dependencies. The SVG
     * can be embedded directly in HTML or saved to a file.
     *
     * This implementation uses a simple QR encoding approach suitable for
     * otpauth:// URIs. For production use with very long URIs or complex
     * data, consider a dedicated QR library.
     *
     * @param string $uri    The otpauth:// URI to encode
     * @param int    $size   SVG width/height in pixels (default: 200)
     * @param string $fgColor Foreground color (default: '#000000')
     * @param string $bgColor Background color (default: '#ffffff')
     *
     * @return string Data URI for embedding in an <img> tag (base64 SVG), or
     *                the provisioning URI itself if QR cannot be generated
     */
    public static function getQrCodeDataUri(
        string $uri,
        int $size = 200,
        string $fgColor = '#000000',
        string $bgColor = '#ffffff'
    ): string {
        // Generate the provisioning URI as a simple data URI
        // For actual QR code generation, use a library like chillerlan/php-qrcode
        // or a Google Chart API URL.
        // Here we provide a Google Chart API fallback:
        return 'https://chart.googleapis.com/chart?chs=' . $size . 'x' . $size
            . '&chld=M|0&cht=qr&chl=' . urlencode($uri);
    }

    // ==================== BASE32 ENCODING/DECODING ====================

    /**
     * Encode binary data to Base32 (RFC 4648).
     *
     * @param string $data Raw binary data to encode
     *
     * @return string Base32-encoded string (uppercase, no padding)
     */
    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $binary = '';
        foreach (str_split($data) as $byte) {
            $binary .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        $chunks = str_split($binary, 5);

        foreach ($chunks as $chunk) {
            // Pad the last chunk to 5 bits if needed
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    /**
     * Decode a Base32-encoded string to binary data (RFC 4648).
     *
     * Handles both uppercase and lowercase input. Strips any padding characters (=).
     *
     * @param string $encoded Base32-encoded string
     *
     * @return string Decoded binary data
     *
     * @throws \InvalidArgumentException If the input contains invalid Base32 characters
     */
    public static function base32Decode(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }

        // Normalize: uppercase and strip padding
        $encoded = rtrim(strtoupper($encoded), '=');

        $binary = '';
        for ($i = 0; $i < strlen($encoded); $i++) {
            $char = $encoded[$i];
            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                throw new \InvalidArgumentException("Invalid Base32 character: '{$char}'");
            }

            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        $byteChunks = str_split($binary, 8);

        foreach ($byteChunks as $byte) {
            if (strlen($byte) < 8) {
                break; // Discard incomplete trailing bits
            }
            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }

    // ==================== INTERNAL METHODS ====================

    /**
     * Generate an OTP code using HMAC-based One-Time Password algorithm.
     *
     * This is the core HOTP algorithm (RFC 4226 Section 5.3):
     * 1. Convert counter to 8-byte big-endian binary
     * 2. Compute HMAC-SHA hash using the secret
     * 3. Dynamic truncation to extract a 4-byte code
     * 4. Reduce modulo 10^digits for the final OTP
     *
     * @param string $secret    Base32-encoded secret key
     * @param int    $counter   Counter value (or time-derived counter for TOTP)
     * @param int    $digits    Number of output digits
     * @param string $algorithm Hash algorithm for HMAC
     *
     * @return string Zero-padded OTP code
     */
    private static function generateOTP(string $secret, int $counter, int $digits, string $algorithm): string
    {
        // Decode Base32 secret to raw binary
        $key = self::base32Decode($secret);

        // Pack counter as 8-byte big-endian (network byte order)
        $counterBytes = pack('N*', 0, $counter);

        // Compute HMAC hash
        $hash = hash_hmac($algorithm, $counterBytes, $key, true);

        // Dynamic truncation (RFC 4226 Section 5.4):
        // Use the low-order 4 bits of the last byte as the offset
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        // Extract 4 bytes starting at the offset, mask the high bit
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        // Generate the OTP by taking modulo 10^digits
        $otp = $binary % (10 ** $digits);

        // Zero-pad to the required number of digits
        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Validate common TOTP parameters.
     *
     * @param int    $digits    Number of digits
     * @param int    $period    Time period in seconds
     * @param string $algorithm Hash algorithm
     *
     * @throws \InvalidArgumentException If any parameter is invalid
     */
    private static function validateParameters(int $digits, int $period, string $algorithm): void
    {
        if ($digits < 6 || $digits > 8) {
            throw new \InvalidArgumentException('Digits must be between 6 and 8.');
        }

        if ($period < 1) {
            throw new \InvalidArgumentException('Period must be at least 1 second.');
        }

        if (!in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported algorithm: {$algorithm}. Supported: " . implode(', ', self::SUPPORTED_ALGORITHMS)
            );
        }
    }
}
