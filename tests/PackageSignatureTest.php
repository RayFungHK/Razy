<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\PackageIntegrityException;
use Razy\PackageSignature;

/**
 * Publisher-authenticity primitives (OFFICIAL-REPO-INSTALL.md §8 S5, gap G4):
 * Ed25519 detached signatures over EXACT bytes + pinned-key resolution.
 * Pure unit — no network, no registry; key material generated per test.
 */
#[CoversClass(PackageSignature::class)]
class PackageSignatureTest extends TestCase
{
    /** @var list<string> env names touched, restored in tearDown */
    private array $touchedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->touchedEnv as $name) {
            \putenv($name);
        }
        $this->touchedEnv = [];
    }

    public function testGenerateProducesCorrectHexShapes(): void
    {
        $pair = PackageSignature::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pair['public']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $pair['secret']);
    }

    public function testSignVerifyRoundTripOverExactBytes(): void
    {
        $pair = PackageSignature::generate();
        $payload = "{\"dashboard\": {\"latest\": \"1.0.0\"}}\nwith newlines and éæ";

        $signature = PackageSignature::sign($payload, $pair['secret']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{128}$/', $signature);
        $this->assertTrue(PackageSignature::verify($payload, $signature, $pair['public']));
    }

    public function testOneByteTamperFailsVerification(): void
    {
        $pair = PackageSignature::generate();
        $payload = '{"razymod/queue-admin":{"latest":"1.1.0"}}';
        $signature = PackageSignature::sign($payload, $pair['secret']);

        $tampered = \str_replace('1.1.0', '1.2.0', $payload);

        $this->assertFalse(PackageSignature::verify($tampered, $signature, $pair['public']));
    }

    public function testSignatureFlipFailsVerification(): void
    {
        $pair = PackageSignature::generate();
        $payload = 'index bytes';
        $signature = PackageSignature::sign($payload, $pair['secret']);

        // flip one hex char — still syntactically hex, must fail cryptographically
        $flipped = ($signature[0] === 'a' ? 'b' : 'a') . \substr($signature, 1);

        $this->assertFalse(PackageSignature::verify($payload, $flipped, $pair['public']));
    }

    public function testWrongKeyFailsVerification(): void
    {
        $pairA = PackageSignature::generate();
        $pairB = PackageSignature::generate();

        $signature = PackageSignature::sign('index bytes', $pairA['secret']);

        $this->assertFalse(PackageSignature::verify('index bytes', $signature, $pairB['public']));
    }

    public function testMalformedKeyMaterialFailsClosed(): void
    {
        $pair = PackageSignature::generate();
        $signature = PackageSignature::sign('x', $pair['secret']);

        $this->expectException(PackageIntegrityException::class);
        PackageSignature::verify('x', $signature, 'not-hex!!');
    }

    public function testShortKeyFailsClosed(): void
    {
        $pair = PackageSignature::generate();
        $signature = PackageSignature::sign('x', $pair['secret']);

        $this->expectException(PackageIntegrityException::class);
        PackageSignature::verify('x', $signature, \substr($pair['public'], 0, 63));
    }

    public function testNonHexSignatureFailsClosed(): void
    {
        $pair = PackageSignature::generate();

        $this->expectException(PackageIntegrityException::class);
        PackageSignature::verify('x', \str_repeat('z', 128), $pair['public']);
    }

    // ── key resolution precedence ────────────────────────────────────

    public function testExplicitArgumentBeatsEnvAndIsAcceptedAsLiteralHex(): void
    {
        $pair = PackageSignature::generate();
        $this->setEnv(PackageSignature::ENV_PUBKEY, \str_repeat('0', 64));

        $this->assertSame($pair['public'], PackageSignature::resolvePublicKey($pair['public']));
    }

    public function testEnvAcceptsPathToFileAndLiteralHex(): void
    {
        $pair = PackageSignature::generate();

        $file = \tempnam(\sys_get_temp_dir(), 'razypub');
        \file_put_contents($file, $pair['public'] . "\n");

        $this->setEnv(PackageSignature::ENV_PUBKEY, $file);
        $this->assertSame($pair['public'], PackageSignature::resolvePublicKey());

        $this->setEnv(PackageSignature::ENV_PUBKEY, $pair['public']);
        $this->assertSame($pair['public'], PackageSignature::resolvePublicKey());

        @\unlink($file);
    }

    public function testUnreadableKeyReferenceFailsLoud(): void
    {
        $this->expectException(PackageIntegrityException::class);
        PackageSignature::resolvePublicKey('/definitely/not/a/key/nor/hex');
    }

    public function testSigningSecretResolutionPathAndEnvAndNull(): void
    {
        $pair = PackageSignature::generate();

        $file = \tempnam(\sys_get_temp_dir(), 'razykey');
        \file_put_contents($file, $pair['secret'] . "\n");

        $this->assertSame($pair['secret'], PackageSignature::resolveSigningSecret($file));

        @\unlink($file);
        \putenv(PackageSignature::ENV_SIGNKEY);
        $this->assertNull(PackageSignature::resolveSigningSecret(null));
    }

    public function testSigningSecretNeverReadsPublicKeyEnv(): void
    {
        $pair = PackageSignature::generate();
        // pub env set, sign env absent ⇒ signing resolution must NOT borrow it
        $this->setEnv(PackageSignature::ENV_PUBKEY, $pair['public']);
        \putenv(PackageSignature::ENV_SIGNKEY);

        $this->assertNull(PackageSignature::resolveSigningSecret(null));
    }

    // ── wiring pin (source-ordering, same technique as RegistryFetchPolicyTest) ──

    public function testFetchIndexVerifiesBeforeJsonDecode(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/RepositoryManager.php');

        $verify = \strpos($source, 'PackageSignature::verify($response');
        $decode = \strpos($source, 'json_decode($response');

        $this->assertIsInt($verify, 'fetchIndex must call PackageSignature::verify on the raw response');
        $this->assertIsInt($decode);
        $this->assertLessThan($decode, $verify, 'signature verification must happen BEFORE json_decode trusts the bytes');

        // invalid ⇒ refused (null) with recorded trust state, never parsed
        $this->assertStringContainsString('self::TRUST_INVALID', $source);
        $this->assertMatchesRegularExpression('/TRUST_INVALID;\s*\$this->notify[^;]+;\s*\n\s*return null;/', $source, 'invalid signature must refuse the index, not decode it');
    }

    private function setEnv(string $name, string $value): void
    {
        $this->touchedEnv[] = $name;
        \putenv($name . '=' . $value);
    }
}
