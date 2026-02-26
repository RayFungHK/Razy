<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Controller;
use Razy\Module;
use Razy\ModuleInfo;
use Razy\ORM\Model;
use Razy\Exception\ModuleLoadException;
use ReflectionClass;

/**
 * Tests for Controller::loadModel().
 *
 * Validates that loadModel() correctly loads anonymous Model subclasses
 * from the module's model/ directory, caches them, and throws appropriate
 * errors for invalid model files.
 */
#[CoversClass(Controller::class)]
class ControllerLoadModelTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_ctrl_model_test_' . uniqid();
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'model', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Create a Controller with a mocked Module whose ModuleInfo::getPath()
     * returns our temp directory.
     */
    private function createController(): Controller
    {
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $moduleInfo->method('getPath')->willReturn($this->tempDir);

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($moduleInfo);

        // Use Reflection to construct Controller with the mocked Module
        $ref = new ReflectionClass(Controller::class);
        $controller = $ref->newInstance($module);

        return $controller;
    }

    /**
     * Write a model PHP file into the temp model/ directory.
     */
    private function writeModelFile(string $name, string $content): void
    {
        file_put_contents(
            $this->tempDir . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . $name . '.php',
            $content
        );
    }

    // ───────────────────────────────────────────────────────────────
    // Happy path
    // ───────────────────────────────────────────────────────────────

    public function testLoadModelReturnsClassName(): void
    {
        $this->writeModelFile('Product', '<?php
use Razy\ORM\Model;
return new class extends Model {
    protected static string $table = "products";
    protected static array $fillable = ["name", "price"];
};');

        $controller = $this->createController();
        $fqcn = $controller->loadModel('Product');

        $this->assertIsString($fqcn);
        $this->assertTrue(is_subclass_of($fqcn, Model::class), 'Returned class should extend Model');
    }

    public function testLoadModelReturnsSameClassOnRepeatedCalls(): void
    {
        $this->writeModelFile('Order', '<?php
use Razy\ORM\Model;
return new class extends Model {
    protected static string $table = "orders";
};');

        $controller = $this->createController();
        $first = $controller->loadModel('Order');
        $second = $controller->loadModel('Order');

        $this->assertSame($first, $second, 'loadModel() should cache and return the same FQCN');
    }

    public function testLoadedModelHasCorrectTable(): void
    {
        $this->writeModelFile('Category', '<?php
use Razy\ORM\Model;
return new class extends Model {
    protected static string $table = "categories";
    protected static array $fillable = ["name"];
};');

        $controller = $this->createController();
        $fqcn = $controller->loadModel('Category');

        $this->assertSame('categories', $fqcn::resolveTable());
    }

    public function testLoadMultipleModels(): void
    {
        $this->writeModelFile('User', '<?php
use Razy\ORM\Model;
return new class extends Model {
    protected static string $table = "users";
    protected static array $fillable = ["name", "email"];
};');
        $this->writeModelFile('Post', '<?php
use Razy\ORM\Model;
return new class extends Model {
    protected static string $table = "posts";
    protected static array $fillable = ["title", "body"];
};');

        $controller = $this->createController();
        $User = $controller->loadModel('User');
        $Post = $controller->loadModel('Post');

        $this->assertNotSame($User, $Post);
        $this->assertSame('users', $User::resolveTable());
        $this->assertSame('posts', $Post::resolveTable());
    }

    // ───────────────────────────────────────────────────────────────
    // Error paths
    // ───────────────────────────────────────────────────────────────

    public function testLoadModelThrowsForMissingFile(): void
    {
        $controller = $this->createController();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Model file not found');

        $controller->loadModel('NonExistent');
    }

    public function testLoadModelThrowsForNonModelReturn(): void
    {
        $this->writeModelFile('BadModel', '<?php return "not a model";');

        $controller = $this->createController();

        $this->expectException(ModuleLoadException::class);
        $this->expectExceptionMessage('must return an instance of Razy\ORM\Model');

        $controller->loadModel('BadModel');
    }

    public function testLoadModelThrowsForArrayReturn(): void
    {
        $this->writeModelFile('ArrayModel', '<?php return ["table" => "users"];');

        $controller = $this->createController();

        $this->expectException(ModuleLoadException::class);
        $this->expectExceptionMessage('must return an instance of Razy\ORM\Model');

        $controller->loadModel('ArrayModel');
    }

    public function testLoadModelThrowsForWrongClassReturn(): void
    {
        $this->writeModelFile('WrongClass', '<?php return new stdClass();');

        $controller = $this->createController();

        $this->expectException(ModuleLoadException::class);
        $this->expectExceptionMessage('must return an instance of Razy\ORM\Model');

        $controller->loadModel('WrongClass');
    }
}
