<?php

/**
 * RZ-18: per-request URL state for persistent workers.
 *
 * The bootstrap `define()`d HOSTNAME, SITE_URL_ROOT, RAZY_URL_ROOT, URL_QUERY…
 * once per process, so a FrankenPHP worker built every link from its boot
 * request (host 'UNKNOWN'). RequestUrl holds the facts per request; the
 * worker loop replaces it before each request and the framework reads it.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor;
use Razy\Domain;
use Razy\Http\RequestUrl;
use Razy\Http\TrustedProxies;
use Razy\Util\PathUtil;
use Razy\XHR;
use ReflectionProperty;

#[CoversClass(RequestUrl::class)]
final class RequestUrlTest extends TestCase
{
    private const DIST = 'rz18_url_fixture';

    protected function tearDown(): void
    {
        RequestUrl::setCurrent(null);
        TrustedProxies::configure(null);
        $this->removeDir(PathUtil::append(SITES_FOLDER, self::DIST));
    }

    public function testDirectRequest(): void
    {
        $url = RequestUrl::fromServer([
            'SERVER_NAME' => 'shop.example.com',
            'SERVER_PORT' => '8080',
            'REQUEST_URI' => '/cart/view?x=1',
        ]);

        $this->assertSame('http', $url->scheme);
        $this->assertSame('shop.example.com', $url->host);
        $this->assertSame(8080, $url->port);
        $this->assertSame('http://shop.example.com:8080', $url->siteUrlRoot());
        $this->assertSame('http://shop.example.com:8080', $url->razyUrlRoot());
        $this->assertSame('http://shop.example.com:8080/cart/view', $url->scriptUrl());
        $this->assertSame('/cart/view?x=1', $url->fullUrlQuery());
        $this->assertSame('/cart/view/', $url->urlQuery());
    }

    public function testStandardPortsAreOmitted(): void
    {
        $https = RequestUrl::fromServer(['SERVER_NAME' => 'a.test', 'SERVER_PORT' => '443', 'HTTPS' => 'on', 'REQUEST_URI' => '/']);
        $this->assertSame('https://a.test', $https->siteUrlRoot());

        $http = RequestUrl::fromServer(['SERVER_NAME' => 'a.test', 'SERVER_PORT' => '80', 'REQUEST_URI' => '/']);
        $this->assertSame('http://a.test', $http->siteUrlRoot());
    }

    public function testTrustedProxySuppliesPublicHostAndPort(): void
    {
        TrustedProxies::configure(TrustedProxies::fromList('10.0.0.0/8'));
        $url = RequestUrl::fromServer([
            'REMOTE_ADDR' => '10.1.2.3',
            'SERVER_NAME' => 'backend',
            'SERVER_PORT' => '8080',
            'HTTP_X_FORWARDED_HOST' => 'public.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REQUEST_URI' => '/',
        ]);

        $this->assertSame('https://public.example.com', $url->siteUrlRoot());
    }

    public function testHostIsSanitized(): void
    {
        $url = RequestUrl::fromServer(['SERVER_NAME' => 'evil.test"><script>', 'REQUEST_URI' => '/']);
        $this->assertSame('evil.testscript', $url->host);
        $this->assertSame('UNKNOWN', RequestUrl::fromServer([])->host, 'no request: the boot sentinel');
    }

    public function testRelativeRoot(): void
    {
        $this->assertSame('/abc', RequestUrl::relativeRootFrom(['DOCUMENT_ROOT' => '/var/www/'], '/var/www/abc'));
        $this->assertSame('', RequestUrl::relativeRootFrom(['DOCUMENT_ROOT' => '/var/www'], '/var/www'));
        $this->assertSame('/sub', RequestUrl::relativeRootFrom(['DOCUMENT_ROOT' => '/elsewhere', 'SCRIPT_NAME' => '/sub/index.php'], '/srv/app'));
        $this->assertSame('', RequestUrl::relativeRootFrom(['SCRIPT_NAME' => '/index.php'], '/srv/app'));
        $this->assertSame('', RequestUrl::relativeRootFrom(['DOCUMENT_ROOT' => '/var/www'], '/var/wwwx'), 'prefix must end at a path boundary');
    }

    public function testQueryBelowTheRelativeRoot(): void
    {
        $url = new RequestUrl('http', 'a.test', 80, '/abc', '/abc/blog/1?draft=1');
        $this->assertSame('/blog/1?draft=1', $url->fullUrlQuery());
        $this->assertSame('/blog/1/', $url->urlQuery());
        $this->assertSame('http://a.test/abc', $url->razyUrlRoot());

        $bare = new RequestUrl('http', 'a.test', 80, '/abc', '/abc?draft=1');
        $this->assertSame('/', $bare->urlQuery(), 'a query string right after the root is not a path (strtok bug)');
        $this->assertSame('/', (new RequestUrl('http', 'a.test', 80, '', ''))->urlQuery());
    }

    public function testDistSiteUrlFollowsTheCurrentRequest(): void
    {
        $dist = $this->makeDist();

        RequestUrl::setCurrent(new RequestUrl('http', 'first.example.com', 80, '', '/'));
        $this->assertSame('http://first.example.com/shop', $dist->getSiteURL());

        // the next worker request, on another host
        RequestUrl::setCurrent(new RequestUrl('https', 'second.example.com', 8443, '', '/'));
        $this->assertSame('https://second.example.com:8443/shop', $dist->getSiteURL());
    }

    public function testXhrDefaultOriginFollowsTheCurrentRequest(): void
    {
        $origin = new ReflectionProperty(XHR::class, 'allowOrigin');

        RequestUrl::setCurrent(new RequestUrl('http', 'first.example.com', 80, '', '/'));
        $this->assertSame('http://first.example.com', $origin->getValue(new XHR()));

        RequestUrl::setCurrent(new RequestUrl('https', 'second.example.com', 443, '', '/'));
        $this->assertSame('https://second.example.com', $origin->getValue(new XHR()));
        $this->assertSame('https://second.example.com', $origin->getValue((new XHR())->allowOrigin('not a url')));
    }

    private function makeDist(): Distributor
    {
        $path = PathUtil::append(SITES_FOLDER, self::DIST);
        \mkdir($path, 0o777, true);
        \file_put_contents($path . '/dist.php', "<?php\nreturn ['dist' => '" . self::DIST . "'];\n");
        $domain = $this->createStub(Domain::class);
        $domain->method('getDomainName')->willReturn('first.example.com');

        return new Distributor(self::DIST, '*', $domain, '/shop', '/');
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            \is_dir($p) ? $this->removeDir($p) : \unlink($p);
        }
        \rmdir($dir);
    }
}
