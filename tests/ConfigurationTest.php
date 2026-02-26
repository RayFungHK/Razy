<?php
/**
 * Unit tests for Razy\Configuration.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Configuration;
use Razy\Exception\ConfigurationException;

#[CoversClass(Configuration::class)]
class ConfigurationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/razy-config-test-' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== PHP FORMAT ====================

    public function testLoadPHPConfig(): void
    {
        $file = $this->tempDir . '/config.php';
        file_put_contents($file, "<?php\nreturn ['name' => 'TestApp', 'version' => '1.0'];");

        $config = new Configuration($file);

        $this->assertEquals('TestApp', $config['name']);
        $this->assertEquals('1.0', $config['version']);
    }

    public function testSavePHPConfig(): void
    {
        $file = $this->tempDir . '/config.php';
        file_put_contents($file, "<?php\nreturn ['name' => 'TestApp'];");

        $config = new Configuration($file);
        $config['version'] = '2.0';
        $config->save();

        $loaded = require $file;
        $this->assertEquals('2.0', $loaded['version']);
    }

    // ==================== JSON FORMAT ====================

    public function testLoadJSONConfig(): void
    {
        $file = $this->tempDir . '/config.json';
        file_put_contents($file, json_encode(['name' => 'TestApp', 'debug' => true]));

        $config = new Configuration($file);

        $this->assertEquals('TestApp', $config['name']);
        $this->assertTrue($config['debug']);
    }

    public function testSaveJSONConfig(): void
    {
        $file = $this->tempDir . '/config.json';
        file_put_contents($file, json_encode(['name' => 'TestApp']));

        $config = new Configuration($file);
        $config['port'] = 8080;
        $config->save();

        $content = file_get_contents($file);
        $data = json_decode($content, true);

        $this->assertEquals(8080, $data['port']);
    }

    // ==================== INI FORMAT ====================

    public function testLoadINIConfig(): void
    {
        $file = $this->tempDir . '/config.ini';
        $content = <<<INI
name = "TestApp"
port = 8080

[database]
host = "localhost"
port = 3306
INI;
        file_put_contents($file, $content);

        $config = new Configuration($file);

        $this->assertEquals('TestApp', $config['name']);
        $this->assertEquals('8080', $config['port']);
        $this->assertEquals('localhost', $config['database']['host']);
    }

    public function testSaveINIConfig(): void
    {
        $file = $this->tempDir . '/config.ini';
        file_put_contents($file, "name = \"TestApp\"\n");

        $config = new Configuration($file);
        $config['version'] = '1.0';
        $config->save();

        $content = file_get_contents($file);

        // Numeric-looking values are written unquoted in INI format
        $this->assertStringContainsString('version = 1.0', $content);
    }

    // ==================== YAML FORMAT ====================

    public function testLoadYAMLConfig(): void
    {
        $file = $this->tempDir . '/config.yaml';
        $content = <<<YAML
name: TestApp
version: 1.0
database:
  host: localhost
  port: 3306
YAML;
        file_put_contents($file, $content);

        $config = new Configuration($file);

        $this->assertEquals('TestApp', $config['name']);
        $this->assertEquals(1.0, $config['version']);
        $this->assertEquals('localhost', $config['database']['host']);
        $this->assertEquals(3306, $config['database']['port']);
    }

    public function testLoadYMLConfig(): void
    {
        $file = $this->tempDir . '/config.yml';
        $content = "name: TestApp\nfeatures:\n  - auth\n  - api";
        file_put_contents($file, $content);

        $config = new Configuration($file);

        $this->assertEquals('TestApp', $config['name']);
        $this->assertEquals(['auth', 'api'], $config['features']);
    }

    public function testSaveYAMLConfig(): void
    {
        $file = $this->tempDir . '/config.yaml';
        file_put_contents($file, "name: TestApp\n");

        $config = new Configuration($file);
        $config['database'] = [
            'host' => 'localhost',
            'port' => 3306
        ];
        $config->save();

        $content = file_get_contents($file);

        $this->assertStringContainsString('database:', $content);
        $this->assertStringContainsString('host: localhost', $content);
        $this->assertStringContainsString('port: 3306', $content);
    }

    // ==================== GENERAL TESTS ====================

    public function testNewConfig(): void
    {
        $file = $this->tempDir . '/new.json';

        $config = new Configuration($file);
        $config['name'] = 'NewApp';
        $config['version'] = '1.0';
        $config->save();

        $this->assertFileExists($file);

        $reloaded = new Configuration($file);
        $this->assertEquals('NewApp', $reloaded['name']);
    }

    public function testChangeTracking(): void
    {
        $file = $this->tempDir . '/config.json';
        file_put_contents($file, '{"name":"Test"}');

        $config = new Configuration($file);
        
        // No changes yet
        $config->save();
        $modified = filemtime($file);
        sleep(1);
        
        // Make a change
        $config['version'] = '2.0';
        $config->save();
        
        $newModified = filemtime($file);
        $this->assertGreaterThan($modified, $newModified);
    }

    public function testNestedConfiguration(): void
    {
        $file = $this->tempDir . '/nested.yaml';
        $content = <<<YAML
app:
  name: MyApp
  settings:
    debug: true
    timezone: UTC
YAML;
        file_put_contents($file, $content);

        $config = new Configuration($file);

        $this->assertEquals('MyApp', $config['app']['name']);
        $this->assertTrue($config['app']['settings']['debug']);
        $this->assertEquals('UTC', $config['app']['settings']['timezone']);
    }

    public function testArrayAccess(): void
    {
        $file = $this->tempDir . '/array.json';
        file_put_contents($file, '{}');

        $config = new Configuration($file);

        $this->assertFalse(isset($config['key']));

        $config['key'] = 'value';
        $this->assertTrue(isset($config['key']));
        $this->assertEquals('value', $config['key']);

        unset($config['key']);
        $this->assertFalse(isset($config['key']));
    }

    public function testEmptyFilename(): void
    {
        // A trailing-slash path resolves to the directory basename via pathinfo,
        // so it doesn't trigger the empty filename check. Test with truly empty name.
        $this->expectException(ConfigurationException::class);

        new Configuration('');
    }

    public function testMultipleFormats(): void
    {
        $data = [
            'name' => 'TestApp',
            'version' => '1.0',
            'settings' => [
                'debug' => true,
                'port' => 8080
            ]
        ];

        // PHP
        $phpFile = $this->tempDir . '/config.php';
        file_put_contents($phpFile, "<?php\nreturn " . var_export($data, true) . ";");
        $phpConfig = new Configuration($phpFile);
        $this->assertEquals('TestApp', $phpConfig['name']);

        // JSON
        $jsonFile = $this->tempDir . '/config.json';
        file_put_contents($jsonFile, json_encode($data));
        $jsonConfig = new Configuration($jsonFile);
        $this->assertEquals('TestApp', $jsonConfig['name']);

        // YAML
        $yamlFile = $this->tempDir . '/config.yaml';
        file_put_contents($yamlFile, "name: TestApp\nversion: \"1.0\"\nsettings:\n  debug: true\n  port: 8080\n");
        $yamlConfig = new Configuration($yamlFile);
        $this->assertEquals('TestApp', $yamlConfig['name']);
    }

    public function testDirectoryCreation(): void
    {
        $file = $this->tempDir . '/subdir/config.json';

        $config = new Configuration($file);
        $config['test'] = 'value';
        $config->save();

        $this->assertFileExists($file);
        $this->assertDirectoryExists($this->tempDir . '/subdir');
    }

    public function testIteration(): void
    {
        $file = $this->tempDir . '/config.yaml';
        file_put_contents($file, "a: 1\nb: 2\nc: 3\n");

        $config = new Configuration($file);

        $result = [];
        foreach ($config as $key => $value) {
            $result[$key] = $value;
        }

        $this->assertEquals(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function testComplexNestedData(): void
    {
        $file = $this->tempDir . '/complex.yaml';

        $config = new Configuration($file);
        $config['database'] = [
            'connections' => [
                'mysql' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'credentials' => [
                        'username' => 'root',
                        'password' => 'secret'
                    ]
                ]
            ]
        ];
        $config->save();

        $reloaded = new Configuration($file);
        $this->assertEquals('localhost', $reloaded['database']['connections']['mysql']['host']);
        $this->assertEquals('root', $reloaded['database']['connections']['mysql']['credentials']['username']);
    }
}
