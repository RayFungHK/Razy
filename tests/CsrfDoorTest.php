<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use Razy\Controller;
use Razy\Csrf\CsrfDoor;
use Razy\Csrf\CsrfMiddleware;
use Razy\Csrf\CsrfRejection;
use Razy\Csrf\CsrfTokenManager;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Session\Driver\ArrayDriver;
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\SessionMiddleware;
use ReflectionProperty;

/**
 * CSRF-RAIL L1: the door arms the engine from one config key.
 *
 * The Distributor's real three-state config parsing is pinned from source
 * (booting a Distributor needs a dist on disk — the live loop is L3's
 * playground job); everything below runs at unit weight.
 */
#[CoversClass(CsrfDoor::class)]
#[CoversClass(CsrfRejection::class)]
class CsrfDoorTest extends TestCase
{
    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']);
    }
    // ────────────────────────────────────────────────────────────
    // Section 1: arm() wiring
    // ────────────────────────────────────────────────────────────

    public function testArmPipesSessionOutsideCsrfInside(): void
    {
        $dispatcher = new RouteDispatcher();
        $distributor = $this->distributorDouble($dispatcher, $container = new Container());

        CsrfDoor::arm($distributor);

        $middleware = $dispatcher->getGlobalMiddleware();
        $this->assertCount(2, $middleware);
        // Onion order IS registration order: the token must validate
        // between session start() and save(), so session wraps csrf.
        $this->assertInstanceOf(SessionMiddleware::class, $middleware[0]);
        $this->assertInstanceOf(CsrfMiddleware::class, $middleware[1]);
    }

    public function testArmSetsRotationOnSuccessAtTheDoor(): void
    {
        $dispatcher = new RouteDispatcher();
        CsrfDoor::arm($this->distributorDouble($dispatcher, new Container()));

        $csrf = $dispatcher->getGlobalMiddleware()[1];
        $rotate = (new ReflectionProperty(CsrfMiddleware::class, 'rotateOnSuccess'))->getValue($csrf);

        // Q5: the door ships the good default, not the engine's compat one.
        $this->assertTrue($rotate);
    }

    public function testArmPublishesTokenManagerOnTheContainer(): void
    {
        $dispatcher = new RouteDispatcher();
        CsrfDoor::arm($this->distributorDouble($dispatcher, $container = new Container()));

        $this->assertTrue($container->has(CsrfTokenManager::class));
        $this->assertSame(
            $container->make(CsrfTokenManager::class),
            $container->make(CsrfTokenManager::class),
            'the published manager must be the ONE the middleware validates against',
        );
    }

    // ────────────────────────────────────────────────────────────
    // Section 2: Controller helpers (the last mile)
    // ────────────────────────────────────────────────────────────

    public function testUnarmedHelperDiesWithInstructionsNotAnEmptyField(): void
    {
        $controller = $this->controllerDouble(new Container());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('UNARMED');

        $controller->csrfToken();
    }

    public function testArmedHelperReturnsTheManagersOwnToken(): void
    {
        $manager = new CsrfTokenManager(new Session(new ArrayDriver(), new SessionConfig()));

        $container = new Container();
        $container->instance(CsrfTokenManager::class, $manager);

        $this->assertSame($manager->token(), $this->controllerDouble($container)->csrfToken());
    }

    public function testCsrfFieldIsTheHiddenInputTheFormsNeed(): void
    {
        $manager = new CsrfTokenManager(new Session(new ArrayDriver(), new SessionConfig()));

        $container = new Container();
        $container->instance(CsrfTokenManager::class, $manager);

        $field = $this->controllerDouble($container)->csrfField();

        $this->assertStringContainsString('name="_token"', $field);
        $this->assertStringContainsString('value="' . $manager->token() . '"', $field);
    }

    // ────────────────────────────────────────────────────────────
    // Section 3: rejection answers
    // ────────────────────────────────────────────────────────────

    public function testXhrRejectionIsTheJsonEnvelope(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            \ob_start();
            $answer = (new CsrfRejection())([
                'module' => 'demo/csrfdemo',
                'route' => '/csrfdemo/echo',
                'method' => 'POST',
            ]);
            $body = (string) \ob_get_clean();
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        $this->assertNull($answer, 'onMismatch must short-circuit with null');

        $payload = \json_decode($body, true);
        $this->assertIsArray($payload);
        $this->assertSame('csrf-token-mismatch', $payload['error']);
        $this->assertSame('demo/csrfdemo', $payload['module']);
        $this->assertStringContainsString('X-CSRF-TOKEN', $payload['fix']);
    }

    public function testHtmlRejectionNamesTheRefusalWithoutLeakingAnything(): void
    {
        \ob_start();
        (new CsrfRejection())([
            'module' => 'demo/csrfdemo',
            'route' => '/csrfdemo/save',
            'method' => 'DELETE',
        ]);
        $body = (string) \ob_get_clean();

        $this->assertStringContainsString('419', $body);
        $this->assertStringContainsString('demo/csrfdemo', $body);
        $this->assertStringContainsString('DELETE', $body);
        // The submitted token never appears — there is none in reach, by
        // design; this rail keeps it that way if anyone later "helpfully"
        // pipes the request into this class.
        $this->assertStringNotContainsString('_POST', $body);
    }

    public function testRejectionFiresTheFailedEventWithRouteContext(): void
    {
        // One recorder playing both roles (Module → createEmitter → resolve),
        // no reference plumbing: constructor property promotion disallows it.
        $recorder = new class() {
            public ?array $seen = null;

            private string $event = '';

            public function createEmitter(string $event): self
            {
                $this->event = $event;

                return $this;
            }

            public function resolve(...$args): void
            {
                $this->seen = ['event' => $this->event, 'payload' => $args[0] ?? null];
            }
        };

        $rejection = new CsrfRejection(static fn (string $code): object => $recorder);

        \ob_start();
        $rejection(['module' => 'demo/x', 'route' => '/x/save', 'method' => 'post']);
        \ob_end_clean();

        $this->assertSame('csrf.failed', $recorder->seen['event']);
        $this->assertSame('demo/x', $recorder->seen['payload']['module']);
        $this->assertSame('POST', $recorder->seen['payload']['method'], 'method is normalised for auditors');
    }

    public function testRejectionSurvivesAnUnresolvableModule(): void
    {
        // Events announce, they never gate: no module, no event — but the
        // 419 answer still stands.
        $rejection = new CsrfRejection(static fn (string $code): ?object => null);

        \ob_start();
        $answer = $rejection(['module' => 'ghost/mod', 'route' => '/g', 'method' => 'PUT']);
        $body = (string) \ob_get_clean();

        $this->assertNull($answer);
        $this->assertStringContainsString('419', $body);
    }

    // ────────────────────────────────────────────────────────────
    // Section 4: source rails for the boot-time halves
    // ────────────────────────────────────────────────────────────

    public function testDistributorParsesThreeStatesAndFailsLoudOnLies(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__) . '/src/library/Razy/Distributor.php'
        );

        // Positive-form pins only (negative ones hit their own comments).
        $this->assertStringContainsString("\$csrfRaw = \$config['csrf'] ?? 'off';", $source);
        $this->assertStringContainsString("\\in_array(\$csrfRaw, ['on', 'off'], true)", $source);
        $this->assertStringContainsString("throw new ConfigurationException(\n                \"dist.php 'csrf' must be the string 'on' or 'off'", $source);
        $this->assertStringContainsString("if ('on' === \$this->csrfMode) {\n            CsrfDoor::arm(\$this);", $source);
    }

    public function testValidatePrintsTheUnarmedWarning(): void
    {
        $source = (string) \file_get_contents(
            \dirname(__DIR__) . '/src/system/terminal/validate.inc.php'
        );

        $this->assertStringContainsString('CSRF Posture', $source);
        $this->assertStringContainsString('UNARMED — mutating routes accept cross-site form submissions', $source);
        $this->assertStringContainsString('(forms: Controller::csrfField(); XHR: X-CSRF-TOKEN header)', $source);
    }

    private function distributorDouble(RouteDispatcher $dispatcher, Container $container): Distributor
    {
        $distributor = $this->createMock(Distributor::class);
        $distributor->method('getCode')->willReturn('csrfdoordist');
        $distributor->method('getRouter')->willReturn($dispatcher);
        $distributor->method('getRegistry')->willReturn($this->createMock(ModuleRegistry::class));
        $distributor->method('getContainer')->willReturn($container);

        return $distributor;
    }

    private function controllerDouble(Container $container): Controller
    {
        $module = $this->createMock(Module::class);
        $module->method('getContainer')->willReturn($container);

        return new class($module) extends Controller {};
    }
}
