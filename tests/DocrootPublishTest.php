<?php

declare(strict_types=1);

namespace Razy\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Razy\Routing\CaddyfileCompiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * RZ-24: php -S and the generated claim must not publish the document root.
 *
 * @license MIT
 */
final class DocrootPublishTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static ?string $base = null;

    private static string $docroot = '';

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $docroot = \sys_get_temp_dir() . '/razy_rz24_' . \bin2hex(\random_bytes(4));
        \mkdir($docroot . '/sites', 0o777, true);
        \mkdir($docroot . '/data', 0o777, true);
        \mkdir($docroot . '/webassets/mod/default', 0o777, true);

        \file_put_contents($docroot . '/index.php', <<<'PHP'
<?php
header('Content-Type: text/plain');
echo 'APP uri=' . ($_SERVER['REQUEST_URI'] ?? '') . ' script=' . ($_SERVER['SCRIPT_NAME'] ?? '');
PHP);
        \file_put_contents($docroot . '/evil.php', "<?php echo 'EXECUTED';\n");
        \file_put_contents($docroot . '/sites/dist.php', "<?php echo 'DISTEXECUTED';\n");
        \file_put_contents($docroot . '/Razy.phar', "PHARBYTES\n");
        \file_put_contents($docroot . '/view.tpl', "TPLBYTES\n");
        \file_put_contents($docroot . '/robots.txt', "ROBOTBYTES\n");
        \file_put_contents($docroot . '/data/ok.txt', "DATABYTES\n");
        \file_put_contents($docroot . '/data/note.php', "<?php echo 'DATAEXEC';\n");
        \file_put_contents($docroot . '/webassets/mod/default/app.js', "JSBYTES\n");

        $probe = @\stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($probe, false), ':'), 1);
        \fclose($probe);

        $log = $docroot . '/server.log';
        $cmd = [
            \PHP_BINARY,
            '-S',
            '127.0.0.1:' . $port,
            '-t',
            $docroot,
            SYSTEM_ROOT . '/src/asset/setup/serve.php',
        ];
        $proc = @\proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (!\is_resource($proc)) {
            return;
        }

        self::$server = $proc;
        self::$docroot = $docroot;
        self::$log = $log;
        for ($i = 0; $i < 50; ++$i) {
            $sock = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                \fclose($sock);
                self::$base = 'http://127.0.0.1:' . $port;

                return;
            }
            \usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
        self::$server = null;
        if (self::$docroot !== '' && \is_dir(self::$docroot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(self::$docroot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
            }
            @\rmdir(self::$docroot);
        }
    }

    public function testBuiltInServerDoesNotPublishOrExecuteTheTree(): void
    {
        if (self::$base === null) {
            $this->markTestSkipped('php -S did not start' . (self::$log !== '' && \is_file(self::$log) ? ': ' . \file_get_contents(self::$log) : ''));
        }

        foreach ([
            '/evil.php' => 'EXECUTED',
            '/sites/dist.php' => 'DISTEXECUTED',
            '/Razy.phar' => 'PHARBYTES',
            '/view.tpl' => 'TPLBYTES',
            '/robots.txt' => 'ROBOTBYTES',
            '/webassets/mod/default/app.js' => 'JSBYTES',
        ] as $path => $secret) {
            [$status, $body] = $this->fetch($path);
            $this->assertSame(200, $status, $path . ' ' . $this->logTail());
            $this->assertStringStartsWith('APP uri=' . $path, $body, $path);
            $this->assertStringContainsString('script=/index.php', $body, $path);
            $this->assertStringNotContainsString($secret, $body, $path);
        }

        // php -S may normalize ".." before the router. Either way the phar bytes
        // must not come back, and the answer is the front controller.
        [$status, $body] = $this->fetch('/data/../Razy.phar');
        $this->assertSame(200, $status, $this->logTail());
        $this->assertStringStartsWith('APP ', $body);
        $this->assertStringNotContainsString('PHARBYTES', $body);

        [$status, $body] = $this->fetch('/login?next=/foo.php');
        $this->assertSame(200, $status, $this->logTail());
        $this->assertStringContainsString('uri=/login?next=/foo.php', $body);
        $this->assertStringContainsString('script=/index.php', $body);

        [$status, $body, $headers] = $this->fetch('/data/ok.txt');
        $this->assertSame(200, $status, $this->logTail());
        $this->assertSame("DATABYTES\n", $body);
        $this->assertMatchesRegularExpression('/^X-Content-Type-Options:\s*nosniff$/mi', \implode("\n", $headers));

        [$status, $body] = $this->fetch('/data/note.php');
        $this->assertSame(200, $status, $this->logTail());
        $this->assertSame("<?php echo 'DATAEXEC';\n", $body, 'a .php under /data is bytes, not executed');
    }

    public function testGeneratedClaimRewritesToTheFrontController(): void
    {
        if (!\defined('PHAR_PATH')) {
            \define('PHAR_PATH', SYSTEM_ROOT . '/src');
        }
        $dir = \sys_get_temp_dir() . '/razy_rz24_caddy_' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0o777, true);
        try {
            $worker = $dir . '/w';
            $standard = $dir . '/s';
            (new CaddyfileCompiler())->compile(
                ['example.com' => ['/app' => 'coex_fake_dist']],
                [],
                $worker,
                true,
                '/app/public',
                ['/api-py'],
            );
            (new CaddyfileCompiler())->compile(
                ['example.com' => ['/' => 'coex_fake_dist']],
                [],
                $standard,
                false,
                '/app/public',
            );
            foreach ([$worker, $standard] as $path) {
                $content = (string) \file_get_contents($path);
                $this->assertStringContainsString('rewrite * /index.php', $content);
                $this->assertStringNotContainsString("\tphp_server\n", $content);
                $this->assertStringNotContainsString('php_server @', $content);
                $this->assertStringNotContainsString('php_server {', $content);
                $this->assertStringNotContainsString('robots.txt', $content);
            }
            $scoped = (string) \file_get_contents($worker);
            $this->assertStringContainsString('handle @php_claimed {', $scoped);
            $this->assertStringContainsString("handle /_razy/health {\n\t\theader * {\n\t\t\tCache-Control \"no-store, max-age=0\"\n\t\t}\n\t\troute {\n\t\t\trewrite * /index.php\n\t\t\tphp\n\t\t}\n\t}", $scoped);
        } finally {
            foreach ((array) \glob($dir . '/*') as $file) {
                @\unlink($file);
            }
            @\rmdir($dir);
        }
    }

    public function testHtaccessFallbackDoesNotSkipExistingFiles(): void
    {
        $tpl = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/htaccess.tpl');
        $start = \strpos($tpl, '<!-- START BLOCK: fallback -->');
        $end = \strpos($tpl, '<!-- END BLOCK: fallback -->');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $fallback = \substr($tpl, $start, $end - $start);
        $this->assertStringNotContainsString('!-f', $fallback);
        $this->assertStringNotContainsString('!-d', $fallback);
        $this->assertStringNotContainsString('!-l', $fallback);
        $this->assertStringContainsString('RewriteCond $1 !^(index\.php)$', $fallback);
        $this->assertStringNotContainsString('robots.txt', $fallback);
        foreach (['sites', 'system', 'shared', 'plugins', 'asset', 'config.inc.php', 'repository.inc.php', 'sites.inc.php'] as $name) {
            $this->assertStringNotContainsString($name, $fallback, $name . ' must not be left for Apache to serve');
        }
    }

    public function testStaticTypeFallsBackWhenFileinfoIsOff(): void
    {
        if (!\defined('RAZY_SERVE_FUNCTIONS_ONLY')) {
            \define('RAZY_SERVE_FUNCTIONS_ONLY', true);
        }
        require_once SYSTEM_ROOT . '/src/asset/setup/serve.php';

        $this->assertSame('text/plain', \serveContentType('notes.txt', false));
        $this->assertSame('text/css', \serveContentType('app.css', false));
        $this->assertSame('application/octet-stream', \serveContentType('blob.no-such-ext', false));
        $router = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/serve.php');
        $this->assertStringContainsString("header('X-Content-Type-Options: nosniff');", $router);
        $this->assertStringContainsString("function_exists('mime_content_type')", $router);
    }

    /**
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private function fetch(string $path): array
    {
        $ctx = \stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 3]]);
        $fp = \fopen(self::$base . $path, 'rb', false, $ctx);
        if ($fp === false) {
            return [0, '', []];
        }
        $meta = \stream_get_meta_data($fp);
        $body = \stream_get_contents($fp);
        \fclose($fp);
        $headers = \is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        $status = 0;
        if (isset($headers[0]) && \preg_match('/\s(\d{3})\s/', (string) $headers[0], $m)) {
            $status = (int) $m[1];
        }

        return [$status, (string) $body, $headers];
    }

    private function logTail(): string
    {
        if (self::$log === '' || !\is_file(self::$log)) {
            return '';
        }

        return \substr((string) \file_get_contents(self::$log), -500);
    }
}
