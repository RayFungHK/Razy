<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Configuration;
use Razy\Database;
use Razy\Database\MigrationManager;
use Razy\Module;
use Razy\Module\permissions\PermissionController;
use Razy\ModuleInfo;
use Razy\Template;

// the suite has no module autoloader (PermissionsCheckSurfaceTest precedent):
// the module's named controller + service are plain files, pull them in.
require_once SYSTEM_ROOT . '/modules/permissions/default/controller/support/Service.php';
require_once SYSTEM_ROOT . '/modules/permissions/default/controller/support/controller.php';

/**
 * S4: the {can} Template function plugin, rendered through the REAL engine
 * (Template->load()->output(), `{@can …}{/can}` function-tag syntax per
 * manual/05 §3.4), with the plugin folder registered by the module's own
 * __onInit — the test therefore proves the whole wiring, not just the
 * plugin file. CLI_MODE is true under phpunit (bootstrap:27-28), so actor
 * identity rides the system_actors head (§7.5), never ambient.
 */
#[CoversNothing]
class PermissionsTemplateTest extends TestCase
{
    private const MODULE_DIR = SYSTEM_ROOT . '/modules/permissions/default';

    /** @var list<string> */
    private array $tempFiles = [];

    private string $prevSuper = '';

    protected function setUp(): void
    {
        // process-global plugin memo caches the FIRST controller bound to a
        // folder (PluginManager.php:109 — the GateFactory lesson again);
        // resetPlugins() is the documented worker-mode antidote
        // (PluginTrait.php:46-55): each test starts from a clean registry.
        Template::resetPlugins();

        $this->prevSuper = (string) \getenv('RAZY_SUPER_ADMINS');
        \putenv('RAZY_SUPER_ADMINS=');
    }

    protected function tearDown(): void
    {
        Template::resetPlugins();

        \putenv('RAZY_SUPER_ADMINS=' . $this->prevSuper);

        foreach ($this->tempFiles as $file) {
            @\unlink($file);
        }

        $this->tempFiles = [];
    }

    // ── scenarios ─────────────────────────────────────────────────

    public function testGuestSeesNothingAndContentNeverShips(): void
    {
        $controller = $this->controller([]);

        $out = $this->render($controller, "PRE{@can 'demo.view'}TOP-SECRET{/can}POST");

        $this->assertSame('PREPOST', $out, 'deny drops the enclosed markup server-side, not client-side');
    }

    public function testSuperActorSeesTheBlock(): void
    {
        $controller = $this->controller([
            'system_actors' => ['admin:9'],
            'super_actors' => ['admin:9'],
        ]);

        $out = $this->render($controller, "{@can 'demo.view'}TOP-SECRET{/can}");

        $this->assertSame('TOP-SECRET', $out);
    }

    public function testEnvSuperExtendsConfigThroughTheTemplate(): void
    {
        \putenv('RAZY_SUPER_ADMINS=ops:1');

        $controller = $this->controller(['system_actors' => ['ops:1']]);

        $this->assertSame('YES', $this->render($controller, "{@can 'anything.at.all'}YES{/can}"));
    }

    public function testDatabaseGrantAllowsAndAbsenceDenies(): void
    {
        // random suffix: a prior failed run can leave the file locked at
        // tearDown time (@unlink), and a stale file would seed-collide
        $dbName = 'perm_tpl_' . \bin2hex(\random_bytes(6));
        $dbFile = $this->temp($dbName . '.sqlite');

        $db = new Database($dbName);
        $this->assertTrue($db->connectWithDriver('sqlite', ['database' => $dbFile]));

        $manager = new MigrationManager($db, 'razymod/permissions');
        $manager->addPath(self::MODULE_DIR . '/migration');
        $manager->migrate();

        $seed = function (string $sql) use ($db): void {
            $db->execute($db->prepare($sql));
        };
        // the resolver's new Database($name) returns the SAME shared instance
        // (Database name registry) — both renders below share this one
        // connection, hence no per-render file reopen. Seed exactly the two
        // codes exercised, with unique ids.
        $seed("INSERT INTO permissions (code, module_code) VALUES ('demo.view', 'demo/mod')");
        $seed("INSERT INTO permissions (code, module_code) VALUES ('demo.other', 'demo/mod')");
        $seed("INSERT INTO roles (code) VALUES ('viewer')");
        $seed('INSERT INTO permission_role (role_id, permission_id) VALUES (1, 1)');
        $seed('INSERT INTO permission_role (role_id, permission_id) VALUES (1, 2)');
        $seed("INSERT INTO actor_role (actor_type, actor_id, role_id) VALUES ('user', '7', 1)");

        $controller = $this->controller([
            'system_actors' => ['user:7'],
            'database' => [
                'type' => 'sqlite',
                'connection' => ['database' => $dbFile],
                'name' => $dbName,
            ],
        ]);

        $this->assertSame('SHOWN', $this->render($controller, "{@can 'demo.view'}SHOWN{/can}"));
        $this->assertSame('', $this->render($controller, "{@can 'demo.delete'}SHOWN{/can}"));
    }

