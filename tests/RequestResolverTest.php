<?php

/**
 * RZ-12: the dist request-resolver stage.
 *
 * Host/tenant resolution used to live in a module's __onInit with database
 * I/O and `exit` (an RZ-009 violation, and fatal in worker mode). The stage
 * runs after the lifecycle is ready and before the route table; a resolver
 * records what it found on the RequestContext or ends the request with a
 * Response.
 *
 * @license MIT
 */

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor;
use Razy\Domain;
use Razy\Http\Request;
use Razy\Http\RequestContext;
use Razy\Http\RequestResolver;
use Razy\Http\Response;
use Razy\Util\PathUtil;
use UnexpectedValueException;

#[CoversClass(RequestResolver::class)]
#[CoversClass(RequestContext::class)]
#[CoversClass(Response::class)]
final class RequestResolverTest extends TestCase
{
    private const DIST = 'rz12_resolver_fixture';

    private string $distPath;

    /** @var array<string, mixed> */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->distPath = PathUtil::append(SITES_FOLDER, self::DIST);
        $controller = PathUtil::append($this->distPath, 'acme', 'tenant', 'default', 'controller');
        \mkdir($controller, 0o777, true);
        \file_put_contents($this->distPath . '/dist.php', "<?php\nreturn ['dist' => '" . self::DIST . "'];\n");
        \file_put_contents($this->distPath . '/acme/tenant/module.php', "<?php\nreturn ['module_code' => 'acme/tenant', 'author' => 't', 'description' => 't'];\n");
        \file_put_contents($this->distPath . '/acme/tenant/default/package.php', "<?php\nreturn ['alias' => 'tenant'];\n");
        // The resolver reads the host, records the tenant or answers 404;
        // the route prints what the resolver recorded.
        \file_put_contents($controller . '/tenant.php', <<<'PHP'
            <?php
            use Razy\Controller;
            use Razy\Http\RequestContext;
            use Razy\Http\Response;
            return new class(null) extends Controller {
                public function __onInit($agent): bool
                {
                    // CLI_MODE in tests matches scripts, the web route table's twin
                    $agent->addScript(['hello' => 'hello']);
                    return true;
                }
                public function __onLoad($agent): bool
                {
                    $agent->resolveRequest(function (RequestContext $request): ?Response {
                        $GLOBALS['rz12_resolver_runs'] = ($GLOBALS['rz12_resolver_runs'] ?? 0) + 1;
                        if ('blue.example.com' !== $request->host()) {
                            return Response::notFound();
                        }
                        $request->set('tenant', 'blue');
                        return null;
                    });
                    return true;
                }
            };
            PHP);
        \file_put_contents($controller . '/tenant.hello.php', <<<'PHP'
            <?php
            return function () {
                echo 'hello ' . $this->requestContext()->get('tenant', 'none');
            };
            PHP);
        $GLOBALS['rz12_resolver_runs'] = 0;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        unset($GLOBALS['rz12_resolver_runs']);
        $this->removeDir($this->distPath);
    }

    public function testKnownHostIsRecordedAndRouted(): void
    {
        $this->assertSame('hello blue', $this->request('blue.example.com', '/tenant/hello/'));
        $this->assertSame(1, $GLOBALS['rz12_resolver_runs']);
    }

    public function testUnknownHostIsAnsweredBeforeAnyRoute(): void
    {
        $this->assertSame('Not found', $this->request('red.example.com', '/tenant/hello/'));
        $this->assertSame(1, $GLOBALS['rz12_resolver_runs']);
    }

    public function testFirstAnswerStopsLaterResolvers(): void
    {
        $stage = new RequestResolver();
        $ran = [];
        $stage->add(function (RequestContext $r) use (&$ran) {
            $ran[] = 'a';
            $r->set('host', $r->host());
        }, 'acme/a');
        $stage->add(function () use (&$ran) {
            $ran[] = 'b';

            return Response::redirect('/elsewhere', 308);
        }, 'acme/b');
        $stage->add(function () use (&$ran) {
            $ran[] = 'c';
        }, 'acme/c');

        $context = $this->context('x.example.com');
        $answer = $stage->resolve($context);

        $this->assertSame(['a', 'b'], $ran);
        $this->assertSame('x.example.com', $context->get('host'));
        $this->assertSame(308, $answer?->getStatus());
        $this->assertSame(['Location' => '/elsewhere'], $answer->getHeaders());
    }

    public function testSameModuleReplacesItsResolver(): void
    {
        $stage = new RequestResolver();
        $stage->add(fn () => Response::notFound('old'), 'acme/a');
        $stage->add(fn () => Response::notFound('new'), 'acme/a');

        $this->assertSame(1, $stage->count());
        $this->assertSame('new', $stage->resolve($this->context('h.example.com'))?->getBody());
    }

    public function testNoResolverRoutesTheRequest(): void
    {
        $this->assertNull((new RequestResolver())->resolve($this->context('h.example.com')));
    }

    public function testAnythingButNullOrResponseFailsLoudly(): void
    {
        $stage = new RequestResolver();
        $stage->add(fn () => false, 'acme/a');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'acme/a'");
        $stage->resolve($this->context('h.example.com'));
    }

    public function testResponseRejectsHeaderInjectionAndBadStatus(): void
    {
        try {
            new Response(200, '', ['X-Ok' => "a\r\nSet-Cookie: x=1"]);
            $this->fail('CR/LF in a header value must throw');
        } catch (InvalidArgumentException) {
        }
        try {
            Response::redirect("/a\nb");
            $this->fail('CR/LF in a redirect must throw');
        } catch (InvalidArgumentException) {
        }
        try {
            new Response(200, '', ['Bad Name' => 'x']);
            $this->fail('a bad header name must throw');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new Response(99);
    }

    public function testContextAttributes(): void
    {
        $context = $this->context('h.example.com');
        $this->assertFalse($context->has('tenant'));
        $this->assertSame('d', $context->get('tenant', 'd'));
        $context->set('tenant', null);
        $this->assertTrue($context->has('tenant'));
        $this->assertNull($context->get('tenant', 'd'));
        $this->assertSame(['tenant' => null], $context->all());
        $this->assertSame('POST', $context->method);
        $this->assertSame('/p', $context->path);
    }

    public function testStageRunsAfterTheLifecycleAndBeforeTheRouteTable(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/Distributor.php');
        $match = \substr($src, \strpos($src, 'public function matchRoute'));
        $this->assertLessThan(\strpos($match, 'answeredByResolvers()'), \strpos($match, 'notifyReady()'));
        $this->assertLessThan(\strpos($match, '$this->router->matchRoute('), \strpos($match, 'answeredByResolvers()'));
        $dispatch = \substr($src, \strpos($src, 'public function dispatch'));
        $this->assertLessThan(\strpos($dispatch, '$this->router->matchRoute('), \strpos($dispatch, 'answeredByResolvers()'));
    }

    private function request(string $host, string $path): string
    {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $domain = $this->createStub(Domain::class);
        $domain->method('getDomainName')->willReturn($host);
        $dist = new Distributor(self::DIST, '*', $domain, '/', $path);
        $dist->initialize();

        \ob_start();
        try {
            $dist->matchRoute();
        } finally {
            $out = (string) \ob_get_clean();
        }

        return $out;
    }

    private function context(string $host): RequestContext
    {
        return new RequestContext(new Request(['HTTP_HOST' => $host]), 'POST', '/p');
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $f) {
            if ('.' === $f || '..' === $f) {
                continue;
            }
            $p = $dir . '/' . $f;
            \is_dir($p) ? $this->removeDir($p) : @\unlink($p);
        }
        @\rmdir($dir);
    }
}
