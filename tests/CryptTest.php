<?php

/**
 * Unit tests for Razy\Crypt.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Crypt;

#[CoversClass(Crypt::class)]
class CryptTest extends TestCase
{
    private string $testKey = 'test-encryption-key-32-characters';

    // ==================== DIFFERENT DATA TYPES ====================

    public static function encryptDecryptProvider(): array
    {
        return [
            'empty string' => [''],
            'numeric' => ['123456789'],
            'long text' => [\str_repeat('Lorem ipsum dolor sit amet. ', 100)],
            'special chars' => ['!@#$%^&*()_+-=[]{}|;:\'",.<>?/~`'],
            'unicode' => ['你好世�? ?? ??иве? ми?'],
            'multiline' => ["Line 1\nLine 2\nLine 3\n"],
            'single char' => ['a'],
            'binary' => ["\x00\x01\x02\x03\x04\x05"],
        ];
    }

    public static function keyLengthProvider(): array
    {
        return [
            'short key' => ['short'],
            'long key' => [\str_repeat('a', 256)],
        ];
    }

    // ==================== BASIC ENCRYPTION/DECRYPTION ====================

    public function testEncryptSimpleText(): void
    {
        $text = 'Hello, World!';
        $encrypted = Crypt::encrypt($text, $this->testKey);

        $this->assertNotEmpty($encrypted);
        $this->assertNotEquals($text, $encrypted);
    }

    public function testDecryptSimpleText(): void
    {
        $text = 'Hello, World!';
        $encrypted = Crypt::encrypt($text, $this->testKey);
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);

        $this->assertEquals($text, $decrypted);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $original = 'Secret message';
        $encrypted = Crypt::encrypt($original, $this->testKey);
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);

        $this->assertEquals($original, $decrypted);
    }

    // ==================== HEX ENCODING ====================

    public function testEncryptToHex(): void
    {
        $text = 'Test';
        $encrypted = Crypt::encrypt($text, $this->testKey, true);

        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $encrypted);
    }

    public function testDecryptFromHex(): void
    {
        $text = 'Hello';
        $encrypted = Crypt::encrypt($text, $this->testKey, true);
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);

        $this->assertEquals($text, $decrypted);
    }

    public function testHexRoundTrip(): void
    {
        $original = 'Hex encoded message';
        $encrypted = Crypt::encrypt($original, $this->testKey, true);

        // Verify it's hex
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $encrypted);

        // Decrypt
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);
        $this->assertEquals($original, $decrypted);
    }

    #[DataProvider('encryptDecryptProvider')]
    public function testEncryptDecryptRoundTripVariants(string $text): void
    {
        $encrypted = Crypt::encrypt($text, $this->testKey);
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);
        $this->assertEquals($text, $decrypted);
    }

    // ==================== KEY VARIATIONS ====================

    public function testDifferentKeys(): void
    {
        $text = 'Secret';
        $key1 = 'key-one-32-characters-long-key1';
        $key2 = 'key-two-32-characters-long-key2';

        $encrypted = Crypt::encrypt($text, $key1);
        $decrypted = Crypt::decrypt($encrypted, $key2);

        // Decryption with wrong key should fail
        $this->assertNotEquals($text, $decrypted);
    }

    public function testWrongKeyReturnsEmpty(): void
    {
        $text = 'Secret message';
        $correctKey = 'correct-key-32-chars-long-key';
        $wrongKey = 'wrong-key-32-characters-longkey';

        $encrypted = Crypt::encrypt($text, $correctKey);
        $decrypted = Crypt::decrypt($encrypted, $wrongKey);

        $this->assertEquals('', $decrypted);
    }

    #[DataProvider('keyLengthProvider')]
    public function testKeyLengthVariation(string $key): void
    {
        $text = 'Test';
        $encrypted = Crypt::encrypt($text, $key);
        $decrypted = Crypt::decrypt($encrypted, $key);
        $this->assertEquals($text, $decrypted);
    }

    // ==================== ENCRYPTION RANDOMNESS ====================

    public function testEncryptionIsRandom(): void
    {
        $text = 'Same text';

        $encrypted1 = Crypt::encrypt($text, $this->testKey);
        $encrypted2 = Crypt::encrypt($text, $this->testKey);

        // Due to random IV, encrypted values should be different
        $this->assertNotEquals($encrypted1, $encrypted2);

        // But both should decrypt to same text
        $this->assertEquals($text, Crypt::decrypt($encrypted1, $this->testKey));
        $this->assertEquals($text, Crypt::decrypt($encrypted2, $this->testKey));
    }

    // ==================== TAMPER DETECTION ====================

    public function testTamperedCiphertextFails(): void
    {
        $text = 'Original message';
        $encrypted = Crypt::encrypt($text, $this->testKey);

        // Tamper with the last character
        $tampered = \substr($encrypted, 0, -1) . \chr(\ord(\substr($encrypted, -1)) ^ 1);

        $decrypted = Crypt::decrypt($tampered, $this->testKey);

        // Tampered data should not decrypt properly
        $this->assertEquals('', $decrypted);
    }

    public function testInvalidCiphertextFormat(): void
    {
        $invalid = 'this-is-not-valid-ciphertext';
        $decrypted = Crypt::decrypt($invalid, $this->testKey);

        $this->assertEquals('', $decrypted);
    }

    public function testEmptyCiphertext(): void
    {
        $decrypted = Crypt::decrypt('', $this->testKey);
        $this->assertEquals('', $decrypted);
    }

    // ==================== BINARY VS HEX ====================

    public function testBinaryAndHexProduceDifferentFormats(): void
    {
        $text = 'Test';

        $binary = Crypt::encrypt($text, $this->testKey, false);
        $hex = Crypt::encrypt($text, $this->testKey, true);

        // Hex should be longer (each byte becomes 2 hex chars)
        $this->assertGreaterThan(\strlen($binary), \strlen($hex));

        // Hex should only contain hex characters
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $hex);

        // Binary may contain any bytes
        $this->assertNotEmpty($binary);
    }

    public function testBothFormatsDecryptCorrectly(): void
    {
        $text = 'Test message';

        $binary = Crypt::encrypt($text, $this->testKey, false);
        $hex = Crypt::encrypt($text, $this->testKey, true);

        $this->assertEquals($text, Crypt::decrypt($binary, $this->testKey));
        $this->assertEquals($text, Crypt::decrypt($hex, $this->testKey));
    }

    // ==================== EDGE CASES (single char & binary covered by DataProvider) ====================

    public function testEncryptedLength(): void
    {
        $text = 'Test';
        $encrypted = Crypt::encrypt($text, $this->testKey);

        // Encrypted data should be longer due to IV and HMAC
        // AES-256-CBC IV = 16 bytes, HMAC = 32 bytes, plus padded ciphertext
        $this->assertGreaterThan(48, \strlen($encrypted));
    }

    // ==================== CONSISTENCY TESTS ====================

    public function testMultipleRoundTrips(): void
    {
        $original = 'Test message';

        for ($i = 0; $i < 10; $i++) {
            $encrypted = Crypt::encrypt($original, $this->testKey);
            $decrypted = Crypt::decrypt($encrypted, $this->testKey);
            $this->assertEquals($original, $decrypted, "Failed at iteration $i");
        }
    }

    public function testDifferentTextsProduceDifferentCiphertexts(): void
    {
        $text1 = 'Message 1';
        $text2 = 'Message 2';

        $encrypted1 = Crypt::encrypt($text1, $this->testKey);
        $encrypted2 = Crypt::encrypt($text2, $this->testKey);

        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    // ==================== REAL-WORLD SCENARIOS ====================

    public function testEncryptSensitiveData(): void
    {
        $data = \json_encode([
            'password' => 'secret123',
            'api_key' => 'ABC123XYZ',
            'credit_card' => '1234-5678-9012-3456',
        ]);

        $encrypted = Crypt::encrypt($data, $this->testKey, true);
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);

        $this->assertEquals($data, $decrypted);

        $decoded = \json_decode($decrypted, true);
        $this->assertEquals('secret123', $decoded['password']);
    }

    public function testEncryptSessionToken(): void
    {
        $token = \bin2hex(\random_bytes(32));

        $encrypted = Crypt::encrypt($token, $this->testKey, true);
        $decrypted = Crypt::decrypt($encrypted, $this->testKey);

        $this->assertEquals($token, $decrypted);
        $this->assertEquals(64, \strlen($token)); // 32 bytes = 64 hex chars
    }
}
