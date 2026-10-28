<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

/**
 * Crypt provides symmetric AES-256-CBC encryption with HMAC-SHA256 authentication
 * (encrypt-then-MAC).
 *
 * Format v2 (written by encrypt() since RZ-41):
 *
 *     version (1 byte, 0x02) . IV (16 bytes) . ciphertext . HMAC-SHA256 (32 bytes)
 *
 * The HMAC covers version + IV + ciphertext, so no byte of the envelope can change
 * without failing verification, and it is checked (in constant time) before anything
 * is decrypted. The AES key and the HMAC key are derived separately from the one
 * secret with HKDF-SHA256 and distinct info strings, so the secret is never used
 * directly by either primitive.
 *
 * The key is treated as a high-entropy secret (e.g. `random_bytes(32)`); HKDF is not
 * a password hash. Derive keys from passwords yourself (Argon2 via `sodium_*`).
 *
 * Legacy format (v1, written before RZ-41): IV (16) . HMAC (32) . ciphertext, with the
 * HMAC over the ciphertext only and the raw secret used for both AES and HMAC. Its IV is
 * not authenticated, so a first-block bit flip passes its MAC. decrypt() still reads it
 * (read-only, verify before decrypt) so stored values can be migrated; it is
 * deprecated and the legacy path is removed in v2.0.0. Re-encrypt stored values with
 * encrypt() (see needsReencrypt()), or pass `$allowLegacy = false` to refuse it now.
 *
 * Both formats may be passed raw or hex-encoded.
 *
 * @class Crypt
 *
 * @license MIT
 */
class Crypt
{
    /** Version byte that opens every v2 envelope. */
    public const VERSION = "\x02";

    private const CIPHER = 'AES-256-CBC';

    private const IV_LENGTH = 16;

    private const MAC_LENGTH = 32;

    /** HKDF info strings: one secret, two keys that never coincide. */
    private const INFO_ENC = 'razy-crypt-v2-aes-256-cbc';

    private const INFO_MAC = 'razy-crypt-v2-hmac-sha256';

    /**
     * Decrypt a value produced by encrypt().
     *
     * Accepts raw binary or hex. The HMAC is verified before decryption; any
     * tampering (version, IV, ciphertext or tag), a wrong key or malformed input
     * returns an empty string.
     *
     * @param string $encryptedText The encrypted data (hex string or raw binary)
     * @param string $key The secret
     * @param bool $allowLegacy Also accept the deprecated pre-RZ-41 format (removed in v2.0.0)
     *
     * @return string The plaintext, or empty string on failure
     */
    public static function decrypt(string $encryptedText, string $key, bool $allowLegacy = true): string
    {
        $data = self::toBinary($encryptedText);

        $plain = self::decryptV2($data, $key);
        if ($plain !== null) {
            return $plain;
        }

        // A legacy envelope opens with a random IV, so it can also start with 0x02:
        // every input that fails v2 is tried as legacy, never the other way round.
        if ($allowLegacy) {
            return self::decryptLegacy($data, $key) ?? '';
        }

        return '';
    }

    /**
     * Encrypt plaintext into a v2 envelope.
     *
     * @param string $text The plaintext to encrypt
     * @param string $key The secret
     * @param bool $toHex Whether to return the result as a hex-encoded string
     *
     * @return string The encrypted data (binary or hex), or empty string on failure
     */
    public static function encrypt(string $text, string $key, bool $toHex = false): string
    {
        [$encKey, $macKey] = self::deriveKeys($key);
        $iv = \random_bytes(self::IV_LENGTH);

        $cipherText = \openssl_encrypt($text, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            return '';
        }

        $header = self::VERSION . $iv;
        $result = $header . $cipherText . \hash_hmac('sha256', $header . $cipherText, $macKey, true);

        return ($toHex) ? \bin2hex($result) : $result;
    }

    /**
     * True when the value decrypts only through the deprecated legacy path, so it
     * should be re-encrypted with encrypt() before v2.0.0 removes that path.
     *
     * @param string $encryptedText The encrypted data (hex string or raw binary)
     * @param string $key The secret
     */
    public static function needsReencrypt(string $encryptedText, string $key): bool
    {
        $data = self::toBinary($encryptedText);

        return self::decryptV2($data, $key) === null && self::decryptLegacy($data, $key) !== null;
    }

    /**
     * Hex input (even length, hex digits only) becomes binary; anything else is
     * taken as binary already. A v2 envelope starts with 0x02, never a hex digit.
     */
    private static function toBinary(string $encryptedText): string
    {
        if ($encryptedText !== '' && \strlen($encryptedText) % 2 === 0 && \ctype_xdigit($encryptedText)) {
            return (string) \hex2bin($encryptedText);
        }

        return $encryptedText;
    }

    /**
     * @return array{0: string, 1: string} [AES key, HMAC key]
     */
    private static function deriveKeys(string $key): array
    {
        // hash_hkdf() refuses an empty input key; an empty secret still yields keys
        // (as encrypt() always has), it just protects nothing.
        $ikm = ($key === '') ? "\0" : $key;

        return [
            \hash_hkdf('sha256', $ikm, 32, self::INFO_ENC),
            \hash_hkdf('sha256', $ikm, 32, self::INFO_MAC),
        ];
    }

    /**
     * Verify then decrypt a v2 envelope; null when it is not a valid one for this key.
     */
    private static function decryptV2(string $data, string $key): ?string
    {
        // version + IV + at least one cipher block + tag
        if (\strlen($data) < 1 + self::IV_LENGTH + 16 + self::MAC_LENGTH || $data[0] !== self::VERSION) {
            return null;
        }

        [$encKey, $macKey] = self::deriveKeys($key);
        $signed = \substr($data, 0, -self::MAC_LENGTH);
        $tag = \substr($data, -self::MAC_LENGTH);
        if (!\hash_equals(\hash_hmac('sha256', $signed, $macKey, true), $tag)) {
            return null;
        }

        $iv = \substr($signed, 1, self::IV_LENGTH);
        $plain = \openssl_decrypt(\substr($signed, 1 + self::IV_LENGTH), self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);

        return ($plain === false) ? null : $plain;
    }

    /**
     * Verify then decrypt a legacy (pre-RZ-41) envelope; null on failure.
     *
     * @deprecated since RZ-41; removed in v2.0.0. Its IV is not covered by the MAC.
     */
    private static function decryptLegacy(string $data, string $key): ?string
    {
        if (\strlen($data) <= self::IV_LENGTH + self::MAC_LENGTH) {
            return null;
        }

        $iv = \substr($data, 0, self::IV_LENGTH);
        $hmac = \substr($data, self::IV_LENGTH, self::MAC_LENGTH);
        $cipherText = \substr($data, self::IV_LENGTH + self::MAC_LENGTH);
        if (!\hash_equals(\hash_hmac('sha256', $cipherText, $key, true), $hmac)) {
            return null;
        }

        $plain = \openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return ($plain === false) ? null : $plain;
    }
}
