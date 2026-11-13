<?php

/**
 * RZ-56: exact routes and the dist's not-found answer.
 *
 * Routes are prefixes: `/` with no flag answered every URL of a dist with
 * 200, so a dist could not say 404. `Route::exact()` makes a route match
 * only its own path, and `$agent->onNotFound()` answers a request no route
 * matched (status 404 forced). Without a handler the answer stays
 * `Error::show404()`.
 *
 * @license MIT
 */

namespace Razy\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor\RouteDispatcher;
use Razy\Error\ErrorReporter;
use Razy\Http\NotFoundHandler;
use Razy\Http\Request;
use Razy\Http\RequestContext;
use Razy\Http\Response;
use Razy\Logger;
use Razy\Route;
use Razy\Testing\Client;
use Razy\Util\PathUtil;
use UnexpectedValueException;

#[CoversClass(NotFoundHandler::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteDispatcher::class)]
final class RouteNotFoundTest extends TestCase
{
    /** A dist with an exact `/`, prefix routes and a not-found handler, over two modules */
    private const DIST = 'rz56_hooked_fixture';

    /** The same routes and no handler */
    private const PLAIN = 'rz56_plain_fixture';

    /** @var list<string> */
    private array $dists = [];

    protected function setUp(): void
    {
        ErrorReporter::setLogger(new Logger());
        $GLOBALS['rz56_not_found'] = [];
        $this->makeDist(self::DIST, true);
        $this->makeDist(self::PLAIN, false);
    }

    protected function tearDown(): void
    {
        ErrorReporter::setLogger(null);
        unset($GLOBALS['rz56_not_found']);
        foreach ($this->dists as $dist) {
            $this->removeDir(PathUtil::append(SITES_FOLDER, $dist));
        }
    }

    public function testExactRootAnswersItsOwnPath(): void
    {
        $response = $this->get(self::DIST, '/');

        $this->assertSame(200, $response->status());
        $this->assertSame('main', $response->body());
        $this->assertSame([], $GLOBALS['rz56_not_found']);
    }

    public function testExactRootDoesNotSwallowATail(): void
    {
        $response = $this->get(self::DIST, '/no-such-page/');

        $this->assertSame(404, $response->status(), 'the handler returned 200; the framework forces 404');
        $this->assertSame('custom 404 /no-such-page/', $response->body());
        $this->assertSame(['/no-such-page/'], $GLOBALS['rz56_not_found'], 'the handler runs once');
    }

    public function testTheAnswerKeepsTheDistSecurityHeaders(): void
    {
        $response = $this->get(self::DIST, '/no-such-page/');

        $this->assertNotNull($response->header('X-Content-Type-Options'), 'the dist security headers are sent before the handler');
        $this->assertSame('text/html; charset=UTF-8', $response->header('Content-Type'), 'the handler headers are sent');
    }

    public function testHeadGetsNoBody(): void
    {
        $response = (new Client(['/' => self::DIST], 'rz56.example.test'))->request('HEAD', '/no-such-page/');

        $this->assertSame(404, $response->status());
        $this->assertSame('', $response->body());
        $this->assertSame(['/no-such-page/'], $GLOBALS['rz56_not_found']);
    }

    public function testTheQueryStringIsNotPartOfThePath(): void
    {
        $response = (new Client(['/' => self::DIST], 'rz56.example.test'))->get('/', ['x' => '1']);

        $this->assertSame('main', $response->body(), 'an exact route ignores the query string');
    }

    public function testTheUrlQueryAlwaysEndsInASlash(): void
    {
        // The one trailing-slash rule: the front controller tidies every path
        // to end in '/', so '/privacy' and '/privacy/' are one URL for every route.
        $url = \Razy\Http\RequestUrl::fromServer(['REQUEST_URI' => '/privacy?x=1', 'SERVER_NAME' => 'h.example.test', 'SERVER_PORT' => '80']);
        $this->assertSame('/privacy/', $url->urlQuery());
    }

