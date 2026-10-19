<?php

/**
 * RZ-04 — trusted proxies, Request facts, canonical origins, RAZY_DOMAINS.
 *
 * Before RZ-04: NetworkUtil::isSsl() trusted X-Forwarded-Proto from any
 * client and compared the string SERVER_PORT with int 443 (never true);
 * ipInRange() was IPv4-only; there was no way to learn the real client IP,
 * scheme or host behind a proxy; absolute URLs always came from the request
 * Host; and sites.inc.php had no helper to take its domain list from the env.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Config\DomainList;
use Razy\Exception\ConfigurationException;
use Razy\Http\Request;
use Razy\Http\TrustedProxies;
use Razy\Util\NetworkUtil;

#[CoversClass(Request::class)]
#[CoversClass(TrustedProxies::class)]
#[CoversClass(DomainList::class)]
#[CoversClass(NetworkUtil::class)]
class TrustedProxiesTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        TrustedProxies::configure(null);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        TrustedProxies::configure(null);
    }

    // -- ipInRange --------------------------------------------------------

    public function testIpInRangeSupportsIpv6(): void
    {
        $this->assertTrue(NetworkUtil::ipInRange('2001:db8::1', '2001:db8::/32'));
        $this->assertFalse(NetworkUtil::ipInRange('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue(NetworkUtil::ipInRange('::1', '::1'));
        $this->assertTrue(NetworkUtil::ipInRange('fd00::abcd', 'fc00::/7'));
        $this->assertFalse(NetworkUtil::ipInRange('10.0.0.1', '2001:db8::/32'), 'families never match');
    }

    public function testIpInRangeIpv4Unchanged(): void
    {
        $this->assertTrue(NetworkUtil::ipInRange('10.1.2.3', '10.0.0.0/8'));
        $this->assertFalse(NetworkUtil::ipInRange('11.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(NetworkUtil::ipInRange('192.168.1.5', '192.168.1.5'));
        $this->assertFalse(NetworkUtil::ipInRange('bogus', '10.0.0.0/8'));
    }

    // -- isSsl port comparison ----------------------------------------------

    public function testIsSslTreatsStringPort443AsSecure(): void
    {
        $_SERVER = ['SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.5'];
        $this->assertTrue(NetworkUtil::isSsl(), 'SERVER_PORT is a string in $_SERVER');
    }

    public function testIsSslIgnoresSpoofedProtoWhenProxiesConfigured(): void
    {
        TrustedProxies::configure(TrustedProxies::fromList('10.0.0.0/8'));
        $_SERVER = ['SERVER_PORT' => '80', 'REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertFalse(NetworkUtil::isSsl());

        $_SERVER['REMOTE_ADDR'] = '10.1.1.1';
        $this->assertTrue(NetworkUtil::isSsl());
    }

    // -- TrustedProxies -------------------------------------------------------

    public function testTrustedProxyListParsing(): void
    {
        $p = TrustedProxies::fromList('10.0.0.0/8, 2001:db8::/32 ,127.0.0.1');
        $this->assertTrue($p->isConfigured());
        $this->assertTrue($p->isTrusted('10.9.9.9'));
        $this->assertTrue($p->isTrusted('2001:db8::5'));
        $this->assertTrue($p->isTrusted('127.0.0.1'));
        $this->assertFalse($p->isTrusted('203.0.113.1'));
        $this->assertFalse(TrustedProxies::fromList('')->isConfigured());
    }

    public function testInvalidTrustedProxyIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        TrustedProxies::fromList('10.0.0.0/33');
    }

    public function testEnvOverridesConfig(): void
    {
        $previous = \getenv('RAZY_TRUSTED_PROXIES');
        \putenv('RAZY_TRUSTED_PROXIES=172.16.0.0/12');

        try {
            $p = TrustedProxies::fromConfig(['trusted_proxies' => ['10.0.0.0/8']]);
            $this->assertTrue($p->isTrusted('172.20.0.1'));
            $this->assertFalse($p->isTrusted('10.0.0.1'));
        } finally {
            \putenv(false === $previous ? 'RAZY_TRUSTED_PROXIES' : 'RAZY_TRUSTED_PROXIES=' . $previous);
        }
        $this->assertTrue(TrustedProxies::fromConfig(['trusted_proxies' => ['10.0.0.0/8']])->isTrusted('10.0.0.1'));
    }

    // -- Request ----------------------------------------------------------------

    public function testDirectRequestIgnoresForwardedHeaders(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '203.0.113.7',
            'SERVER_NAME' => 'app.example.com',
            'SERVER_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ], TrustedProxies::fromList('10.0.0.0/8'));

        $this->assertSame('http', $r->scheme());
        $this->assertSame('app.example.com', $r->host());
        $this->assertSame(80, $r->port());
        $this->assertSame('203.0.113.7', $r->clientIp());
        $this->assertSame('http://app.example.com', $r->origin());
    }

    public function testTrustedProxySuppliesSchemeHostPortAndClient(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'SERVER_NAME' => 'web',
            'SERVER_PORT' => '8188',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'App.Example.com',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 10.0.0.9',
        ], TrustedProxies::fromList('10.0.0.0/8'));

        $this->assertTrue($r->isFromTrustedProxy());
        $this->assertSame('https', $r->scheme());
        $this->assertTrue($r->isSecure());
        $this->assertSame('app.example.com', $r->host());
        $this->assertSame(443, $r->port());
        $this->assertSame('198.51.100.9', $r->clientIp(), 'rightmost untrusted hop is the client');
        $this->assertSame('https://app.example.com', $r->origin());
    }

    public function testSpoofedLeftmostForwardedForIsNotTheClient(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9',
        ], TrustedProxies::fromList('10.0.0.0/8'));

        $this->assertSame('198.51.100.9', $r->clientIp());
    }

    public function testTrustAllOnlyVouchesForThePeer(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.9',
        ], TrustedProxies::fromList('*'));

        $this->assertSame('198.51.100.9', $r->clientIp(), 'the client-supplied leftmost hop is never the client');
    }

    public function testProxyWithoutForwardedPortUsesPublicDefault(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'SERVER_NAME' => 'app.example.com',
            'HTTP_HOST' => 'app.example.com',
            'SERVER_PORT' => '8188',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], TrustedProxies::fromList('10.0.0.0/8'));

        $this->assertSame(443, $r->port(), 'the backend SERVER_PORT is not the public port');
        $this->assertSame('https://app.example.com', $r->origin());

        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_HOST' => 'app.example.com:8443',
            'SERVER_PORT' => '8188',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ], TrustedProxies::fromList('10.0.0.0/8'));
        $this->assertSame(8443, $r->port());
    }

    public function testOutOfRangeForwardedHostPortIsIgnored(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'app.example.com:99999',
        ], TrustedProxies::fromList('10.0.0.0/8'));

        $this->assertSame(443, $r->port());
    }

    public function testIpv6ProxyAndForwardedPort(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => 'fd00::2',
            'SERVER_PORT' => '80',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'app.example.com',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_FOR' => '2001:db8::77',
        ], TrustedProxies::fromList('fc00::/7'));

        $this->assertSame(8443, $r->port());
        $this->assertSame('2001:db8::77', $r->clientIp());
        $this->assertSame('https://app.example.com:8443', $r->origin());
    }

    public function testInvalidForwardedHostFallsBack(): void
    {
        $r = new Request([
            'REMOTE_ADDR' => '10.0.0.2',
            'SERVER_NAME' => 'app.example.com',
            'SERVER_PORT' => '80',
            'HTTP_X_FORWARDED_HOST' => 'bad host/<x>',
        ], TrustedProxies::fromList('10.0.0.0/8'));

        $this->assertSame('app.example.com', $r->host());
    }

    public function testDirectHttpsOnStringPort(): void
    {
        $r = new Request(['SERVER_NAME' => 'a.test', 'SERVER_PORT' => '443', 'REMOTE_ADDR' => '203.0.113.1']);
        $this->assertSame('https', $r->scheme());
        $this->assertSame('https://a.test', $r->origin(), 'default port is omitted');
    }

    // -- canonical origins --------------------------------------------------------

    public function testOriginNormalisation(): void
    {
        $this->assertSame('https://app.example.com', Request::normalizeOrigin('HTTPS://App.Example.com/'));
        $this->assertSame('http://localhost:8193', Request::normalizeOrigin('http://localhost:8193'));
        $this->assertSame('https://a.test', Request::normalizeOrigin('https://a.test:443'));
        $this->assertNull(Request::normalizeOrigin('ftp://a.test'));
        $this->assertNull(Request::normalizeOrigin('https://a.test/path'));
        $this->assertNull(Request::normalizeOrigin('https://user@a.test'));
        $this->assertNull(Request::normalizeOrigin('a.test'));
    }

    public function testDistributorUsesCanonicalOrigin(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        $this->assertStringContainsString('Request::normalizeOrigin(', $src);
        $this->assertStringContainsString('function getCanonicalOrigin()', $src);
    }

    public function testBootstrapConfiguresProxiesAndOmitsStandardPorts(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/bootstrap.inc.php');
        $this->assertStringContainsString('TrustedProxies::configure(TrustedProxies::fromConfig(', $src);
        $this->assertStringContainsString("\\in_array(PORT, [80, 443], true) ? '' : ':' . PORT", $src);
        $this->assertStringNotContainsString("PORT != '80'", $src);
    }

    // -- RAZY_DOMAINS ---------------------------------------------------------------

    public function testDomainListParsing(): void
    {
        $sites = ['/' => 'main'];
        $domains = DomainList::parse('app.example.com, *.example.com localhost:8193,docs.example.com=docs@v2', $sites);

        $this->assertSame([
            'app.example.com' => ['/' => 'main'],
            '*.example.com' => ['/' => 'main'],
            'localhost:8193' => ['/' => 'main'],
            'docs.example.com' => ['/' => 'docs@v2'],
        ], $domains);
    }

    public function testDomainListFromEnvWithFallback(): void
    {
        $previous = \getenv('RAZY_DOMAINS');
        \putenv('RAZY_DOMAINS');

        try {
            $this->assertSame(['localhost' => ['/' => 'main']], DomainList::fromEnv(['/' => 'main'], 'RAZY_DOMAINS', ['localhost']));
            \putenv('RAZY_DOMAINS=a.test');
            $this->assertSame(['a.test' => ['/' => 'main']], DomainList::fromEnv(['/' => 'main'], 'RAZY_DOMAINS', ['localhost']));
        } finally {
            \putenv(false === $previous ? 'RAZY_DOMAINS' : 'RAZY_DOMAINS=' . $previous);
        }
    }

    public function testDomainListRejectsGarbage(): void
    {
        $this->expectException(ConfigurationException::class);
        DomainList::parse('good.test,bad/host', ['/' => 'main']);
    }
}
