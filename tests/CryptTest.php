<?php

/**
 * Unit tests for Razy\Crypt.
 *
 * This file is part of Razy.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Crypt;

#[CoversClass(Crypt::class)]
class CryptTest extends TestCase
{
    // ==================== RZ-41: FORMAT V2 (ENCRYPT-THEN-MAC) ====================

    /** Fixed v2 vector: key, IV 00..0f, plaintext below; layout version . IV . ciphertext . HMAC. */
    private const KAT_KEY = 'rz41-known-answer-secret';

    private const KAT_PLAIN = 'Razy known-answer vector v2';

    private const KAT_V2 = '02000102030405060708090a0b0c0d0e0f46b309614ecca039f1c80b232d7959d9e9d4d561d1f41599e868fe939852747c0da3cf6e26fbf77497ba612f7705ebb5846250978d3efa62393ca9e1217b28e9';

    /** Written by the pre-RZ-41 Crypt::encrypt(..., true) (IV . HMAC(ciphertext) . ciphertext, one key). */
    private const LEGACY_KEY = 'legacy-test-secret';

    private const LEGACY_HEX = '37dc449c07b2914e4969e8dd71b4ebd9dbd64459aef348e0f823fefb4197f6e70ba3cd6c703f16eaa2d64230b9065a5771908a0864c35434d64e1b84b35f3e3dd414460f2c25f7afc69391d0e54701fc';

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

    /**
     * @return array{0: string, 1: string}
     */
    private static function specKeys(string $secret): array
    {
        return [
            \hash_hkdf('sha256', $secret, 32, 'razy-crypt-v2-aes-256-cbc'),
            \hash_hkdf('sha256', $secret, 32, 'razy-crypt-v2-hmac-sha256'),
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

        $this->assertNull($decrypted);
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
        $this->assertNull($decrypted);
    }

    public function testInvalidCiphertextFormat(): void
    {
        $invalid = 'this-is-not-valid-ciphertext';
        $decrypted = Crypt::decrypt($invalid, $this->testKey);

        $this->assertNull($decrypted);
    }

    public function testEmptyCiphertext(): void
    {
        $decrypted = Crypt::decrypt('', $this->testKey);
        $this->assertNull($decrypted);
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

    public function testKnownAnswerV2Decrypts(): void
    {
        $this->assertSame(self::KAT_PLAIN, Crypt::decrypt(self::KAT_V2, self::KAT_KEY));
        $this->assertSame(self::KAT_PLAIN, Crypt::decrypt(\hex2bin(self::KAT_V2), self::KAT_KEY, false));
    }

    public function testKnownAnswerV2MatchesTheSpec(): void
    {
        // Rebuild the vector from the documented construction, independently of Crypt.
        $iv = \hex2bin('000102030405060708090a0b0c0d0e0f');
        [$encKey, $macKey] = self::specKeys(self::KAT_KEY);
        $cipher = \openssl_encrypt(self::KAT_PLAIN, 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, $iv);
        $signed = "\x02" . $iv . $cipher;

        $this->assertSame(self::KAT_V2, \bin2hex($signed . \hash_hmac('sha256', $signed, $macKey, true)));
    }

    public function testEncryptWritesV2Envelope(): void
    {
        $sealed = Crypt::encrypt(self::KAT_PLAIN, self::KAT_KEY);
        [$encKey, $macKey] = self::specKeys(self::KAT_KEY);

        $this->assertSame(Crypt::VERSION, $sealed[0]);
        $this->assertSame(1 + 16 + 32 + 32, \strlen($sealed)); // 27 bytes pad to 2 blocks
        $signed = \substr($sealed, 0, -32);
        $this->assertSame(\hash_hmac('sha256', $signed, $macKey, true), \substr($sealed, -32), 'HMAC covers version + IV + ciphertext');
        $this->assertSame(self::KAT_PLAIN, \openssl_decrypt(\substr($signed, 17), 'AES-256-CBC', $encKey, OPENSSL_RAW_DATA, \substr($signed, 1, 16)));
        $this->assertFalse(Crypt::needsReencrypt($sealed, self::KAT_KEY));
    }

    public function testSecretIsNotUsedDirectlyForAesOrHmac(): void
    {
        $sealed = Crypt::encrypt('x', self::KAT_KEY);
        $signed = \substr($sealed, 0, -32);

        $this->assertNotSame(\hash_hmac('sha256', $signed, self::KAT_KEY, true), \substr($sealed, -32));
        $this->assertFalse(\openssl_decrypt(\substr($signed, 17), 'AES-256-CBC', self::KAT_KEY, OPENSSL_RAW_DATA, \substr($signed, 1, 16)) === 'x');
    }

    public function testIvBitFlipFails(): void
    {
        // Under the legacy format this flip passed the MAC and changed the first plaintext block.
        $sealed = \hex2bin(self::KAT_V2);
        for ($byte = 1; $byte <= 16; $byte++) {
            $tampered = $sealed;
            $tampered[$byte] = \chr(\ord($tampered[$byte]) ^ 0x01);
            $this->assertNull(Crypt::decrypt($tampered, self::KAT_KEY), "IV byte {$byte}");
        }
    }

    public function testIvBitFlipOnFreshEnvelopeFails(): void
    {
        // The attack itself: flip a bit in the first 17 bytes (where any IV lives) of a
        // fresh envelope. Pre-RZ-41 this returned the plaintext with one bit flipped.
        $sealed = Crypt::encrypt('pay=0001;to=alice', self::KAT_KEY);
        for ($byte = 0; $byte <= 16; $byte++) {
            $tampered = $sealed;
            $tampered[$byte] = \chr(\ord($tampered[$byte]) ^ 0x01);
            $this->assertNull(Crypt::decrypt($tampered, self::KAT_KEY), "byte {$byte}");
        }
    }

    public function testHmacTamperFails(): void
    {
        $sealed = \hex2bin(self::KAT_V2);
        $tampered = $sealed;
        $tampered[\strlen($tampered) - 32] = \chr(\ord($tampered[\strlen($tampered) - 32]) ^ 0x80);
        $this->assertNull(Crypt::decrypt($tampered, self::KAT_KEY));

        // A tag that is the old single-key MAC over the same bytes is refused too.
        $signed = \substr($sealed, 0, -32);
        $this->assertNull(Crypt::decrypt($signed . \hash_hmac('sha256', $signed, self::KAT_KEY, true), self::KAT_KEY));
    }

    public function testVersionAndCiphertextTamperFail(): void
    {
        $sealed = \hex2bin(self::KAT_V2);
        foreach (["\x01", "\x03", "\x00"] as $version) {
            $this->assertNull(Crypt::decrypt($version . \substr($sealed, 1), self::KAT_KEY));
        }
        $tampered = $sealed;
        $tampered[20] = \chr(\ord($tampered[20]) ^ 0x01);
        $this->assertNull(Crypt::decrypt($tampered, self::KAT_KEY));
        $this->assertNull(Crypt::decrypt(\substr($sealed, 0, -1), self::KAT_KEY), 'truncated');
    }

    public function testLegacyCiphertextStillDecrypts(): void
    {
        $this->assertSame("legacy plaintext: RZ-41 \u{1F512}", Crypt::decrypt(self::LEGACY_HEX, self::LEGACY_KEY, true));
        $this->assertTrue(Crypt::needsReencrypt(self::LEGACY_HEX, self::LEGACY_KEY));
        $this->assertFalse(Crypt::needsReencrypt(self::LEGACY_HEX, 'wrong-key'));
        $this->assertNull(Crypt::decrypt(self::LEGACY_HEX, self::LEGACY_KEY), 'legacy is refused unless the caller opts in');
    }

    public function testLegacyFirstBlockFlipIsRefusedByDefault(): void
    {
        // The legacy MAC skips the IV: flipping an IV bit flips the first plaintext
        // block and still passes it. Only the explicit migration opt-in accepts that.
        $legacy = \hex2bin(self::LEGACY_HEX);
        $legacy[0] = \chr(\ord($legacy[0]) ^ 0x01);

        $this->assertNull(Crypt::decrypt($legacy, self::LEGACY_KEY));
        $flipped = Crypt::decrypt($legacy, self::LEGACY_KEY, true);
        $this->assertNotNull($flipped);
        $this->assertNotSame("legacy plaintext: RZ-41 \u{1F512}", $flipped, 'the hazard the CHANGELOG names');
    }

    public function testLegacyWhoseIvStartsWithTheVersionByteStillDecrypts(): void
    {
        $iv = "\x02" . \str_repeat("\xAA", 15);
        $cipher = \openssl_encrypt('old', 'AES-256-CBC', self::LEGACY_KEY, OPENSSL_RAW_DATA, $iv);
        $legacy = $iv . \hash_hmac('sha256', $cipher, self::LEGACY_KEY, true) . $cipher;

        $this->assertSame('old', Crypt::decrypt($legacy, self::LEGACY_KEY, true));
    }

    public function testLegacyTamperStillFails(): void
    {
        $legacy = \hex2bin(self::LEGACY_HEX);
        $legacy[\strlen($legacy) - 1] = \chr(\ord($legacy[\strlen($legacy) - 1]) ^ 0x01);
        $this->assertNull(Crypt::decrypt($legacy, self::LEGACY_KEY, true));
    }

    public function testEmptyPlaintextIsNotAFailure(): void
    {
        // Before: '' meant both "empty plaintext" and "tampered"; now a failure is null.
        $this->assertSame('', Crypt::decrypt(Crypt::encrypt('', self::KAT_KEY), self::KAT_KEY));
        $this->assertNull(Crypt::decrypt(Crypt::encrypt('', self::KAT_KEY), 'other-key'));
    }

    public function testOddLengthHexAndEmptyKeyAreHandled(): void
    {
        $this->assertNull(Crypt::decrypt(\substr(self::KAT_V2, 0, -1), self::KAT_KEY));
        $this->assertSame('abc', Crypt::decrypt(Crypt::encrypt('abc', ''), ''));
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