    public function testAnUnknownUrlIs404BeforeAnyRouteGuard(): void
    {
        $this->assertSame(302, $this->get(self::DIST, '/vault/')->status(), 'fixture sanity: signed out on the guarded route goes to the login');

        $response = $this->get(self::DIST, '/vault/x/');
        $this->assertSame(404, $response->status(), 'signed out on an unknown URL: 404, not a login redirect');
        $this->assertSame(['/vault/x/'], $GLOBALS['rz56_not_found']);
    }

    public function testExactChainsWithCan(): void
    {
        $route = (new Route('vault'))->exact()->can('vault.open');
        $this->assertTrue($route->isExact());
        $this->assertSame('vault.open', $route->getAuthzAbility());
        $this->assertTrue((new Route('vault'))->can('vault.open')->exact()->isExact());
    }

    public function testAVariadicHandlerIsAccepted(): void
    {
        $handler = new NotFoundHandler();
        $handler->set((static fn (mixed ...$args): Response => Response::notFound((string) \count($args)))(...), 'acme/home');

        $this->assertSame('1', $handler->answer($this->context())?->getBody(), 'the handler gets the one request context');
    }

    public function testPrefixRoutesStillTakeTheirTail(): void
    {
        $this->assertSame('pool:a,b', $this->get(self::DIST, '/pool/a/b/')->body());
        $this->assertSame('pool:', $this->get(self::DIST, '/pool/')->body());
        $this->assertSame('chat:42:', $this->get(self::DIST, '/chat/42/')->body());
        $this->assertSame([], $GLOBALS['rz56_not_found']);
    }

    public function testAPeerModuleRouteIsNotANotFound(): void
    {
        $response = $this->get(self::DIST, '/blog/post/hello/');

        $this->assertSame(200, $response->status());
        $this->assertSame('post:hello', $response->body(), 'the second module keeps its prefix lazy route');
    }

    public function testExactLazyRouteTakesTheTrailingSlashOnly(): void
    {
        $this->assertSame('about', $this->get(self::DIST, '/home/about/')->body());

        $response = $this->get(self::DIST, '/home/about/team/');
        $this->assertSame(404, $response->status());
        $this->assertSame(['/home/about/team/'], $GLOBALS['rz56_not_found']);
    }

    public function testWithoutAHandlerTheOldAnswerStays(): void
    {
        $miss = $this->get(self::PLAIN, '/no-such-page/');
        $this->assertSame(404, $miss->status());
        $this->assertStringContainsString('404 Not Found', $miss->body());
        $this->assertSame([], $GLOBALS['rz56_not_found'], 'the other dist handler is not called');

        $this->assertSame('pool:a', $this->get(self::PLAIN, '/pool/a/')->body());
    }

    public function testBothEntryPointsAskTheHandlerAfterTheRouteTable(): void
    {
        // dispatch() is the worker fast path (WORKER_MODE only, which the test
        // bootstrap pins to false), so its wiring is checked in the source.
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/Distributor.php');
        foreach (['public function matchRoute', 'public function dispatch'] as $entry) {
            $body = \substr($src, (int) \strpos($src, $entry));
            $body = \substr($body, 0, (int) \strpos($body, "\n    }\n"));
            $router = \strpos($body, '$this->router->matchRoute(');
            $this->assertNotFalse($router, $entry);
            $this->assertMatchesRegularExpression('/\$this->router->matchRoute\([^;]*\)\s*\|\|\s*\$this->getNotFoundHandler\(\)->respond\(\$this->getRequestContext\(\)\);/', $body, $entry);
        }
    }

    public function testDefaultRoutesStayPrefixes(): void
    {
        $route = new Route('main');
        $this->assertFalse($route->isExact());
        $this->assertSame($route, $route->exact());
        $this->assertTrue($route->isExact());
    }

    public function testOneModuleOwnsTheHandler(): void
    {
        $handler = new NotFoundHandler();
        $this->assertFalse($handler->has());
        $this->assertNull($handler->answer($this->context()));

        $handler->set(fn () => Response::notFound('a'), 'acme/home');
        $handler->set(fn () => Response::notFound('b'), 'acme/home');
        $this->assertSame('b', $handler->answer($this->context())?->getBody(), 'the owner may replace its own');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("owned by 'acme/home'");
        $handler->set(fn () => Response::notFound('c'), 'acme/blog');
    }

