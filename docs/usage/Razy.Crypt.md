# Razy\Crypt

## Summary
- AES-256-CBC encryption and decryption with HMAC.

## Key methods
- `Encrypt($text, $key, $toHex)`: returns binary or hex.
- `Decrypt($encryptedText, $key)`: returns plaintext or empty string.

## Usage notes
- Decrypt expects IV + HMAC + ciphertext layout.
