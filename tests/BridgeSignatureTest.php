<?php

/**
 * Unit tests for Razy\BridgeSignature — cross-distributor bridge HMAC (audit §S3).
 *
 * This file is part of Razy v1.0.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\BridgeSignature;

#[CoversClass(BridgeSignature::class)]
class BridgeSignatureTest extends TestCase
{
    private const SECRET = 'test-secret-kangaroo-42';

    public function testCanonicalPayloadIsOrderIndependent(): void
    {
        $a = BridgeSignature::canonicalPayload('siteA', 'core/auth', 'getUser', ['z' => 1, 'a' => 2, 'm' => ['y' => 1, 'b' => 2]], 1700000000, 'n1');
        $b = BridgeSignature::canonicalPayload('siteA', 'core/auth', 'getUser', ['a' => 2, 'm' => ['b' => 2, 'y' => 1], 'z' => 1], 1700000000, 'n1');

        $this->assertSame($a, $b);
    }

    public function testCanonicalPayloadBindsEveryElement(): void
    {
        $base = BridgeSignature::canonicalPayload('siteA', 'mod', 'cmd', ['k' => 'v'], 1700000000, 'n1');

        $mutants = [
            'source' => BridgeSignature::canonicalPayload('siteB', 'mod', 'cmd', ['k' => 'v'], 1700000000, 'n1'),
            'module' => BridgeSignature::canonicalPayload('siteA', 'mod2', 'cmd', ['k' => 'v'], 1700000000, 'n1'),
            'command' => BridgeSignature::canonicalPayload('siteA', 'mod', 'cmd2', ['k' => 'v'], 1700000000, 'n1'),
            'args' => BridgeSignature::canonicalPayload('siteA', 'mod', 'cmd', ['k' => 'CHANGED'], 1700000000, 'n1'),
            'ts' => BridgeSignature::canonicalPayload('siteA', 'mod', 'cmd', ['k' => 'v'], 1700000001, 'n1'),
            'nonce' => BridgeSignature::canonicalPayload('siteA', 'mod', 'cmd', ['k' => 'v'], 1700000000, 'n2'),
        ];

        foreach ($mutants as $label => $mutant) {
            $this->assertNotSame($base, $mutant, "canonical form must bind '{$label}'");
        }
    }

    public function testSignVerifyRoundTrip(): void
    {
        $now = 1700000000;
        $sig = BridgeSignature::sign(self::SECRET, 'siteA', 'core/pay', 'refund', ['order' => 7], $now, 'nonce-1');

        $this->assertTrue(BridgeSignature::verify(self::SECRET, 'siteA', 'core/pay', 'refund', ['order' => 7], $now, 'nonce-1', $sig, 60, $now));
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $now = 1700000000;
        $sig = BridgeSignature::sign('other-secret', 'siteA', 'm', 'c', [], $now, 'n');

        $this->assertFalse(BridgeSignature::verify(self::SECRET, 'siteA', 'm', 'c', [], $now, 'n', $sig, 60, $now));
    }

    public function testVerifyRejectsTamperedArgs(): void
    {
        $now = 1700000000;
        $sig = BridgeSignature::sign(self::SECRET, 'siteA', 'm', 'c', ['amount' => 10], $now, 'n');

        $this->assertFalse(BridgeSignature::verify(self::SECRET, 'siteA', 'm', 'c', ['amount' => 999999], $now, 'n', $sig, 60, $now));
    }

    public function testVerifyRejectsExpiredAndFutureTimestamps(): void
    {
        $now = 1700000000;
        $old = $now - 61;
        $future = $now + 61;

        $sigOld = BridgeSignature::sign(self::SECRET, 's', 'm', 'c', [], $old, 'n');
        $sigFuture = BridgeSignature::sign(self::SECRET, 's', 'm', 'c', [], $future, 'n');

        $this->assertFalse(BridgeSignature::verify(self::SECRET, 's', 'm', 'c', [], $old, 'n', $sigOld, 60, $now), 'expired ts must fail');
        $this->assertFalse(BridgeSignature::verify(self::SECRET, 's', 'm', 'c', [], $future, 'n', $sigFuture, 60, $now), 'far-future ts must fail');

        $edge = $now - 60;
        $sigEdge = BridgeSignature::sign(self::SECRET, 's', 'm', 'c', [], $edge, 'n');
        $this->assertTrue(BridgeSignature::verify(self::SECRET, 's', 'm', 'c', [], $edge, 'n', $sigEdge, 60, $now), 'boundary ts == tolerance must pass');
    }

    public function testVerifyRejectsEmptySecretOrNonce(): void
    {
        $now = 1700000000;
        $sig = BridgeSignature::sign(self::SECRET, 's', 'm', 'c', [], $now, 'n');

        $this->assertFalse(BridgeSignature::verify('', 's', 'm', 'c', [], $now, 'n', $sig, 60, $now));
        $this->assertFalse(BridgeSignature::verify(self::SECRET, 's', 'm', 'c', [], $now, '', $sig, 60, $now));
    }

    public function testSignedPayloadEnvelopeRoundTrip(): void
    {
        $payload = BridgeSignature::signedPayload(self::SECRET, 'siteA', 'core/pay', 'refund', ['order' => 7]);

        $this->assertArrayHasKey('sig', $payload);
        $this->assertArrayHasKey('nonce', $payload);
        $this->assertTrue(BridgeSignature::verifyPayload($payload, self::SECRET));
    }

    public function testVerifyPayloadRejectsTamperingAndMissingFields(): void
    {
        $payload = BridgeSignature::signedPayload(self::SECRET, 'siteA', 'm', 'c', ['a' => 1]);

        $tampered = $payload;
        $tampered['args'] = ['a' => 2];
        $this->assertFalse(BridgeSignature::verifyPayload($tampered, self::SECRET));

        // Attacker swap: forge a different source claiming the same signature.
        $swapped = $payload;
        $swapped['source'] = 'trusted-site';
        $this->assertFalse(BridgeSignature::verifyPayload($swapped, self::SECRET), 'source swap must fail');

        foreach (['source', 'module', 'command', 'ts', 'nonce', 'sig'] as $field) {
            $missing = $payload;
            unset($missing[$field]);
            $this->assertFalse(BridgeSignature::verifyPayload($missing, self::SECRET), "missing '{$field}' must fail");
        }
    }

    public function testVerifyPayloadRejectsBadTypes(): void
    {
        $payload = BridgeSignature::signedPayload(self::SECRET, 's', 'm', 'c', []);

        $payload['ts'] = '1700000000'; // string instead of int
        $this->assertFalse(BridgeSignature::verifyPayload($payload, self::SECRET));
    }

    public function testSecretFromEnvReadsSuperglobal(): void
    {
        $_ENV['RAZY_BRIDGE_SECRET'] = 'env-kangaroo';
        $this->assertSame('env-kangaroo', BridgeSignature::secretFromEnv());
        $this->assertTrue(BridgeSignature::isEnabled());

        unset($_ENV['RAZY_BRIDGE_SECRET']);
        // Note: framework env() takes precedence when loaded; in phpunit it is absent,
        // so the superglobal fallback path is what we just exercised.
        $this->assertSame('', BridgeSignature::secretFromEnv());
        $this->assertFalse(BridgeSignature::isEnabled());
    }
}
