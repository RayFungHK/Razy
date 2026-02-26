<?php
/**
 * Unit tests for Razy\Config\ConfigLoader.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Config\ConfigLoader;
use Razy\Exception\ConfigurationException;

#[CoversClass(ConfigLoader::class)]
class ConfigLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/razy_config_loader_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testLoadReturnsArray(): void
    {
        $file = $this->tempDir . '/config.php';
        file_put_contents($file, '<?php return ["key" => "value"];');

        $loader = new ConfigLoader();
        $result = $loader->load($file);

        $this->assertSame(['key' => 'value'], $result);
    }

    public function testLoadNonArrayReturnsEmptyArray(): void
    {
        $file = $this->tempDir . '/config.php';
        file_put_contents($file, '<?php return "not an array";');

        $loader = new ConfigLoader();
        $result = $loader->load($file);

        $this->assertSame([], $result);
    }

    public function testLoadThrowsOnMissingFile(): void
    {
        $loader = new ConfigLoader();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Config not found');
        $loader->load('/nonexistent/path/config.php');
    }

    public function testLoadRawReturnsRawValue(): void
    {
        $file = $this->tempDir . '/raw.php';
        file_put_contents($file, '<?php return "a string";');

        $loader = new ConfigLoader();
        $result = $loader->loadRaw($file);

        $this->assertSame('a string', $result);
    }

    public function testLoadRawReturnsArray(): void
    {
        $file = $this->tempDir . '/raw_array.php';
        file_put_contents($file, '<?php return ["a" => 1, "b" => 2];');

        $loader = new ConfigLoader();
        $result = $loader->loadRaw($file);

        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testLoadRawThrowsOnMissingFile(): void
    {
        $loader = new ConfigLoader();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Config not found');
        $loader->loadRaw('/nonexistent/path/raw.php');
    }

    public function testLoadCanBeMocked(): void
    {
        // Verify that ConfigLoader can be mocked for DI
        $mock = $this->createMock(ConfigLoader::class);
        $mock->method('load')
            ->with('/fake/path.php')
            ->willReturn(['mocked' => true]);

        $result = $mock->load('/fake/path.php');
        $this->assertSame(['mocked' => true], $result);
    }

    public function testLoadRawCanBeMocked(): void
    {
        $mock = $this->createMock(ConfigLoader::class);
        $mock->method('loadRaw')
            ->with('/fake/package.php')
            ->willReturn(['module_code' => 'vendor/test']);

        $result = $mock->loadRaw('/fake/package.php');
        $this->assertSame(['module_code' => 'vendor/test'], $result);
    }

    public function testLoadComplexConfig(): void
    {
        $file = $this->tempDir . '/complex.php';
        $content = <<<'PHP'
<?php
return [
    'dist' => 'test-dist',
    'modules' => [
        '*' => ['vendor/core' => '1.0.0'],
    ],
    'fallback' => true,
];
PHP;
        file_put_contents($file, $content);

        $loader = new ConfigLoader();
        $result = $loader->load($file);

        $this->assertSame('test-dist', $result['dist']);
        $this->assertTrue($result['fallback']);
        $this->assertArrayHasKey('*', $result['modules']);
    }
}
