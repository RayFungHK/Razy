<?php

/**
 * RZ-17: a malformed sites.inc.php fails fast and names the file.
 *
 * DOGFOOD #1: a sites.inc.php without `return` evaluated to int 1, which hit
 * `Application::$config` (?array) as a TypeError that never mentioned the
 * file. String domain values and bad dist entries were dropped silently.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Razy\Application;
use Razy\Config\DomainList;
use Razy\Config\SiteConfig;
use Razy\Error\ErrorReporter;
use Razy\Exception\ConfigurationException;
use Razy\Logger;
use ReflectionProperty;

#[CoversClass(SiteConfig::class)]
final class SiteConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/rz17_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @\unlink($this->dir . '/sites.inc.php');
        @\rmdir($this->dir);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function malformed(): array
    {
        return [
            'domains not array' => [['domains' => 'example.com'], "'domains' must be an array, got string"],
            'alias not array' => [['domains' => [], 'alias' => 'a.com'], "'alias' must be an array, got string"],
            'exclude_paths not array' => [['exclude_paths' => '/api'], "'exclude_paths' must be an array"],
            'string domain value' => [['domains' => ['example.com' => 'main']], "'domains' => 'example.com' must be an array of URL path => dist code"],
            'int dist value' => [['domains' => ['example.com' => ['/' => 5]]], 'domains.example.com[/] must be a dist code string or a nested array, got int'],
            'bad dist identifier' => [['domains' => ['example.com' => ['/' => 'my site']]], "domains.example.com[/]: 'my site' is not a dist identifier"],
            'bad nested identifier' => [['domains' => ['example.com' => ['/' => ['/docs' => '../etc']]]], "domains.example.com[/][/docs]: '../etc' is not a dist identifier"],
            'non-string alias target' => [['alias' => ['a.example.com' => ['b']]], "'alias' => 'a.example.com' must map to a domain string, got array"],
            'null return' => [null, 'it returned null'],
        ];
    }

    public function testFileWithoutReturnNamesTheFileAndShape(): void
    {
        $file = $this->write("<?php\n\$x = ['domains' => ['*' => ['/' => 'main']]];\n");

        try {
            SiteConfig::validate(require $file, $file);
            $this->fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString($file, $e->getMessage());
            $this->assertStringContainsString("must return ['domains' => [...], 'alias' => [...]]", $e->getMessage());
            $this->assertStringContainsString('returned int (missing `return`?)', $e->getMessage());
        }
    }

    #[DataProvider('malformed')]
    public function testShapeErrorsThrow(mixed $config, string $detail): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($detail);
        SiteConfig::validate($config, '/srv/app/sites.inc.php');
    }

    public function testValidConfigsPassAndMissingSectionsAreFilled(): void
    {
        $config = SiteConfig::validate([
            'domains' => [
                '*' => ['/' => 'main'],
                'example.com' => ['/' => 'main@v2', '/docs' => 'docs@1.2.0', '/nested' => ['/a' => 'a-dist']],
            ],
        ], 'sites.inc.php');

        $this->assertSame([], $config['alias']);
        $this->assertSame([], $config['exclude_paths']);
        $this->assertSame('main@v2', $config['domains']['example.com']['/']);
    }

    public function testDomainListOutputIsValid(): void
    {
        $domains = DomainList::parse('app.example.com, *.example.com, localhost:8193, docs.example.com=docs@v2', ['/' => 'main']);

        $config = SiteConfig::validate(['domains' => $domains, 'alias' => []], 'sites.inc.php');
        $this->assertSame($domains, $config['domains']);
    }

    public function testSkippedEntriesAreWarnedNotFatal(): void
    {
        $log = new Logger(null, 'debug', 'Y-m-d', true);
        ErrorReporter::setLogger($log);
        try {
            $app = new Application();
            $config = new ReflectionProperty($app, 'config');
            $config->setValue($app, [
                'domains' => ['bad_domain!' => ['/' => 'main'], 'ok.example.com' => ['/' => 'rz17_no_such_dist']],
                'alias' => ['also bad' => 'ok.example.com'],
            ]);
            $app->updateSites();
        } finally {
            ErrorReporter::setLogger(null);
        }

        $messages = \implode("\n", \array_column($log->getBuffer(), 'message'));
        $this->assertStringContainsString("domain 'bad_domain!' is not a valid domain; skipped", $messages);
        $this->assertStringContainsString("dist 'rz17_no_such_dist' for 'ok.example.com' has no dist.php", $messages);
        $this->assertStringContainsString("alias 'also bad' => 'ok.example.com' is not a valid domain pair", $messages);
    }

    #[RunInSeparateProcess]
    public function testApplicationLoadSiteConfigFailsFastOnNoReturn(): void
    {
        $file = $this->write("<?php\n// forgot the return\n['domains' => []];\n");
        \define('RAZY_PATH', $this->dir);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($file);
        (new Application())->loadSiteConfig();
    }

    #[RunInSeparateProcess]
    public function testApplicationWrapsParseErrors(): void
    {
        $file = $this->write("<?php\nreturn ['domains' => [;\n");
        \define('RAZY_PATH', $this->dir);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Failed to load site configuration: ' . $file);
        (new Application())->loadSiteConfig();
    }

    #[RunInSeparateProcess]
    public function testApplicationLoadsAValidFile(): void
    {
        $this->write("<?php\nreturn ['domains' => ['*' => ['/' => 'main']]];\n");
        \define('RAZY_PATH', $this->dir);

        $config = (new Application())->loadSiteConfig();
        $this->assertSame(['*' => ['/' => 'main']], $config['domains']);
        $this->assertSame([], $config['alias']);
        $this->assertSame([], $config['exclude_paths']);
    }

    private function write(string $php): string
    {
        $file = $this->dir . '/sites.inc.php';
        \file_put_contents($file, $php);

        return $file;
    }
}