    public function testStatusIsForcedTo404AndHeadersKept(): void
    {
        $handler = new NotFoundHandler();
        $handler->set(fn () => new Response(200, 'page', ['Content-Type' => 'text/html; charset=UTF-8']), 'acme/home');
        $answer = $handler->answer($this->context());

        $this->assertSame(404, $answer?->getStatus());
        $this->assertSame('page', $answer->getBody());
        $this->assertSame(['Content-Type' => 'text/html; charset=UTF-8'], $answer->getHeaders());
    }

    public function testAnythingButAResponseFailsLoudly(): void
    {
        $handler = new NotFoundHandler();
        $handler->set(fn () => null, 'acme/home');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'acme/home'");
        $handler->answer($this->context());
    }

    private function get(string $dist, string $path): \Razy\Testing\TestResponse
    {
        return (new Client(['/' => $dist], 'rz56.example.test'))->get($path);
    }

    private function context(): RequestContext
    {
        return new RequestContext(new Request(['HTTP_HOST' => 'h.example.test']), 'GET', '/x/');
    }

    private function makeDist(string $dist, bool $hooked): void
    {
        $this->dists[] = $dist;
        $root = PathUtil::append(SITES_FOLDER, $dist);
        \mkdir($root, 0o777, true);
        \file_put_contents($root . '/dist.php', "<?php\nreturn ['dist' => '{$dist}', 'authz' => ['login_url' => '/login']];\n");

        $hook = $hooked ? <<<'PHP'
                public function __onLoad($agent): bool
                {
                    $agent->onNotFound(function (\Razy\Http\RequestContext $request): \Razy\Http\Response {
                        $GLOBALS['rz56_not_found'][] = $request->path;
                        return new \Razy\Http\Response(200, 'custom 404 ' . $request->path, ['Content-Type' => 'text/html; charset=UTF-8']);
                    });
                    return true;
                }
            PHP : '';
        $this->makeModule($root, 'home', <<<PHP
                public function __onInit(\$agent): bool
                {
                    \$agent->addRoute([
                        '/' => (new \\Razy\\Route('main'))->exact(),
                        '/pool' => 'pool',
                        '/chat/(:d)' => 'chat',
                        '/vault' => (new \\Razy\\Route('vault'))->exact()->can('vault.open'),
                    ]);
                    \$agent->identity(fn () => null);
                    \$agent->addAuthzAbility('vault.open', fn () => true);
                    \$agent->addLazyRoute(['about' => (new \\Razy\\Route('about'))->exact()]);
                    return true;
                }
            {$hook}
            PHP, [
            'main' => "echo 'main';",
            'pool' => "echo 'pool:' . implode(',', array_filter(func_get_args(), 'strlen'));",
            'chat' => "\$a = func_get_args(); echo 'chat:' . \$a[0] . ':' . (\$a[1] ?? '');",
            'about' => "echo 'about';",
            'vault' => "echo 'vault';",
        ]);
        $this->makeModule($root, 'blog', <<<'PHP'
                public function __onInit($agent): bool
                {
                    $agent->addLazyRoute(['post' => 'post']);
                    return true;
                }
            PHP, [
            'post' => "echo 'post:' . implode(',', func_get_args());",
        ]);
    }

    /**
     * @param array<string, string> $closures closure name => body
     */
    private function makeModule(string $root, string $alias, string $body, array $closures): void
    {
        $controller = PathUtil::append($root, 'acme', $alias, 'default', 'controller');
        \mkdir($controller, 0o777, true);
        \file_put_contents("{$root}/acme/{$alias}/module.php", "<?php\nreturn ['module_code' => 'acme/{$alias}', 'author' => 't', 'description' => 't'];\n");
        \file_put_contents("{$root}/acme/{$alias}/default/package.php", "<?php\nreturn ['alias' => '{$alias}'];\n");
        \file_put_contents("{$controller}/{$alias}.php", "<?php\nuse Razy\\Controller;\nreturn new class(null) extends Controller {\n{$body}\n};\n");
        foreach ($closures as $name => $code) {
            \file_put_contents("{$controller}/{$alias}.{$name}.php", "<?php\nreturn function () {\n{$code}\n};\n");
        }
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
