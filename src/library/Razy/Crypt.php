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

class Crypt
{
    /**
     * Decrypt the ciphertext.
     *
     * @param string $encryptedText
     * @param string $key
     *
     * @return string
     */
    public static function Decrypt(string $encryptedText, string $key): string
    {
        // If the encrypted text is hex string, convert to binary
        if (preg_match('/^[a-z\d]+$/', $encryptedText)) {
            $encryptedText = hex2bin($encryptedText);
        }
        $length = openssl_cipher_iv_length($cipher = 'AES-256-CBC');
        if (preg_match('/^(.{' . $length . '})(.{32})(.+)$/', $encryptedText, $matches)) {
            [, $iv, $hmac, $cipherText] = $matches;
        } else {
            return '';
        }

        $decryptedText = openssl_decrypt($cipherText, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        $decryptedHmac = hash_hmac('sha256', $cipherText, $key, true);
        if (hash_equals($hmac, $decryptedHmac)) {
            return $decryptedText;
        }
        return '';
    }

    /**
     * Encrypt the text.
     *
     * @param string $text
     * @param string $key
     * @param bool   $toHex
     *
     * @return string
     */
    public static function Encrypt(string $text, string $key, bool $toHex = false): string
    {
        $iv         = openssl_random_pseudo_bytes(openssl_cipher_iv_length($cipher = 'AES-256-CBC'));
        $cipherText = openssl_encrypt($text, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        $hmac       = hash_hmac('sha256', $cipherText, $key, true);
        if (!$hmac) {
            return '';
        }

        $result = $iv . $hmac . $cipherText;
        return ($toHex) ? bin2hex($result) : $result;
    }
}
