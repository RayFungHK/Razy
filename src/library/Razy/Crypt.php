<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

/**
 * Crypt provides symmetric AES-256-CBC encryption and decryption with HMAC-SHA256 integrity verification.
 *
 * The encrypted output format is: IV (16 bytes) + HMAC (32 bytes) + ciphertext.
 * Supports both raw binary and hex-encoded representations.
 *
 * @class Crypt
 *
 * @package Razy
 *
 * @license MIT
 */
class Crypt
{
    /**
     * Decrypt an AES-256-CBC encrypted ciphertext with HMAC verification.
     *
     * Accepts either hex-encoded or raw binary input. Verifies the HMAC-SHA256
     * signature before returning the plaintext to ensure data integrity.
     *
     * @param string $encryptedText The encrypted data (hex string or raw binary)
     * @param string $key The encryption key
     *
     * @return string The decrypted plaintext, or empty string on failure
     */
    public static function decrypt(string $encryptedText, string $key): string
    {
        // Auto-detect hex encoding and convert to raw binary
        if (\preg_match('/^[a-z\d]+$/', $encryptedText)) {
            $encryptedText = \hex2bin($encryptedText);
        }

        // Determine the IV length for AES-256-CBC (typically 16 bytes)
        $length = \openssl_cipher_iv_length($cipher = 'AES-256-CBC');

        // Extract components: IV + HMAC (32 bytes raw SHA-256) + ciphertext
        if (\preg_match('/^(.{' . $length . '})(.{32})(.+)$/s', $encryptedText, $matches)) {
            [, $iv, $hmac, $cipherText] = $matches;
        } else {
            // Input too short or malformed
            return '';
        }

        // Decrypt the ciphertext using the extracted IV
        $decryptedText = \openssl_decrypt($cipherText, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        // Recompute HMAC over the ciphertext and verify against the stored HMAC
        $decryptedHmac = \hash_hmac('sha256', $cipherText, $key, true);
        if (\hash_equals($hmac, $decryptedHmac)) {
            return $decryptedText;
        }

        // HMAC mismatch: data has been tampered with or wrong key
        return '';
    }

    /**
     * Encrypt plaintext using AES-256-CBC with HMAC-SHA256 authentication.
     *
     * Generates a random IV, encrypts the text, computes an HMAC over the ciphertext,
     * and concatenates: IV + HMAC + ciphertext. Optionally returns as hex string.
     *
     * @param string $text The plaintext to encrypt
     * @param string $key The encryption key
     * @param bool $toHex Whether to return the result as a hex-encoded string
     *
     * @return string The encrypted data (binary or hex), or empty string on failure
     */
    public static function encrypt(string $text, string $key, bool $toHex = false): string
    {
        // Generate a cryptographically secure random IV
        $iv = \openssl_random_pseudo_bytes(\openssl_cipher_iv_length($cipher = 'AES-256-CBC'));

        // Encrypt plaintext with AES-256-CBC using raw binary output
        $cipherText = \openssl_encrypt($text, $cipher, $key, OPENSSL_RAW_DATA, $iv);

        // Compute HMAC-SHA256 over the ciphertext for integrity verification
        $hmac = \hash_hmac('sha256', $cipherText, $key, true);
        if (!$hmac) {
            return '';
        }

        // Assemble: IV + HMAC + ciphertext
        $result = $iv . $hmac . $cipherText;

        // Convert to hex if requested (useful for storage/transmission in text formats)
        return ($toHex) ? \bin2hex($result) : $result;
    }
}
