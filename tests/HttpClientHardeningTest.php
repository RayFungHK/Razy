<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Http\ClientInterface;
use Razy\Http\HttpClient;
use Razy\Http\HttpResponse;
use Razy\Http\HttpTransportException;
use RuntimeException;

/**
 * S1 HTTP-hardening additions on top of the existing HttpClientTest net:
 * HTTPS-only gate + escape hatch, mandatory timeouts, MAXREDIRS=3, the
 * ClientInterface seam, Content-Type-aware body parsing, and the exact-status
 * redirect helper. No network — the gate and pure methods are exercised
 * directly, exactly why ClientInterface exists (inject, don't hit the wire).
 */
#[CoversClass(HttpClient::class)]
#[CoversClass(HttpResponse::class)]
#[CoversClass(HttpTransportException::class)]
class HttpClientHardeningTest extends TestCase
{
    public function testHttpClientImplementsClientInterface(): void
    {
        $this->assertInstanceOf(ClientInterface::class, HttpClient::create());
    }

    public function testTimeoutsAreMandatory(): void
    {
        // cURL reads 0 as "wait forever"; the hardened client floors at MIN_TIMEOUT
        $this->assertSame(HttpClient::MIN_TIMEOUT, HttpClient::create()->timeout(0)->getTimeout());
        $this->assertSame(HttpClient::MIN_TIMEOUT, HttpClient::create()->timeout(-5)->getTimeout());
        $this->assertSame(HttpClient::MIN_TIMEOUT, HttpClient::create()->connectTimeout(0)->getConnectTimeout());
        // a real value passes through untouched
        $this->assertSame(42, HttpClient::create()->timeout(42)->getTimeout());
    }

    public function testMaxRedirectsTightened(): void
    {
        $this->assertSame(3, HttpClient::MAX_REDIRECTS);
    }

    public function testPlainHttpRejectedByDefault(): void
    {
        // defensively blank the escape for the duration (S5 save/restore idiom)
        $was = \getenv('RAZY_ALLOW_INSECURE_TRANSPORT');
        \putenv('RAZY_ALLOW_INSECURE_TRANSPORT=');

        try {
            $client = new GateProbe();

            $this->expectException(HttpTransportException::class);
            $this->expectExceptionMessage('Insecure transport rejected');
            $client->probe('http://example.com/x');
        } finally {
            \putenv($was === false ? 'RAZY_ALLOW_INSECURE_TRANSPORT=' : "RAZY_ALLOW_INSECURE_TRANSPORT={$was}");
        }
    }

    public function testPlainHttpAllowedWithEscapeHatch(): void
    {
        $client = (new GateProbe())->allowInsecureTransport();

        // no throw — the opt-in widened the gate for this client only
        $client->probe('http://example.com/x');
        $this->assertTrue($client->getAllowInsecureTransport());
        $this->assertTrue($client->probeThrowsNothing('https://example.com/x'));
    }

    public function testHttpsAlwaysPasses(): void
    {
        (new GateProbe())->probe('https://example.com/x');
        $this->addToAssertionCount(1);
    }

    public function testTransportExceptionCarriesErrno(): void
    {
        $e = new HttpTransportException('boom', 7);
        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame(7, $e->getCurlErrno());
        $this->assertSame(7, $e->getCode());
    }

    public function testDataParsesFormUrlEncoded(): void
    {
        // the exact shape GitHub's token endpoint answers with
        $res = new HttpResponse(200, 'access_token=abc123&scope=read%3Auser&token_type=bearer', [
            'content-type' => 'application/x-www-form-urlencoded',
        ]);
        $this->assertSame(['access_token' => 'abc123', 'scope' => 'read:user', 'token_type' => 'bearer'], $res->data());
    }

    public function testDataParsesJson(): void
    {
        $res = new HttpResponse(200, '{"a":1}', ['content-type' => 'application/json']);
        $this->assertSame(['a' => 1], $res->data());
    }

    public function testDataNullForUnknownType(): void
    {
        $res = new HttpResponse(200, 'plain text', ['content-type' => 'text/plain']);
        $this->assertNull($res->data());
    }

    public function testRedirectExactStatusHelper(): void
    {
        $res = new HttpResponse(302, '');
        $this->assertTrue($res->redirect());      // backwards-compatible 3xx family
        $this->assertTrue($res->redirect(302));   // exact match (dossier: redirect(302))
        $this->assertFalse($res->redirect(301));  // different 3xx does not match
    }

    public function testWithAcceptOverridesDefault(): void
    {
        $client = HttpClient::create()->withAccept('application/x-www-form-urlencoded');
        $this->assertSame('application/x-www-form-urlencoded', $client->getHeaders()['accept']);
    }
}

/**
 * Test double: expose the protected HTTPS gate so it can be driven without a
 * real request (the whole point of a narrow, injectable transport seam).
 */
class GateProbe extends HttpClient
{
    /**
     * @throws HttpTransportException
     */
    public function probe(string $url): void
    {
        $this->assertSecureTransport($url);
    }

    public function probeThrowsNothing(string $url): bool
    {
        try {
            $this->assertSecureTransport($url);

            return true;
        } catch (HttpTransportException) {
            return false;
        }
    }
}
