<?php

/**
 * RZ-15: the in-process HTTP test client.
 *
 * Apps had no way to run a web request through a dist from PHPUnit: the CLI
 * process matched the CLI script table, kept no headers, and php://input
 * could not be fed. Razy\Testing\Client runs the real web path in-process.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Error\ErrorReporter;
use Razy\Http\Headers;
use Razy\Logger;
use Razy\RuntimeMode;
use Razy\Testing\Client;
use Razy\Testing\TestResponse;
use Razy\Util\PathUtil;

#[CoversClass(Client::class)]
#[CoversClass(TestResponse::class)]
#[CoversClass(Headers::class)]
#[CoversClass(RuntimeMode::class)]
final class TestingClientTest extends TestCase
{
    private const DIST = 'rz15_client_fixture';

    private string $distPath;

    protected function setUp(): void
    {
        // Error pages report to the log; keep that out of the test output.
        ErrorReporter::setLogger(new Logger());
        $this->distPath = PathUtil::append(SITES_FOLDER, self::DIST);
        $controller = PathUtil::append($this->distPath, 'acme', 'shop', 'default', 'controller');
        \mkdir($controller, 0o777, true);
        \file_put_contents($this->distPath . '/dist.php', "<?php\nreturn ['dist' => '" . self::DIST . "'];\n");
        \file_put_contents($this->distPath . '/acme/shop/module.php', "<?php\nreturn ['module_code' => 'acme/shop', 'author' => 't', 'description' => 't'];\n");
        \file_put_contents($this->distPath . '/acme/shop/default/package.php', "<?php\nreturn ['alias' => 'shop'];\n");
        \file_put_contents($controller . '/shop.php', <<<'PHP'
            <?php
            use Razy\Controller;
            return new class(null) extends Controller {
                public function __onInit($agent): bool
                {
                    $agent->addLazyRoute([
                        'hello' => 'hello', 'feed' => 'feed', 'echo' => 'echo', 'go' => 'go',
                        'boom' => 'boom', 'count' => 'count', 'cookie' => 'cookie', 'method' => 'method', 'price' => 'price',
                    ]);
                    return true;
                }
            };
            PHP);
        $routes = [
            'hello' => "echo 'hello ' . (\$_GET['name'] ?? 'nobody');",
            'feed' => "return \$this->xhr()->data(['sku' => 'A1'])->send(true, 'ok');",
            'echo' => "echo json_encode(['got' => \\Razy\\Http\\RequestBody::jsonArray(), 'type' => \$_SERVER['CONTENT_TYPE'] ?? '']);",
            'go' => "\$this->goto('hello/');",
            'boom' => "throw new \\RuntimeException('kaboom');",
            'count' => "\$_SESSION['n'] = (\$_SESSION['n'] ?? 0) + 1; echo \$_SESSION['n'];",
            'cookie' => "\\Razy\\Http\\Headers::cookie('flavor', \$_COOKIE['flavor'] ?? 'choc', ['path' => '/']); echo \$_COOKIE['flavor'] ?? 'none';",
            'price' => 'echo \\acme\\shop\\Price::format(1234);',
            'method' => "echo \$_SERVER['REQUEST_METHOD'] . ' ' . json_encode(\$_POST) . ' ' . (\$_SERVER['HTTP_X_TRACE'] ?? '-');",
        ];
        \mkdir($this->distPath . '/acme/shop/default/library', 0o777, true);
        \file_put_contents($this->distPath . '/acme/shop/default/library/Price.php', "<?php\nnamespace acme\\shop;\nfinal class Price { public static function format(int \$cents): string { return number_format(\$cents / 100, 2); } }\n");
        foreach ($routes as $name => $body) {
            \file_put_contents($controller . "/shop.{$name}.php", "<?php\nreturn function () {\n{$body}\n};\n");
        }
    }

    protected function tearDown(): void
    {
        ErrorReporter::setLogger(null);
        $this->removeDir($this->distPath);
    }

    public function testGetRunsTheWebRouteTable(): void
    {
        $response = $this->client()->get('/shop/hello/', ['name' => 'ray']);

        $this->assertSame(200, $response->status());
        $this->assertSame('hello ray', $response->body());
        $this->assertNotNull($response->header('X-Content-Type-Options'), 'the dist security headers are captured');
        $this->assertFalse(RuntimeMode::isWeb(), 'the simulated web mode ends with the request');
        $this->assertFalse(Headers::isCapturing());
    }

    public function testJsonApiResponse(): void
    {
        $response = $this->client()->get('/shop/feed/');

        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith('application/json', (string) $response->header('content-type'));
        $this->assertSame(['sku' => 'A1'], $response->json()['response']);
    }

    public function testJsonBodyReachesTheController(): void
    {
        $response = $this->client()->postJson('/shop/echo/', ['qty' => 3]);

        $this->assertSame(['got' => ['qty' => 3], 'type' => 'application/json'], $response->json());
    }

    public function testFormPostAndDefaultHeaders(): void
    {
        $client = $this->client()->withHeader('X-Trace', 't-1');
        $this->assertSame('POST {"a":"1"} t-1', $client->post('/shop/method/', ['a' => '1'])->body());
        $this->assertSame('DELETE [] t-1', $client->request('DELETE', '/shop/method/')->body());
    }

    public function testPerRequestHeadersWinOverDefaults(): void
    {
        $client = $this->client()->withHeader('x-trace', 'default')->withHeader('Content-Type', 'text/plain');

        $this->assertSame('POST {"a":"1"} per-call', $client->post('/shop/method/', ['a' => '1'], ['X-Trace' => 'per-call'])->body());
        $this->assertSame('application/json', $client->postJson('/shop/echo/', [])->json()['type'], 'postJson keeps its Content-Type');
    }

    public function testModuleLibraryClassesAutoload(): void
    {
        $this->assertSame('12.34', $this->client()->get('/shop/price/')->body());
    }

    public function testRedirect(): void
    {
        $response = $this->client()->get('/shop/go/');

        $this->assertSame(301, $response->status());
        $this->assertTrue($response->isRedirect());
        $this->assertStringEndsWith('hello/', (string) $response->header('Location'));
    }

    public function testUnknownPathIs404(): void
    {
        $response = $this->client()->get('/shop/nope/');

        $this->assertSame(404, $response->status());
        $this->assertStringContainsString('404 Not Found', $response->body());
    }

    public function testExceptionGivesTheSafeErrorPage(): void
    {
        $response = $this->client()->get('/shop/boom/');

        $this->assertSame(500, $response->status());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $response->header('X-Error-Id'));
        $this->assertStringNotContainsString('kaboom', $response->body(), 'debug is off: no message leaks');
    }

    public function testSessionPersistsAcrossRequests(): void
    {
        $client = $this->client();
        $this->assertSame('1', $client->get('/shop/count/')->body());
        $this->assertSame('2', $client->get('/shop/count/')->body());
        $this->assertSame(2, $client->session()['n']);
        $this->assertFalse(isset($_SESSION), 'the test process session is restored');

        $client->session()['n'] = 10;
        $this->assertSame('11', $client->get('/shop/count/')->body());
    }

    public function testCookiesRoundTrip(): void
    {
        $client = $this->client();
        $first = $client->get('/shop/cookie/');
        $this->assertSame('none', $first->body());
        $this->assertSame(['flavor' => 'choc'], $first->cookies());
        $this->assertSame('choc', $client->get('/shop/cookie/')->body(), 'the jar sends it back');
    }

    public function testGlobalsAreRestored(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        $this->client()->get('/shop/hello/', ['name' => 'x']);

        $this->assertSame($server, $_SERVER);
        $this->assertSame($get, $_GET);
    }

    public function testHeadersCaptureSemantics(): void
    {
        Headers::startCapture();
        Headers::send('X-A: 1');
        Headers::send('X-A: 2');
        Headers::send('X-B: 1');
        Headers::send('X-B: 2', false);
        Headers::send('Location: /x');
        Headers::remove('X-A');
        $captured = Headers::stopCapture();

        $this->assertSame(302, $captured['status'], 'Location turns a 200 into a 302, as PHP does');
        $this->assertSame([['X-B', '1'], ['X-B', '2'], ['Location', '/x']], $captured['headers']);

        Headers::startCapture();
        Headers::send('HTTP/1.1 418 I\'m a teapot');
        $this->assertSame(418, Headers::status());
        $this->assertFalse(Headers::sent());
        $this->assertSame(418, Headers::stopCapture()['status']);
    }

    private function client(): Client
    {
        return new Client(['/' => self::DIST], 'shop.example.test');
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