    public function testAnyOfListedAbilitiesOpensTheBlock(): void
    {
        $controller = $this->controller([
            'system_actors' => ['admin:9'],
            'super_actors' => ['admin:9'],
        ]);

        $out = $this->render($controller, "{@can 'nope.one' 'nope.two'}EITHER{/can}");

        // super resolves the first token already; a db actor exercises the OR
        $this->assertSame('EITHER', $out);
    }

    public function testDeadDatabaseHidesRatherThanBreaksRender(): void
    {
        $controller = $this->controller([
            'system_actors' => ['user:7'],
            'database' => [
                'type' => 'sqlite',
                // missing PARENT dir = guaranteed-dead on every platform (the old
                // 'Q:\…' literal was a creatable relative filename on Linux).
                'connection' => ['database' => \sys_get_temp_dir() . '/razy-no-such-dir-9f2b/app.db'],
                'name' => 'perm_tpl_dead',
            ],
        ]);

        $this->assertSame('AFTER', $this->render($controller, "{@can 'demo.view'}SECRET{/can}AFTER"));
    }

    /**
     * RZ-004 posture (note test): the plugin neither escapes nor
     * re-interpolates — allowed content passes through exactly as the engine
     * rendered it; denied content never ships. Escaping stays the author's
     * duty at the HTML boundary; hiding stays the plugin's.
     */
    public function testAllowedMarkupPassesThroughUnchangedDeniedVanishes(): void
    {
        $markup = '<b data-x="a&b">hi</b>';
        $template = "{@can 'demo.view'}{$markup}{/can}";

        $allowed = $this->controller([
            'system_actors' => ['admin:9'],
            'super_actors' => ['admin:9'],
        ]);
        $this->assertSame($markup, $this->render($allowed, $template), 'no double-escaping by the plugin');

        $denied = $this->controller([]);
        $this->assertSame('', $this->render($denied, $template), 'denied markup never reaches the wire');
    }

    public function testDeclarationFileExistsWhereRegisterPluginLoaderLooks(): void
    {
        $this->assertFileExists(
            self::MODULE_DIR . '/plugins/Template/function.can.php',
            'Controller.php:714 composes exactly this path',
        );
    }

    // ── harness ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $config module-config slice for this scenario
     */
    private function controller(array $config): PermissionController
    {
        // every controller() owns a fresh plugin registry — otherwise the
        // PluginManager memo keeps binding the FIRST controller (see setUp)
        Template::resetPlugins();

        $configPath = $this->temp(\uniqid('perm_cfg_', true) . '.php');
        \file_put_contents($configPath, '<?php return ' . \var_export($config, true) . ';');

        $info = $this->createMock(ModuleInfo::class);
        $info->method('getPath')->willReturn(self::MODULE_DIR);
        $info->method('getCode')->willReturn('razymod/permissions');

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($info);
        $module->method('loadConfig')->willReturn(new Configuration($configPath));

        $controller = new PermissionController($module);

        // the module's own registration path (registerPluginLoader at
        // __onInit) — every render below only works if it fired
        $controller->__onInit($this->createMock(Agent::class));

        return $controller;
    }

    private function render(PermissionController $controller, string $template): string
    {
        $file = $this->temp(\uniqid('perm_tpl_', true) . '.tpl');
        \file_put_contents($file, $template);

        return (new Template())->load($file)->output();
    }

    private function temp(string $name): string
    {
        $path = \sys_get_temp_dir() . '/razy_perm_tpl_' . $name;
        $this->tempFiles[] = $path;

        return $path;
    }
}
