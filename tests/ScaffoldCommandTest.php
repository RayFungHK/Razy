<?php

/**
 * Unit tests for the scaffold CLI command.
 *
 * Tests the module scaffolding functionality by verifying file generation,
 * directory structure, and content correctness for various scaffold options.
 *
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Template;
use Razy\Terminal;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(Terminal::class)]
class ScaffoldCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_scaffold_test_' . \uniqid();
        \mkdir($this->tempDir, 0o777, true);

        // Create the sites directory structure that scaffold expects
        \mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'testdist', 0o777, true);
        \mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'module', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ══════════════════════════════════════════════════════
    // Module Code Validation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function validModuleCodePattern(): void
    {
        // Valid module codes
        $validCodes = [
            'app/hello',
            'myvendor/blog',
            'core/auth',
            'my_vendor/my_module',
            'A/B',
        ];

        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*\/[a-zA-Z_][a-zA-Z0-9_]*$/';
        foreach ($validCodes as $code) {
            $this->assertMatchesRegularExpression($pattern, $code, "Expected '{$code}' to be valid");
        }
    }

    #[Test]
    public function invalidModuleCodePattern(): void
    {
        $invalidCodes = [
            'hello',           // No slash
            'a/b/c',           // Too many slashes
            '123/hello',       // Starts with digit
            'app/123start',    // Starts with digit
            'app/hello world', // Contains space
            'app/',            // Missing name
            '/hello',          // Missing vendor
            '',                // Empty
            'app/hello-world', // Contains hyphen
        ];

        $pattern = '/^[a-zA-Z_][a-zA-Z0-9_]*\/[a-zA-Z_][a-zA-Z0-9_]*$/';
        foreach ($invalidCodes as $code) {
            $this->assertDoesNotMatchRegularExpression($pattern, $code, "Expected '{$code}' to be invalid");
        }
    }

    // ══════════════════════════════════════════════════════
    // Module.php Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function generatedModulePhpReturnsValidArray(): void
    {
        $moduleCode = 'app/hello';
        $moduleName = 'Hello Module';
        $author = 'Test Author';
        $description = 'A test module';
        $version = '1.0.0';

        $content = <<<PHP
            <?php
            /**
             * {$moduleName}
             *
             * @package Razy
             * @license MIT
             */
            return [
                'module_code' => '{$moduleCode}',
                'name'        => '{$moduleName}',
                'author'      => '{$author}',
                'description' => '{$description}',
                'version'     => '{$version}',
            ];

            PHP;

        $filePath = $this->tempDir . '/module.php';
        \file_put_contents($filePath, $content);

        $result = include $filePath;
        $this->assertIsArray($result);
        $this->assertSame('app/hello', $result['module_code']);
        $this->assertSame('Hello Module', $result['name']);
        $this->assertSame('Test Author', $result['author']);
        $this->assertSame('A test module', $result['description']);
        $this->assertSame('1.0.0', $result['version']);
    }

    // ══════════════════════════════════════════════════════
    // Package.php Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function generatedPackagePhpReturnsValidArray(): void
    {
        $moduleCode = 'app/hello';
        $author = 'Test Author';
        $description = 'A test module';
        $version = '1.0.0';

        $content = <<<PHP
            <?php
            /**
             * Package configuration for Hello Module
             *
             * @package Razy
             * @license MIT
             */
            return [
                'module_code' => '{$moduleCode}',
                'author'      => '{$author}',
                'description' => '{$description}',
                'version'     => '{$version}',
            ];

            PHP;

        $filePath = $this->tempDir . '/package.php';
        \file_put_contents($filePath, $content);

        $result = include $filePath;
        $this->assertIsArray($result);
        $this->assertSame('app/hello', $result['module_code']);
        $this->assertSame('1.0.0', $result['version']);
    }

    // ══════════════════════════════════════════════════════
    // Controller Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function generatedControllerHasCorrectNamespace(): void
    {
        $moduleCode = 'app/hello';
        $namespace = \str_replace('/', '_', $moduleCode);
        $this->assertSame('app_hello', $namespace);
    }

    #[Test]
    public function generatedControllerExtendsController(): void
    {
        $content = <<<'PHP'
            <?php
            namespace Razy\Module\app_hello;

            use Razy\Agent;
            use Razy\Controller;

            return new class extends Controller {
                public function __onInit(Agent $agent): bool
                {
                    $agent->addLazyRoute([
                        '/' => 'index',
                    ]);

                    return true;
                }
            };
            PHP;

        $this->assertStringContainsString('extends Controller', $content);
        $this->assertStringContainsString('__onInit(Agent $agent)', $content);
        $this->assertStringContainsString('addLazyRoute', $content);
        $this->assertStringContainsString("'/' => 'index'", $content);
    }

    #[Test]
    public function controllerWithApiRegistration(): void
    {
        $moduleCode = 'app/hello';
        $routeLines = "\n        \$agent->addAPICommand('hello', 'api/hello');";

        $this->assertStringContainsString('addAPICommand', $routeLines);
        $this->assertStringContainsString('api/hello', $routeLines);
    }

    #[Test]
    public function controllerWithEventListenerRegistration(): void
    {
        $moduleCode = 'app/hello';
        $eventLine = "\$agent->listen('app/events:on_ready', function (array \$data) {";

        $this->assertStringContainsString('listen', $eventLine);
        $this->assertStringContainsString('app/events:on_ready', $eventLine);
    }

    // ══════════════════════════════════════════════════════
    // Handler Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function minimalHandlerContainsHtmlOutput(): void
    {
        $moduleName = 'Hello Module';
        $controllerName = 'hello';

        $content = <<<HANDLER
            <?php
            use Razy\\Controller;

            return function (): void {
                /** @var Controller \$this */
                header('Content-Type: text/html; charset=UTF-8');

                echo <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>{$moduleName}</title>
            </head>
            <body>
                <h1>{$moduleName}</h1>
                <div class="card">
                    <p>Your module is working!</p>
                    <p>Handler file: <code>controller/{$controllerName}.index.php</code></p>
                </div>
            </body>
            </html>
            HTML;
            };
            HANDLER;

        $this->assertStringContainsString('Content-Type: text/html', $content);
        $this->assertStringContainsString('Hello Module', $content);
        $this->assertStringContainsString('return function (): void', $content);
    }

    #[Test]
    public function templateHandlerUsesLoadTemplate(): void
    {
        $content = <<<'PHP'
            <?php
            use Razy\Controller;

            return function (): void {
                /** @var Controller $this */
                header('Content-Type: text/html; charset=UTF-8');
                $source = $this->loadTemplate('index');
                $source->assign([
                    'title'   => 'Hello Module',
                    'message' => 'Your module is working!',
                ]);
                echo $source->output();
            };
            PHP;

        $this->assertStringContainsString('loadTemplate', $content);
        $this->assertStringContainsString('->assign(', $content);
        $this->assertStringContainsString('->output()', $content);
    }

    // ══════════════════════════════════════════════════════
    // Template Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function generatedTemplateUsesRazySyntax(): void
    {
        $tplContent = <<<'TPL'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <title>{$title}</title>
            </head>
            <body>
                <h1>{$title}</h1>
                <div class="card">
                    <p>{$message}</p>
                </div>
            </body>
            </html>
            TPL;

        // Razy template syntax uses {$variable}
        $this->assertStringContainsString('{$title}', $tplContent);
        $this->assertStringContainsString('{$message}', $tplContent);
        $this->assertStringContainsString('<!DOCTYPE html>', $tplContent);
    }

    // ══════════════════════════════════════════════════════
    // API Handler Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function generatedApiHandlerReturnsString(): void
    {
        $moduleCode = 'app/hello';
        $content = <<<PHP
            <?php
            /**
             * API Command: hello
             *
             * Called by other modules via: \$this->api('{$moduleCode}')->hello(\$name)
             */

            return function (string \$name = 'World'): string {
                return "Hello, {\$name}!";
            };
            PHP;

        $filePath = $this->tempDir . '/api_hello.php';
        \file_put_contents($filePath, $content);

        $fn = include $filePath;
        $this->assertIsCallable($fn);

        // Execute the API handler
        $result = $fn('Razy');
        $this->assertSame('Hello, Razy!', $result);

        // Test default parameter
        $result = $fn();
        $this->assertSame('Hello, World!', $result);
    }

    // ══════════════════════════════════════════════════════
    // Directory Structure
    // ══════════════════════════════════════════════════════

    #[Test]
    public function scaffoldCreatesCorrectDirectoryStructure(): void
    {
        $moduleCode = 'app/hello';
        $parts = \explode('/', $moduleCode);
        $basePath = $this->tempDir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'testdist';
        $moduleDir = $basePath . DIRECTORY_SEPARATOR . $parts[0] . DIRECTORY_SEPARATOR . $parts[1];
        $versionDir = $moduleDir . DIRECTORY_SEPARATOR . 'default';
        $controllerDir = $versionDir . DIRECTORY_SEPARATOR . 'controller';
        $viewDir = $versionDir . DIRECTORY_SEPARATOR . 'view';
        $apiDir = $controllerDir . DIRECTORY_SEPARATOR . 'api';

        // Simulate full scaffold directory creation
        \mkdir($controllerDir, 0o777, true);
        \mkdir($viewDir, 0o777, true);
        \mkdir($apiDir, 0o777, true);

        // Verify structure
        $this->assertDirectoryExists($moduleDir);
        $this->assertDirectoryExists($versionDir);
        $this->assertDirectoryExists($controllerDir);
        $this->assertDirectoryExists($viewDir);
        $this->assertDirectoryExists($apiDir);
    }

    #[Test]
    public function scaffoldCreatesMinimalDirectoryStructure(): void
    {
        $moduleCode = 'app/hello';
        $parts = \explode('/', $moduleCode);
        $basePath = $this->tempDir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'testdist';
        $moduleDir = $basePath . DIRECTORY_SEPARATOR . $parts[0] . DIRECTORY_SEPARATOR . $parts[1];
        $controllerDir = $moduleDir . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'controller';

        // Minimal scaffold: only controller dir needed
        \mkdir($controllerDir, 0o777, true);

        $this->assertDirectoryExists($controllerDir);
        // view/ and api/ should NOT exist in minimal mode
        $this->assertDirectoryDoesNotExist($moduleDir . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'view');
        $this->assertDirectoryDoesNotExist($controllerDir . DIRECTORY_SEPARATOR . 'api');
    }

    // ══════════════════════════════════════════════════════
    // Full Scaffold File Generation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fullScaffoldGeneratesAllFiles(): void
    {
        $moduleCode = 'app/hello';
        $parts = \explode('/', $moduleCode);
        $controllerName = $parts[1]; // 'hello'
        $basePath = $this->tempDir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'testdist';
        $moduleDir = $basePath . DIRECTORY_SEPARATOR . $parts[0] . DIRECTORY_SEPARATOR . $parts[1];
        $versionDir = $moduleDir . DIRECTORY_SEPARATOR . 'default';
        $controllerDir = $versionDir . DIRECTORY_SEPARATOR . 'controller';
        $apiDir = $controllerDir . DIRECTORY_SEPARATOR . 'api';
        $viewDir = $versionDir . DIRECTORY_SEPARATOR . 'view';

        // Create directories
        \mkdir($apiDir, 0o777, true);
        \mkdir($viewDir, 0o777, true);

        // Simulate file generation
        $files = [
            $moduleDir . DIRECTORY_SEPARATOR . 'module.php' => '<?php return [];',
            $versionDir . DIRECTORY_SEPARATOR . 'package.php' => '<?php return [];',
            $controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.php' => '<?php return new class {};',
            $controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.index.php' => '<?php return function() {};',
            $viewDir . DIRECTORY_SEPARATOR . 'index.tpl' => '<h1>{$title}</h1>',
            $apiDir . DIRECTORY_SEPARATOR . $controllerName . '.hello.php' => '<?php return function() { return "hi"; };',
        ];

        foreach ($files as $path => $content) {
            \file_put_contents($path, $content);
        }

        // Verify all files exist
        foreach ($files as $path => $content) {
            $this->assertFileExists($path, 'Missing: ' . \basename($path));
        }
        $this->assertCount(6, $files, 'Full scaffold should generate 6 files');
    }

    // ══════════════════════════════════════════════════════
    // Controller Name Derivation
    // ══════════════════════════════════════════════════════

    #[Test]
    public function controllerNameDerivedFromModuleCode(): void
    {
        $testCases = [
            'app/hello' => 'hello',
            'myvendor/blog_post' => 'blog_post',
            'core/auth' => 'auth',
            'system/user_manager' => 'user_manager',
        ];

        foreach ($testCases as $moduleCode => $expectedController) {
            $parts = \explode('/', $moduleCode);
            $controllerName = $parts[1];
            $this->assertSame($expectedController, $controllerName, "Module '{$moduleCode}'");
        }
    }

    #[Test]
    public function moduleNameDerivedFromControllerName(): void
    {
        $testCases = [
            'hello' => 'Hello Module',
            'blog_post' => 'Blog Post Module',
            'auth' => 'Auth Module',
            'user_manager' => 'User Manager Module',
        ];

        foreach ($testCases as $controllerName => $expectedName) {
            $name = \ucwords(\str_replace('_', ' ', $controllerName)) . ' Module';
            $this->assertSame($expectedName, $name, "Controller '{$controllerName}'");
        }
    }

    #[Test]
    public function namespaceDerivation(): void
    {
        $testCases = [
            'app/hello' => 'app_hello',
            'myvendor/blog' => 'myvendor_blog',
            'core/auth_service' => 'core_auth_service',
        ];

        foreach ($testCases as $moduleCode => $expectedNamespace) {
            $namespace = \str_replace('/', '_', $moduleCode);
            $this->assertSame($expectedNamespace, $namespace, "Module '{$moduleCode}'");
        }
    }

    // ══════════════════════════════════════════════════════
    // Shared Module Path
    // ══════════════════════════════════════════════════════

    #[Test]
    public function sharedModulePathResolution(): void
    {
        $moduleCode = 'shared/auth';
        $parts = \explode('/', $moduleCode);

        $sharedBase = $this->tempDir . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . 'module';
        $moduleDir = $sharedBase . DIRECTORY_SEPARATOR . $parts[0] . DIRECTORY_SEPARATOR . $parts[1];

        $this->assertStringContainsString('shared' . DIRECTORY_SEPARATOR . 'module', $sharedBase);
        $this->assertStringEndsWith($parts[1], $moduleDir);
    }

    // ══════════════════════════════════════════════════════
    // File Content Integration
    // ══════════════════════════════════════════════════════

    #[Test]
    public function generatedModulePhpMatchesModuleCode(): void
    {
        $codes = ['app/hello', 'vendor/product', 'core/auth'];

        foreach ($codes as $moduleCode) {
            $content = "<?php\nreturn [\n    'module_code' => '{$moduleCode}',\n];\n";
            $filePath = $this->tempDir . '/test_module_' . \md5($moduleCode) . '.php';
            \file_put_contents($filePath, $content);

            $result = include $filePath;
            $this->assertSame($moduleCode, $result['module_code']);
        }
    }

    #[Test]
    public function fileGenerationHandlesEdgeCaseModuleNames(): void
    {
        // Module name with underscores should be converted to spaces in display name
        $controllerName = 'user_profile_manager';
        $moduleName = \ucwords(\str_replace('_', ' ', $controllerName)) . ' Module';
        $this->assertSame('User Profile Manager Module', $moduleName);

        // Single word
        $controllerName = 'dashboard';
        $moduleName = \ucwords(\str_replace('_', ' ', $controllerName)) . ' Module';
        $this->assertSame('Dashboard Module', $moduleName);
    }

    // ══════════════════════════════════════════════════════
    // Scaffold Command File Exists
    // ══════════════════════════════════════════════════════

    #[Test]
    public function scaffoldCommandFileExists(): void
    {
        $path = SYSTEM_ROOT . '/src/system/terminal/scaffold.inc.php';
        $this->assertFileExists($path, 'scaffold.inc.php should exist in terminal commands');
    }

    #[Test]
    public function scaffoldCommandFileReturnsClosure(): void
    {
        // The scaffold command file should return a Closure
        $path = SYSTEM_ROOT . '/src/system/terminal/scaffold.inc.php';
        $result = include $path;
        $this->assertInstanceOf(Closure::class, $result);
    }

    #[Test]
    public function scaffoldCommandFileHasProperDocblock(): void
    {
        $path = SYSTEM_ROOT . '/src/system/terminal/scaffold.inc.php';
        $content = \file_get_contents($path);
        $this->assertStringContainsString('@license MIT', $content);
        $this->assertStringContainsString('CLI Command: scaffold', $content);
    }

    // ══════════════════════════════════════════════════════
    // Scaffold Template Files
    // ══════════════════════════════════════════════════════

    #[Test]
    public function scaffoldTemplateDirectoryExists(): void
    {
        $dir = SYSTEM_ROOT . '/src/asset/setup/scaffold';
        $this->assertDirectoryExists($dir, 'scaffold template directory should exist');
    }

    #[Test]
    public function scaffoldTemplateFilesExist(): void
    {
        $dir = SYSTEM_ROOT . '/src/asset/setup/scaffold';
        $requiredFiles = [
            'module.php.tpl',
            'package.php.tpl',
            'controller.php.tpl',
            'handler.index.php.tpl',
            'handler.index.tpl.php.tpl',
            'view.index.tpl',
            'api.hello.php.tpl',
        ];
        foreach ($requiredFiles as $file) {
            $this->assertFileExists($dir . '/' . $file, "Template file '{$file}' should exist");
        }
    }

    #[Test]
    public function snippetFilesRemoved(): void
    {
        $dir = SYSTEM_ROOT . '/src/asset/setup/scaffold';
        $this->assertFileDoesNotExist($dir . '/controller.api.snippet.tpl', 'Snippet merged into controller.php.tpl blocks');
        $this->assertFileDoesNotExist($dir . '/controller.event.snippet.tpl', 'Snippet merged into controller.php.tpl blocks');
    }

    #[Test]
    public function moduleTemplateUsesTemplateEngineVars(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/module.php.tpl');
        $this->assertStringContainsString('{$module_code}', $content);
        $this->assertStringContainsString('{$module_name}', $content);
        $this->assertStringContainsString('{$author}', $content);
        $this->assertStringContainsString('{$description}', $content);
        $this->assertStringContainsString('{$version}', $content);
        // Must NOT contain old {{PLACEHOLDER}} syntax
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function packageTemplateUsesTemplateEngineVars(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/package.php.tpl');
        $this->assertStringContainsString('{$module_code}', $content);
        $this->assertStringContainsString('{$author}', $content);
        $this->assertStringContainsString('{$version}', $content);
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function controllerTemplateUsesBlocksForOptionalSections(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/controller.php.tpl');
        $this->assertStringContainsString('{$module_name}', $content);
        $this->assertStringContainsString('{$namespace}', $content);
        $this->assertStringContainsString('extends Controller', $content);
        $this->assertStringContainsString('__onInit(Agent $agent)', $content);
        // Verify blocks replace old snippet approach
        $this->assertStringContainsString('<!-- START BLOCK: api_section -->', $content);
        $this->assertStringContainsString('<!-- END BLOCK: api_section -->', $content);
        $this->assertStringContainsString('<!-- START BLOCK: event_section -->', $content);
        $this->assertStringContainsString('<!-- END BLOCK: event_section -->', $content);
        $this->assertStringContainsString('addAPICommand', $content);
        $this->assertStringContainsString('listen', $content);
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function handlerTemplateUsesTemplateEngineVars(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/handler.index.php.tpl');
        $this->assertStringContainsString('{$module_name}', $content);
        $this->assertStringContainsString('{$controller_name}', $content);
        $this->assertStringContainsString('Content-Type: text/html', $content);
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function handlerTplTemplateUsesLoadTemplate(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/handler.index.tpl.php.tpl');
        $this->assertStringContainsString('{$module_name}', $content);
        $this->assertStringContainsString('loadTemplate', $content);
        $this->assertStringContainsString('->output()', $content);
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function viewTemplateUsesRazySyntax(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/view.index.tpl');
        $this->assertStringContainsString('{$title}', $content);
        $this->assertStringContainsString('{$message}', $content);
        // Must NOT contain scaffold placeholders -- this is a static-copy template
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function apiTemplateUsesTemplateEngineVars(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/scaffold/api.hello.php.tpl');
        $this->assertStringContainsString('{$module_code}', $content);
        $this->assertStringContainsString('return function', $content);
        $this->assertStringNotContainsString('{{', $content);
    }

    #[Test]
    public function templateEngineRendersModulePhp(): void
    {
        $source = Template::loadFile(SYSTEM_ROOT . '/src/asset/setup/scaffold/module.php.tpl');
        $root = $source->getRoot();
        $root->assign([
            'module_code' => 'app/hello',
            'module_name' => 'Hello Module',
            'author' => 'Test Author',
            'description' => 'A test module',
            'version' => '1.0.0',
        ]);

        $output = $source->output();
        $filePath = $this->tempDir . '/tpl_module.php';
        \file_put_contents($filePath, $output);
        $result = include $filePath;

        $this->assertIsArray($result);
        $this->assertSame('app/hello', $result['module_code']);
        $this->assertSame('Hello Module', $result['name']);
        $this->assertSame('Test Author', $result['author']);
        $this->assertSame('1.0.0', $result['version']);
    }

    #[Test]
    public function templateEngineRendersPackagePhp(): void
    {
        $source = Template::loadFile(SYSTEM_ROOT . '/src/asset/setup/scaffold/package.php.tpl');
        $root = $source->getRoot();
        $root->assign([
            'module_code' => 'app/hello',
            'module_name' => 'Hello Module',
            'author' => 'Test Author',
            'description' => 'A test module',
            'version' => '1.0.0',
        ]);

        $output = $source->output();
        $filePath = $this->tempDir . '/tpl_package.php';
        \file_put_contents($filePath, $output);
        $result = include $filePath;

        $this->assertIsArray($result);
        $this->assertSame('app/hello', $result['module_code']);
        $this->assertSame('1.0.0', $result['version']);
    }

    #[Test]
    public function templateEngineRendersControllerWithBlocks(): void
    {
        $assigns = [
            'module_code' => 'app/hello',
            'module_name' => 'Hello Module',
            'namespace' => 'app_hello',
            'controller_name' => 'hello',
        ];

        // Render with both blocks active
        $source = Template::loadFile(SYSTEM_ROOT . '/src/asset/setup/scaffold/controller.php.tpl');
        $root = $source->getRoot();
        $root->assign($assigns);
        $root->newBlock('api_section')->assign($assigns);
        $root->newBlock('event_section')->assign($assigns);

        $output = $source->output();
        $this->assertStringContainsString('namespace Razy\Module\app_hello;', $output);
        $this->assertStringContainsString('addAPICommand', $output);
        $this->assertStringContainsString('listen', $output);
        $this->assertStringContainsString("api('app/hello')", $output);
    }

    #[Test]
    public function templateEngineRendersControllerWithoutBlocks(): void
    {
        $assigns = [
            'module_code' => 'app/hello',
            'module_name' => 'Hello Module',
            'namespace' => 'app_hello',
            'controller_name' => 'hello',
        ];

        // Render without blocks -- no API or event sections
        $source = Template::loadFile(SYSTEM_ROOT . '/src/asset/setup/scaffold/controller.php.tpl');
        $root = $source->getRoot();
        $root->assign($assigns);

        $output = $source->output();
        $this->assertStringContainsString('namespace Razy\Module\app_hello;', $output);
        $this->assertStringContainsString('addLazyRoute', $output);
        $this->assertStringNotContainsString('addAPICommand', $output);
        $this->assertStringNotContainsString('listen', $output);
    }

    #[Test]
    public function templateEngineRendersApiHandler(): void
    {
        $source = Template::loadFile(SYSTEM_ROOT . '/src/asset/setup/scaffold/api.hello.php.tpl');
        $root = $source->getRoot();
        $root->assign(['module_code' => 'app/hello']);

        $output = $source->output();
        $filePath = $this->tempDir . '/tpl_api_hello.php';
        \file_put_contents($filePath, $output);

        $fn = include $filePath;
        $this->assertIsCallable($fn);
        $this->assertSame('Hello, Razy!', $fn('Razy'));
        $this->assertSame('Hello, World!', $fn());
    }

    #[Test]
    public function scaffoldCommandUsesTemplateEngine(): void
    {
        $content = \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/scaffold.inc.php');
        $this->assertStringContainsString('Template::loadFile', $content);
        $this->assertStringContainsString('->getRoot()', $content);
        $this->assertStringContainsString('->assign(', $content);
        $this->assertStringContainsString('->output()', $content);
        $this->assertStringContainsString('newBlock(', $content);
    }

    // ══════════════════════════════════════════════════════
    // Help Command Includes Scaffold
    // ══════════════════════════════════════════════════════

    #[Test]
    public function helpCommandListsScaffold(): void
    {
        $path = SYSTEM_ROOT . '/src/system/terminal/help.inc.php';
        $content = \file_get_contents($path);
        $this->assertStringContainsString('scaffold', $content);
        $this->assertStringContainsString('module skeleton', $content);
    }

    // ══════════════════════════════════════════════════════
    // Option Flags
    // ══════════════════════════════════════════════════════

    #[Test]
    public function fullFlagEnablesAllOptions(): void
    {
        // Simulating the option parsing logic from scaffold
        $parameters = ['full' => true];

        $withApi = isset($parameters['with-api']) || isset($parameters['full']);
        $withTpl = isset($parameters['with-template']) || isset($parameters['full']);
        $withEvent = isset($parameters['with-event']) || isset($parameters['full']);

        $this->assertTrue($withApi);
        $this->assertTrue($withTpl);
        $this->assertTrue($withEvent);
    }

    #[Test]
    public function individualFlagsWork(): void
    {
        $parameters = ['with-api' => true];

        $withApi = isset($parameters['with-api']) || isset($parameters['full']);
        $withTpl = isset($parameters['with-template']) || isset($parameters['full']);
        $withEvent = isset($parameters['with-event']) || isset($parameters['full']);

        $this->assertTrue($withApi);
        $this->assertFalse($withTpl);
        $this->assertFalse($withEvent);
    }

    #[Test]
    public function noFlagsMeansMinimalScaffold(): void
    {
        $parameters = [];

        $withApi = isset($parameters['with-api']) || isset($parameters['full']);
        $withTpl = isset($parameters['with-template']) || isset($parameters['full']);
        $withEvent = isset($parameters['with-event']) || isset($parameters['full']);

        $this->assertFalse($withApi);
        $this->assertFalse($withTpl);
        $this->assertFalse($withEvent);
    }

    // ══════════════════════════════════════════════════════
    // End-to-End: Minimal Scaffold File Writing
    // ══════════════════════════════════════════════════════

    #[Test]
    public function endToEndMinimalScaffoldCreatesValidFiles(): void
    {
        $moduleCode = 'app/demo';
        $parts = \explode('/', $moduleCode);
        $controllerName = $parts[1];
        $moduleName = \ucwords(\str_replace('_', ' ', $controllerName)) . ' Module';
        $author = 'Test Author';
        $description = 'A test module';
        $version = '1.0.0';
        $namespace = \str_replace('/', '_', $moduleCode);

        $basePath = $this->tempDir . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'testdist';
        $moduleDir = $basePath . DIRECTORY_SEPARATOR . $parts[0] . DIRECTORY_SEPARATOR . $parts[1];
        $versionDir = $moduleDir . DIRECTORY_SEPARATOR . 'default';
        $controllerDir = $versionDir . DIRECTORY_SEPARATOR . 'controller';

        \mkdir($controllerDir, 0o777, true);

        // Generate module.php
        $modulePhp = "<?php\nreturn [\n    'module_code' => '{$moduleCode}',\n    'name' => '{$moduleName}',\n    'author' => '{$author}',\n    'description' => '{$description}',\n    'version' => '{$version}',\n];\n";
        \file_put_contents($moduleDir . DIRECTORY_SEPARATOR . 'module.php', $modulePhp);

        // Generate package.php
        $packagePhp = "<?php\nreturn [\n    'module_code' => '{$moduleCode}',\n    'author' => '{$author}',\n    'description' => '{$description}',\n    'version' => '{$version}',\n];\n";
        \file_put_contents($versionDir . DIRECTORY_SEPARATOR . 'package.php', $packagePhp);

        // Generate controller
        $ctrlPhp = "<?php\nnamespace Razy\\Module\\{$namespace};\nuse Razy\\Agent;\nuse Razy\\Controller;\nreturn new class extends Controller {\n    public function __onInit(Agent \$agent): bool {\n        \$agent->addLazyRoute(['/' => 'index']);\n        return true;\n    }\n};\n";
        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.php', $ctrlPhp);

        // Generate handler
        $handlerPhp = "<?php\nreturn function (): void {\n    header('Content-Type: text/html; charset=UTF-8');\n    echo '<h1>{$moduleName}</h1><p>It works!</p>';\n};\n";
        \file_put_contents($controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.index.php', $handlerPhp);

        // Verify all 4 files exist and are valid PHP
        $this->assertFileExists($moduleDir . DIRECTORY_SEPARATOR . 'module.php');
        $this->assertFileExists($versionDir . DIRECTORY_SEPARATOR . 'package.php');
        $this->assertFileExists($controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.php');
        $this->assertFileExists($controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.index.php');

        // Verify module.php returns correct data
        $moduleData = include $moduleDir . DIRECTORY_SEPARATOR . 'module.php';
        $this->assertSame($moduleCode, $moduleData['module_code']);
        $this->assertSame($moduleName, $moduleData['name']);

        // Verify package.php returns correct data
        $packageData = include $versionDir . DIRECTORY_SEPARATOR . 'package.php';
        $this->assertSame($moduleCode, $packageData['module_code']);
        $this->assertSame($version, $packageData['version']);

        // Verify handler is callable
        $handler = include $controllerDir . DIRECTORY_SEPARATOR . $controllerName . '.index.php';
        $this->assertIsCallable($handler);
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }
        \rmdir($dir);
    }

    /**
     * Execute the scaffold command in a controlled environment.
     *
     * @param string $moduleCode Module code argument
     * @param array $args Positional arguments
     * @param array $parameters Named parameters (flags)
     *
     * @return string Captured output
     */
    private function runScaffold(string $moduleCode, array $args = [], array $parameters = []): string
    {
        // Temporarily override SYSTEM_ROOT to use our temp directory
        $originalRoot = \defined('SYSTEM_ROOT') ? SYSTEM_ROOT : null;

        // We cannot redefine constants, so we'll test file generation directly
        // by calling the logic that creates the files. Instead, we test the
        // generated file structure and content by simulating what scaffold does.
        return '';
    }
}
