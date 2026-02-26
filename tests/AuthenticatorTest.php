<?php
/**
 * Unit tests for Razy\Authenticator.
 *
 * This file is part of Razy v0.5.
 * Tests TOTP/HOTP generation, verification, Base32 encoding/decoding,
 * provisioning URIs, backup codes, and RFC 6238 test vectors.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Authenticator;

#[CoversClass(Authenticator::class)]
class AuthenticatorTest extends TestCase
{
    /**
     * RFC 6238 test secret: "12345678901234567890" encoded as Base32.
     * This is the canonical test vector from the RFC.
     */
    private string $rfcSecret;

    protected function setUp(): void
    {
        // "12345678901234567890" in Base32
        $this->rfcSecret = Authenticator::base32Encode('12345678901234567890');
    }

    // ==================== SECRET GENERATION ====================

    public function testGenerateSecretDefaultLength(): void
    {
        $secret = Authenticator::generateSecret();

        $this->assertNotEmpty($secret);
        // 20 bytes = 32 Base32 characters
        $this->assertEquals(32, strlen($secret));
    }

    public function testGenerateSecretCustomLength(): void
    {
        $secret = Authenticator::generateSecret(32);

        $this->assertNotEmpty($secret);
        // 32 bytes → more Base32 characters
        $this->assertGreaterThan(32, strlen($secret));
    }

    public function testGenerateSecretMinimumLength(): void
    {
        $secret = Authenticator::generateSecret(16);

        $this->assertNotEmpty($secret);
    }

    public function testGenerateSecretTooShortThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 16 bytes');

        Authenticator::generateSecret(8);
    }

    public function testGenerateSecretUniqueness(): void
    {
        $secrets = [];
        for ($i = 0; $i < 10; $i++) {
            $secrets[] = Authenticator::generateSecret();
        }

        // All generated secrets should be unique
        $this->assertCount(10, array_unique($secrets));
    }

    public function testGenerateSecretBase32Valid(): void
    {
        $secret = Authenticator::generateSecret();

        // Should only contain valid Base32 characters
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    // ==================== BASE32 ENCODING/DECODING ====================

    public function testBase32EncodeEmpty(): void
    {
        $this->assertEquals('', Authenticator::base32Encode(''));
    }

    public function testBase32DecodeEmpty(): void
    {
        $this->assertEquals('', Authenticator::base32Decode(''));
    }

    public function testBase32RoundTrip(): void
    {
        $original = 'Hello, World!';
        $encoded = Authenticator::base32Encode($original);
        $decoded = Authenticator::base32Decode($encoded);

        $this->assertEquals($original, $decoded);
    }

    public function testBase32RoundTripBinary(): void
    {
        $binary = random_bytes(20);
        $encoded = Authenticator::base32Encode($binary);
        $decoded = Authenticator::base32Decode($encoded);

        $this->assertEquals($binary, $decoded);
    }

    public function testBase32EncodeKnownValue(): void
    {
        // RFC 4648 test vectors
        $this->assertEquals('MY', Authenticator::base32Encode('f'));
        $this->assertEquals('MZXQ', Authenticator::base32Encode('fo'));
        $this->assertEquals('MZXW6', Authenticator::base32Encode('foo'));
        $this->assertEquals('MZXW6YQ', Authenticator::base32Encode('foob'));
        $this->assertEquals('MZXW6YTB', Authenticator::base32Encode('fooba'));
        $this->assertEquals('MZXW6YTBOI', Authenticator::base32Encode('foobar'));
    }

    public function testBase32DecodeCaseInsensitive(): void
    {
        $upper = Authenticator::base32Decode('MZXW6YTBOI');
        $lower = Authenticator::base32Decode('mzxw6ytboi');
        $mixed = Authenticator::base32Decode('MzXw6yTbOi');

        $this->assertEquals('foobar', $upper);
        $this->assertEquals('foobar', $lower);
        $this->assertEquals('foobar', $mixed);
    }

    public function testBase32DecodeStripspadding(): void
    {
        $withPadding = Authenticator::base32Decode('MZXW6===');
        $withoutPadding = Authenticator::base32Decode('MZXW6');

        $this->assertEquals($withPadding, $withoutPadding);
        $this->assertEquals('foo', $withPadding);
    }

    public function testBase32DecodeInvalidCharacterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Base32 character');

        Authenticator::base32Decode('INVALID!CHARS');
    }

    public function testBase32DecodeDigit0Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // '0' and '1' are not valid Base32 characters
        Authenticator::base32Decode('A0B1C');
    }

    // ==================== TOTP CODE GENERATION ====================

    public function testGetCodeReturnsCorrectLength(): void
    {
        $code = Authenticator::getCode($this->rfcSecret);

        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testGetCode8Digits(): void
    {
        $code = Authenticator::getCode($this->rfcSecret, 8);

        $this->assertEquals(8, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
    }

    public function testGetCodeDeterministic(): void
    {
        $timestamp = 1234567890;

        $code1 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp);
        $code2 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp);

        $this->assertEquals($code1, $code2);
    }

    public function testGetCodeDifferentTimestamps(): void
    {
        // Two timestamps far apart should give different codes
        $code1 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', 1000000000);
        $code2 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', 2000000000);

        $this->assertNotEquals($code1, $code2);
    }

    public function testGetCodeSamePeriod(): void
    {
        // Timestamps within the same 30-second period should give the same code
        $code1 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', 1000000000);
        $code2 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', 1000000015);

        $this->assertEquals($code1, $code2);
    }

    /**
     * RFC 6238 test vectors for SHA-1.
     * Table from Appendix B of RFC 6238.
     */
    public function testRfc6238Sha1Vectors(): void
    {
        $secret = Authenticator::base32Encode('12345678901234567890');

        // Time = 59, Counter = 1
        $code = Authenticator::getCode($secret, 8, 30, 'sha1', 59);
        $this->assertEquals('94287082', $code, 'RFC 6238 SHA-1 T=59');

        // Time = 1111111109, Counter = 37037036
        $code = Authenticator::getCode($secret, 8, 30, 'sha1', 1111111109);
        $this->assertEquals('07081804', $code, 'RFC 6238 SHA-1 T=1111111109');

        // Time = 1111111111, Counter = 37037037
        $code = Authenticator::getCode($secret, 8, 30, 'sha1', 1111111111);
        $this->assertEquals('14050471', $code, 'RFC 6238 SHA-1 T=1111111111');

        // Time = 1234567890
        $code = Authenticator::getCode($secret, 8, 30, 'sha1', 1234567890);
        $this->assertEquals('89005924', $code, 'RFC 6238 SHA-1 T=1234567890');

        // Time = 2000000000
        $code = Authenticator::getCode($secret, 8, 30, 'sha1', 2000000000);
        $this->assertEquals('69279037', $code, 'RFC 6238 SHA-1 T=2000000000');

        // Time = 20000000000
        $code = Authenticator::getCode($secret, 8, 30, 'sha1', 20000000000);
        $this->assertEquals('65353130', $code, 'RFC 6238 SHA-1 T=20000000000');
    }

    /**
     * RFC 6238 test vectors for SHA-256.
     * Note: SHA-256 uses a 32-byte key per the RFC.
     */
    public function testRfc6238Sha256Vectors(): void
    {
        $secret = Authenticator::base32Encode('12345678901234567890123456789012');

        $code = Authenticator::getCode($secret, 8, 30, 'sha256', 59);
        $this->assertEquals('46119246', $code, 'RFC 6238 SHA-256 T=59');

        $code = Authenticator::getCode($secret, 8, 30, 'sha256', 1111111109);
        $this->assertEquals('68084774', $code, 'RFC 6238 SHA-256 T=1111111109');

        $code = Authenticator::getCode($secret, 8, 30, 'sha256', 1111111111);
        $this->assertEquals('67062674', $code, 'RFC 6238 SHA-256 T=1111111111');

        $code = Authenticator::getCode($secret, 8, 30, 'sha256', 1234567890);
        $this->assertEquals('91819424', $code, 'RFC 6238 SHA-256 T=1234567890');

        $code = Authenticator::getCode($secret, 8, 30, 'sha256', 2000000000);
        $this->assertEquals('90698825', $code, 'RFC 6238 SHA-256 T=2000000000');

        $code = Authenticator::getCode($secret, 8, 30, 'sha256', 20000000000);
        $this->assertEquals('77737706', $code, 'RFC 6238 SHA-256 T=20000000000');
    }

    /**
     * RFC 6238 test vectors for SHA-512.
     * Note: SHA-512 uses a 64-byte key per the RFC.
     */
    public function testRfc6238Sha512Vectors(): void
    {
        $secret = Authenticator::base32Encode('1234567890123456789012345678901234567890123456789012345678901234');

        $code = Authenticator::getCode($secret, 8, 30, 'sha512', 59);
        $this->assertEquals('90693936', $code, 'RFC 6238 SHA-512 T=59');

        $code = Authenticator::getCode($secret, 8, 30, 'sha512', 1111111109);
        $this->assertEquals('25091201', $code, 'RFC 6238 SHA-512 T=1111111109');

        $code = Authenticator::getCode($secret, 8, 30, 'sha512', 1111111111);
        $this->assertEquals('99943326', $code, 'RFC 6238 SHA-512 T=1111111111');

        $code = Authenticator::getCode($secret, 8, 30, 'sha512', 1234567890);
        $this->assertEquals('93441116', $code, 'RFC 6238 SHA-512 T=1234567890');

        $code = Authenticator::getCode($secret, 8, 30, 'sha512', 2000000000);
        $this->assertEquals('38618901', $code, 'RFC 6238 SHA-512 T=2000000000');

        $code = Authenticator::getCode($secret, 8, 30, 'sha512', 20000000000);
        $this->assertEquals('47863826', $code, 'RFC 6238 SHA-512 T=20000000000');
    }

    // ==================== TOTP VERIFICATION ====================

    public function testVerifyCodeValid(): void
    {
        $timestamp = 1234567890;
        $code = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp);

        $this->assertTrue(
            Authenticator::verifyCode($this->rfcSecret, $code, 6, 30, 'sha1', 1, $timestamp)
        );
    }

    public function testVerifyCodeInvalid(): void
    {
        $this->assertFalse(
            Authenticator::verifyCode($this->rfcSecret, '000000', 6, 30, 'sha1', 1, 1234567890)
        );
    }

    public function testVerifyCodeWithinWindow(): void
    {
        $timestamp = 1234567890;
        // Generate code for one period ahead
        $futureCode = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp + 30);

        // Should be valid with window=1
        $this->assertTrue(
            Authenticator::verifyCode($this->rfcSecret, $futureCode, 6, 30, 'sha1', 1, $timestamp)
        );
    }

    public function testVerifyCodeOutsideWindow(): void
    {
        $timestamp = 1234567890;
        // Generate code for 3 periods ahead
        $farCode = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp + 90);

        // Should be invalid with window=1
        $this->assertFalse(
            Authenticator::verifyCode($this->rfcSecret, $farCode, 6, 30, 'sha1', 1, $timestamp)
        );
    }

    public function testVerifyCodeLargerWindow(): void
    {
        $timestamp = 1234567890;
        // Generate code for 3 periods ahead
        $farCode = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp + 90);

        // Should be valid with window=3
        $this->assertTrue(
            Authenticator::verifyCode($this->rfcSecret, $farCode, 6, 30, 'sha1', 3, $timestamp)
        );
    }

    public function testVerifyCodePastWindow(): void
    {
        $timestamp = 1234567890;
        // Generate code for one period behind
        $pastCode = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp - 30);

        // Past codes should also be valid within the window
        $this->assertTrue(
            Authenticator::verifyCode($this->rfcSecret, $pastCode, 6, 30, 'sha1', 1, $timestamp)
        );
    }

    public function testVerifyCodeTrimsWhitespace(): void
    {
        $timestamp = 1234567890;
        $code = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp);

        // Code with whitespace should still verify
        $this->assertTrue(
            Authenticator::verifyCode($this->rfcSecret, "  {$code}  ", 6, 30, 'sha1', 1, $timestamp)
        );
    }

    // ==================== HOTP CODE GENERATION ====================

    public function testGetHotpCodeReturnsCorrectLength(): void
    {
        $code = Authenticator::getHotpCode($this->rfcSecret, 0);

        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testGetHotpCodeDeterministic(): void
    {
        $code1 = Authenticator::getHotpCode($this->rfcSecret, 42);
        $code2 = Authenticator::getHotpCode($this->rfcSecret, 42);

        $this->assertEquals($code1, $code2);
    }

    public function testGetHotpCodeDifferentCounters(): void
    {
        $code1 = Authenticator::getHotpCode($this->rfcSecret, 0);
        $code2 = Authenticator::getHotpCode($this->rfcSecret, 1);

        $this->assertNotEquals($code1, $code2);
    }

    /**
     * RFC 4226 Appendix D — HOTP test values for SHA-1.
     */
    public function testRfc4226HotpVectors(): void
    {
        $secret = Authenticator::base32Encode('12345678901234567890');

        $expected = [
            0 => '755224',
            1 => '287082',
            2 => '359152',
            3 => '969429',
            4 => '338314',
            5 => '254676',
            6 => '287922',
            7 => '162583',
            8 => '399871',
            9 => '520489',
        ];

        foreach ($expected as $counter => $expectedCode) {
            $code = Authenticator::getHotpCode($secret, $counter, 6, 'sha1');
            $this->assertEquals(
                $expectedCode,
                $code,
                "RFC 4226 HOTP counter={$counter}"
            );
        }
    }

    // ==================== HOTP VERIFICATION ====================

    public function testVerifyHotpCodeValid(): void
    {
        $counter = 5;
        $code = Authenticator::getHotpCode($this->rfcSecret, $counter);

        $result = Authenticator::verifyHotpCode($this->rfcSecret, $code, $counter);

        $this->assertEquals($counter, $result);
    }

    public function testVerifyHotpCodeInvalid(): void
    {
        $result = Authenticator::verifyHotpCode($this->rfcSecret, '000000', 0);

        $this->assertFalse($result);
    }

    public function testVerifyHotpCodeLookAhead(): void
    {
        $counter = 5;
        // Generate code for counter + 3
        $code = Authenticator::getHotpCode($this->rfcSecret, $counter + 3);

        // Should find it within default window of 5
        $result = Authenticator::verifyHotpCode($this->rfcSecret, $code, $counter);

        $this->assertEquals($counter + 3, $result);
    }

    public function testVerifyHotpCodeOutsideWindow(): void
    {
        $counter = 5;
        // Generate code for counter + 10
        $code = Authenticator::getHotpCode($this->rfcSecret, $counter + 10);

        // Should not find it within window of 5
        $result = Authenticator::verifyHotpCode($this->rfcSecret, $code, $counter, 6, 'sha1', 5);

        $this->assertFalse($result);
    }

    // ==================== PROVISIONING URI ====================

    public function testGetProvisioningUriFormat(): void
    {
        $uri = Authenticator::getProvisioningUri(
            'JBSWY3DPEHPK3PXP',
            'user@example.com',
            'MyApp'
        );

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=MyApp', $uri);
        $this->assertStringContainsString('user%40example.com', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
    }

    public function testGetProvisioningUriCustomParams(): void
    {
        $uri = Authenticator::getProvisioningUri(
            'JBSWY3DPEHPK3PXP',
            'admin@corp.com',
            'Enterprise',
            8,
            60,
            'sha256'
        );

        $this->assertStringContainsString('digits=8', $uri);
        $this->assertStringContainsString('period=60', $uri);
        $this->assertStringContainsString('algorithm=SHA256', $uri);
        $this->assertStringContainsString('Enterprise', $uri);
    }

    public function testGetHotpProvisioningUriFormat(): void
    {
        $uri = Authenticator::getHotpProvisioningUri(
            'JBSWY3DPEHPK3PXP',
            'user@example.com',
            'MyApp',
            0
        );

        $this->assertStringStartsWith('otpauth://hotp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('counter=0', $uri);
    }

    public function testGetHotpProvisioningUriCustomCounter(): void
    {
        $uri = Authenticator::getHotpProvisioningUri(
            'JBSWY3DPEHPK3PXP',
            'user@example.com',
            'MyApp',
            42
        );

        $this->assertStringContainsString('counter=42', $uri);
    }

    // ==================== QR CODE ====================

    public function testGetQrCodeDataUri(): void
    {
        $provUri = 'otpauth://totp/MyApp:user@example.com?secret=JBSWY3DPEHPK3PXP';
        $qrUri = Authenticator::getQrCodeDataUri($provUri);

        $this->assertStringContainsString('chart.googleapis.com', $qrUri);
        $this->assertStringContainsString(urlencode($provUri), $qrUri);
    }

    public function testGetQrCodeDataUriCustomSize(): void
    {
        $provUri = 'otpauth://totp/MyApp:user@example.com?secret=JBSWY3DPEHPK3PXP';
        $qrUri = Authenticator::getQrCodeDataUri($provUri, 300);

        $this->assertStringContainsString('300x300', $qrUri);
    }

    // ==================== BACKUP CODES ====================

    public function testGenerateBackupCodesDefaultCount(): void
    {
        $codes = Authenticator::generateBackupCodes();

        $this->assertCount(8, $codes);
    }

    public function testGenerateBackupCodesCustomCount(): void
    {
        $codes = Authenticator::generateBackupCodes(12);

        $this->assertCount(12, $codes);
    }

    public function testGenerateBackupCodesDefaultLength(): void
    {
        $codes = Authenticator::generateBackupCodes();

        foreach ($codes as $code) {
            $this->assertEquals(8, strlen($code));
        }
    }

    public function testGenerateBackupCodesCustomLength(): void
    {
        $codes = Authenticator::generateBackupCodes(5, 10);

        foreach ($codes as $code) {
            $this->assertEquals(10, strlen($code));
        }
    }

    public function testGenerateBackupCodesAlphanumeric(): void
    {
        $codes = Authenticator::generateBackupCodes();

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-Z]+$/', $code);
        }
    }

    public function testGenerateBackupCodesUnique(): void
    {
        $codes = Authenticator::generateBackupCodes(20);

        // With 36^8 possible codes, collisions are extremely unlikely
        $this->assertCount(20, array_unique($codes));
    }

    // ==================== PARAMETER VALIDATION ====================

    public function testGetCodeInvalidDigitsLowThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getCode($this->rfcSecret, 5);
    }

    public function testGetCodeInvalidDigitsHighThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getCode($this->rfcSecret, 9);
    }

    public function testGetCodeInvalidPeriodThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getCode($this->rfcSecret, 6, 0);
    }

    public function testGetCodeInvalidAlgorithmThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getCode($this->rfcSecret, 6, 30, 'md5');
    }

    public function testVerifyCodeInvalidAlgorithmThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::verifyCode($this->rfcSecret, '123456', 6, 30, 'md5');
    }

    public function testGetHotpCodeInvalidDigitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getHotpCode($this->rfcSecret, 0, 5);
    }

    public function testGetHotpCodeInvalidAlgorithmThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getHotpCode($this->rfcSecret, 0, 6, 'md5');
    }

    public function testVerifyHotpCodeInvalidDigitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::verifyHotpCode($this->rfcSecret, '123456', 0, 5);
    }

    public function testProvisioningUriInvalidDigitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getProvisioningUri('SECRET', 'user', 'App', 5);
    }

    public function testHotpProvisioningUriInvalidAlgorithmThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Authenticator::getHotpProvisioningUri('SECRET', 'user', 'App', 0, 6, 'md5');
    }

    // ==================== INTEGRATION / END-TO-END ====================

    public function testFullWorkflow(): void
    {
        // 1. Generate secret
        $secret = Authenticator::generateSecret();
        $this->assertNotEmpty($secret);

        // 2. Generate provisioning URI
        $uri = Authenticator::getProvisioningUri($secret, 'test@example.com', 'TestApp');
        $this->assertStringStartsWith('otpauth://totp/', $uri);

        // 3. Generate code at a known time
        $timestamp = 1700000000;
        $code = Authenticator::getCode($secret, 6, 30, 'sha1', $timestamp);
        $this->assertEquals(6, strlen($code));

        // 4. Verify the code
        $this->assertTrue(
            Authenticator::verifyCode($secret, $code, 6, 30, 'sha1', 1, $timestamp)
        );

        // 5. Generate backup codes
        $backups = Authenticator::generateBackupCodes();
        $this->assertCount(8, $backups);
    }

    public function testDifferentAlgorithmsGenerateDifferentCodes(): void
    {
        $timestamp = 1234567890;

        $sha1 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha1', $timestamp);
        $sha256 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha256', $timestamp);
        $sha512 = Authenticator::getCode($this->rfcSecret, 6, 30, 'sha512', $timestamp);

        // Different algorithms should produce different codes (with overwhelming probability)
        $codes = [$sha1, $sha256, $sha512];
        $this->assertGreaterThan(1, count(array_unique($codes)));
    }
}
