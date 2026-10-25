<?php

declare(strict_types=1);

namespace Razy\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use Razy\Routing\CaddyfileCompiler;
use Razy\Routing\RewriteRuleCompiler;
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
        \mkdir($docroot . '/data/sub', 0o777, true);
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
        \file_put_contents($docroot . '/data/page.html', "<!DOCTYPE html><html><body>HTMLBYTES</body></html>\n");
        \file_put_contents($docroot . '/data/index.html', "<html><body>INDEXROOT</body></html>\n");
        \file_put_contents($docroot . '/data/sub/index.html', "<html><body>INDEXSUB</body></html>\n");
        \file_put_contents($docroot . '/data/page.htm', "<html><body>HTMBYTES</body></html>\n");
        \file_put_contents($docroot . '/data/upper.HTML', "<html><body>UPPERBYTES</body></html>\n");
        \file_put_contents($docroot . '/data/pic.svg', "<svg xmlns=\"http://www.w3.org/2000/svg\"><text>SVGBYTES</text></svg>\n");
        \file_put_contents($docroot . '/data/doc.xml', "<?xml version=\"1.0\"?><root>XMLBYTES</root>\n");
        \file_put_contents($docroot . '/data/page.xhtml', "<html xmlns=\"http://www.w3.org/1999/xhtml\"><body>XHTMLBYTES</body></html>\n");
        \file_put_contents($docroot . '/data/app.js', "console.log('JSBYTES');\n");
        \file_put_contents($docroot . '/data/mod.mjs', "export const x = 'MJSBYTES';\n");
        \file_put_contents($docroot . '/data/note.phtml', "<?php echo 'PHTML';\n");
        \file_put_contents($docroot . '/data/pack.phar', "PHARINDATA\n");
        \file_put_contents(
            $docroot . '/data/sneaky.bin',
            "<!DOCTYPE html><html><body>SNEAKYHTML</body></html>\n",
        );
        \file_put_contents(
            $docroot . '/data/svg-as.dat',
            "<?xml version=\"1.0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"><script>alert(1)</script></svg>\n",
        );
        \file_put_contents(
            $docroot . '/data/pixel.png',
            (string) \base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        );
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

        // php -S may normalize ".." before the router (front controller). If the
        // router still sees it, the .phar name is a data script URL that fails
        // the realpath guard: 404, not the archive and not PATH_INFO.
        [$status, $body] = $this->fetch('/data/../Razy.phar');
        $this->assertContains($status, [200, 404], $this->logTail());
        $this->assertStringNotContainsString('PHARBYTES', $body);
        if ($status === 200) {
            $this->assertStringStartsWith('APP ', $body);
        }

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

    public function testDataActiveTypesDownloadAndStaySandboxed(): void
    {
        if (self::$base === null) {
            $this->markTestSkipped('php -S did not start' . (self::$log !== '' && \is_file(self::$log) ? ': ' . \file_get_contents(self::$log) : ''));
        }

        foreach ([
            '/data/page.html' => 'HTMLBYTES',
            '/data/page.htm' => 'HTMBYTES',
            '/data/upper.HTML' => 'UPPERBYTES',
            '/data/pic.svg' => 'SVGBYTES',
            '/data/doc.xml' => 'XMLBYTES',
            '/data/page.xhtml' => 'XHTMLBYTES',
            '/data/app.js' => 'JSBYTES',
            '/data/mod.mjs' => 'MJSBYTES',
        ] as $path => $marker) {
            $this->assertActiveDownload($path, $marker);
        }

        $this->assertInlineDataFile('/data/ok.txt', "DATABYTES\n");
        $png = (string) \file_get_contents(self::$docroot . '/data/pixel.png');
        $this->assertInlineDataFile('/data/pixel.png', $png);
        $this->assertInlineDataFile('/data/note.php', "<?php echo 'DATAEXEC';\n");
        $this->assertInlineDataFile('/data/note.phtml', "<?php echo 'PHTML';\n");
        $this->assertInlineDataFile('/data/pack.phar', "PHARINDATA\n");

        $this->assertLibmagicActive('/data/sneaky.bin', 'text/html', 'SNEAKYHTML');
        $this->assertLibmagicActive('/data/svg-as.dat', 'image/svg+xml', '<svg ');
    }

    public function testDataScriptPathInfoIsBytesOrNotFound(): void
    {
        if (self::$base === null) {
            $this->markTestSkipped('php -S did not start' . (self::$log !== '' && \is_file(self::$log) ? ': ' . \file_get_contents(self::$log) : ''));
        }

        $this->assertInlineDataFile('/data/note.php/extra', "<?php echo 'DATAEXEC';\n");
        $this->assertInlineDataFile('/data/note.php/', "<?php echo 'DATAEXEC';\n");
        $this->assertInlineDataFile('/data/note.phtml/a/b', "<?php echo 'PHTML';\n");
        $this->assertInlineDataFile('/data/pack.phar/x', "PHARINDATA\n");

        // Same basename, different case: a case-insensitive volume serves the
        // file; a case-sensitive one 404s. Either way it is not executed.
        [$status, $body] = $this->fetch('/data/note.PHP/extra');
        $this->assertContains($status, [200, 404], $this->logTail());
        $this->assertStringNotContainsString('APP uri=', $body);
        if ($status === 200) {
            $this->assertSame("<?php echo 'DATAEXEC';\n", $body);
        } else {
            $this->assertStringNotContainsString('DATAEXEC', $body);
        }

        foreach (['/data/missing.php', '/data/missing.php/extra'] as $path) {
            [$status, $body] = $this->fetch($path);
            $this->assertSame(404, $status, $path . ' ' . $this->logTail());
            $this->assertStringNotContainsString('APP uri=', $body, $path);
            $this->assertStringNotContainsString('DATAEXEC', $body, $path);
        }

        [$status, $body] = $this->fetch('/data/missing.txt');
        $this->assertSame(200, $status, $this->logTail());
        $this->assertStringStartsWith('APP uri=/data/missing.txt', $body);
    }

    public function testDataDirectoryDoesNotServeIndexHtml(): void
    {
        if (self::$base === null) {
            $this->markTestSkipped('php -S did not start' . (self::$log !== '' && \is_file(self::$log) ? ': ' . \file_get_contents(self::$log) : ''));
        }

        foreach ([
            '/data' => 'INDEXROOT',
            '/data/' => 'INDEXROOT',
            '/data/sub' => 'INDEXSUB',
            '/data/sub/' => 'INDEXSUB',
            '/shop/data/' => 'INDEXROOT',
            '/shop/data/sub/' => 'INDEXSUB',
        ] as $path => $marker) {
            [$status, $body, $headers] = $this->fetch($path);
            $joined = \implode("\n", $headers);
            $this->assertSame(404, $status, $path . ' ' . $this->logTail());
            $this->assertStringNotContainsString($marker, $body, $path);
            $this->assertStringNotContainsString('INDEXROOT', $body, $path);
            $this->assertStringNotContainsString('INDEXSUB', $body, $path);
            $this->assertStringNotContainsString('APP uri=', $body, $path);
            $this->assertStringNotContainsString('<html', \strtolower($body), $path);
            $this->assertMatchesRegularExpression('/^X-Content-Type-Options:\s*nosniff$/mi', $joined, $path);
        }

        // The named file is still a download. Only the directory URL is a 404.
        $this->assertActiveDownload('/data/index.html', 'INDEXROOT');
        $this->assertActiveDownload('/data/sub/index.html', 'INDEXSUB');
    }

    public function testScriptPathStopsAtTheExtension(): void
    {
        if (!\defined('RAZY_SERVE_FUNCTIONS_ONLY')) {
            \define('RAZY_SERVE_FUNCTIONS_ONLY', true);
        }
        require_once SYSTEM_ROOT . '/src/asset/setup/serve.php';

        $this->assertSame('note.php', \serveScriptPath('note.php'));
        $this->assertSame('note.php', \serveScriptPath('note.php/extra'));
        $this->assertSame('note.PHP', \serveScriptPath('note.PHP/extra'));
        $this->assertSame('note.php', \serveScriptPath('note.php/'));
        $this->assertSame('dir/note.phtml', \serveScriptPath('dir/note.phtml/a/b'));
        $this->assertSame('pack.phar', \serveScriptPath('pack.phar/x'));
        $this->assertSame('foo.php', \serveScriptPath('foo.php/bar.php/extra'));
        $this->assertSame('note.php', \serveScriptPath('note.php%2Fextra'));
        $this->assertSame('note.php', \serveScriptPath('note.php%2fextra'));
        $this->assertNull(\serveScriptPath('note.php.bak'));
        $this->assertNull(\serveScriptPath('ok.txt'));
        $this->assertNull(\serveScriptPath('ok.txt/extra'));
        $this->assertNull(\serveScriptPath('page.html'));
    }

    public function testActiveContentFollowsExtensionAndMediaType(): void
    {
        if (!\defined('RAZY_SERVE_FUNCTIONS_ONLY')) {
            \define('RAZY_SERVE_FUNCTIONS_ONLY', true);
        }
        require_once SYSTEM_ROOT . '/src/asset/setup/serve.php';

        foreach (['html', 'htm', 'svg', 'xml', 'xhtml', 'js', 'mjs'] as $ext) {
            $this->assertTrue(\serveIsActiveContent('/data/file.' . $ext, 'application/octet-stream'), $ext);
            $this->assertTrue(\serveIsActiveContent('/data/file.' . \strtoupper($ext), 'application/octet-stream'), $ext);
        }
        $this->assertTrue(\serveIsActiveContent('/data/x.bin', 'text/html'));
        $this->assertTrue(\serveIsActiveContent('/data/x.bin', 'Text/HTML; charset=UTF-8'));
        $this->assertTrue(\serveIsActiveContent('/data/x.dat', 'image/svg+xml'));
        $this->assertTrue(\serveIsActiveContent('/data/x.dat', 'image/svg+xml; charset=utf-8'));
        $this->assertFalse(\serveIsActiveContent('/data/x.txt', 'text/plain'));
        $this->assertFalse(\serveIsActiveContent('/data/x.png', 'image/png'));
        $this->assertFalse(\serveIsActiveContent('/data/x.php', 'text/x-php'));
        $this->assertFalse(\serveIsActiveContent('/data/x.bin', 'application/xhtml+xml'));
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

    public function testMappingRulesUseEndSoApacheDoesNotReenter(): void
    {
        $tpl = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/htaccess.tpl');
        \preg_match_all('/RewriteRule\s+\S+\s+(\S+)\s+\[([^\]]+)\]/', $tpl, $rules, PREG_SET_ORDER);
        $mappings = 0;
        foreach ($rules as $rule) {
            [$full, $target, $flags] = $rule;
            // "-" does not rewrite the URL. index.php is the fallback loop guard.
            if ($target === '-' || \str_contains($target, 'index.php')) {
                continue;
            }
            ++$mappings;
            $flagList = \array_map('trim', \explode(',', $flags));
            $this->assertContains('END', $flagList, $full);
        }
        $this->assertSame(4, $mappings, 'shared, webassets, data scripts, and data files');
    }

    public function testCompiledDataHandlesQuarantineActiveTypes(): void
    {
        if (!\defined('PHAR_PATH')) {
            \define('PHAR_PATH', SYSTEM_ROOT . '/src');
        }
        $code = 'rz25data';
        $distDir = SITES_FOLDER . '/' . $code;
        $sitesExisted = \is_dir(SITES_FOLDER);
        \mkdir($distDir, 0o777, true);
        \file_put_contents($distDir . '/dist.php', "<?php\nreturn ['dist' => '" . $code . "'];\n");

        $dir = \sys_get_temp_dir() . '/razy_rz25_' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0o777, true);
        try {
            $caddy = $dir . '/Caddyfile';
            $htaccess = $dir . '/htaccess';
            (new CaddyfileCompiler())->compile(
                ['example.com' => ['/' => $code, '/shop' => $code]],
                [],
                $caddy,
                false,
                '/app/public',
            );
            (new RewriteRuleCompiler())->compile(
                ['example.com' => ['/' => $code, '/shop' => $code]],
                [],
                $htaccess,
            );
            $caddyText = (string) \file_get_contents($caddy);
            $apacheText = (string) \file_get_contents($htaccess);

            $this->assertCompiledCaddyDataHandle($caddyText, '/data');
            $this->assertCompiledCaddyDataHandle($caddyText, '/shop/data');
            $this->assertSame(2, \substr_count($caddyText, 'index ""'));
            $this->assertSame(2, \substr_count($caddyText, 'hide index.html index.txt'));
            $this->assertCompiledApacheDataMapping($apacheText, '');
            $this->assertCompiledApacheDataMapping($apacheText, 'shop/');
            $this->assertSame(2, \substr_count($apacheText, 'DirectoryIndex disabled'));
            $this->assertSame(2, \substr_count($apacheText, 'Options -Indexes'));
        } finally {
            $this->removeTree($dir);
            $this->removeTree($distDir);
            if (!$sitesExisted && \is_dir(SITES_FOLDER)) {
                @\rmdir(SITES_FOLDER);
            }
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

    private function assertActiveDownload(string $path, string $marker): void
    {
        [$status, $body, $headers] = $this->fetch($path);
        $joined = \implode("\n", $headers);
        $this->assertSame(200, $status, $path . ' ' . $this->logTail());
        $this->assertStringContainsString($marker, $body, $path);
        $this->assertMatchesRegularExpression('/^Content-Disposition:\s*attachment\s*$/mi', $joined, $path);
        $this->assertMatchesRegularExpression('/^Content-Security-Policy:\s*sandbox\s*$/mi', $joined, $path);
        $this->assertMatchesRegularExpression('/^X-Content-Type-Options:\s*nosniff\s*$/mi', $joined, $path);
    }

    private function assertInlineDataFile(string $path, string $bodyExact): void
    {
        [$status, $body, $headers] = $this->fetch($path);
        $joined = \implode("\n", $headers);
        $this->assertSame(200, $status, $path . ' ' . $this->logTail());
        $this->assertSame($bodyExact, $body, $path);
        $this->assertMatchesRegularExpression('/^X-Content-Type-Options:\s*nosniff$/mi', $joined, $path);
        $this->assertDoesNotMatchRegularExpression('/^Content-Disposition:/mi', $joined, $path);
        $this->assertDoesNotMatchRegularExpression('/^Content-Security-Policy:/mi', $joined, $path);
    }

    private function assertLibmagicActive(string $path, string $media, string $marker): void
    {
        if (!\function_exists('mime_content_type')) {
            return;
        }
        $file = self::$docroot . $path;
        $detected = \mime_content_type($file);
        $this->assertIsString($detected);
        $detectedMedia = \strtolower(\trim(\explode(';', $detected, 2)[0]));
        if ($detectedMedia !== $media) {
            return;
        }
        $this->assertActiveDownload($path, $marker);
    }

    private function assertCompiledCaddyDataHandle(string $caddy, string $prefix): void
    {
        $pattern = '/handle @data_\w+ \{\n(?:.*\n)*?\t\}/';
        $this->assertGreaterThanOrEqual(1, \preg_match_all($pattern, $caddy, $handles), $prefix);
        $handle = '';
        foreach ($handles[0] as $candidate) {
            if (\str_contains($candidate, 'path ' . $prefix . '/*') || \str_contains($candidate, 'strip_prefix ' . $prefix . "\n")) {
                $handle = $candidate;
                break;
            }
        }
        $this->assertNotSame('', $handle, 'data handle for ' . $prefix . "\n" . $caddy);
        $this->assertStringContainsString('path ' . $prefix . '/*', $caddy);
        $this->assertStringContainsString("file_server {\n\t\t\tindex \"\"\n\t\t\thide index.html index.txt\n\t\t}", $handle);
        $this->assertStringNotContainsString('browse', $handle);
        $this->assertStringNotContainsString('php_server', $handle);
        $this->assertStringNotContainsString('split_path', $handle);
        $this->assertDoesNotMatchRegularExpression('/^\t\tphp$/m', $handle);
        $this->assertStringContainsString('(?i)^(/.*?\.(?:php|phtml|phar))(?:/.*)$', $handle);
        $this->assertStringContainsString('path_regexp `(?i)\.(?:html|htm|svg|xml|xhtml|js|mjs)$`', $handle);
        $this->assertStringContainsString('Content-Disposition "attachment"', $handle);
        $this->assertStringContainsString('Content-Security-Policy "sandbox"', $handle);
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $handle);
    }

    private function assertCompiledApacheDataMapping(string $apache, string $routePath): void
    {
        $scope = 'RewriteRule ^' . $routePath . 'data/.+ - [NC,H=default-handler,E=RAZY_DATA_DIR:1,E=REDIRECT_RAZY_DATA_DIR:1]';
        $this->assertStringContainsString($scope, $apache);
        $scopePos = \strpos($apache, $scope);
        $this->assertNotFalse($scopePos);
        $this->assertDoesNotMatchRegularExpression(
            '/RewriteRule \^' . \preg_quote($routePath, '/') . 'data\/ - \[/',
            $apache,
            'the /data/ directory URL must not disable PHP on the front controller',
        );
        $script = 'RewriteRule ^' . $routePath . 'data/(.+?\.(php|phtml|phar))(/|$) %{ENV:BASE}data/';
        $this->assertStringContainsString($script, $apache);
        $rulePos = \strpos($apache, 'RewriteRule ^' . $routePath . 'data/(.+?\.(php|phtml|phar))(/|$)');
        $this->assertNotFalse($rulePos);
        $this->assertLessThan($rulePos, $scopePos, 'the whole data prefix forces the static handler');
        $line = \strtok(\substr($apache, $rulePos), "\n") ?: '';
        $this->assertStringContainsString('NC', $line);
        $this->assertStringContainsString('H=default-handler', $line);
        $this->assertStringContainsString('T=application/octet-stream', $line);
        $this->assertMatchesRegularExpression('/\[(?:[^\]]*,)?END(?:,[^\]]*)?\]/', $line);
        $generalPos = \strpos($apache, 'RewriteRule ^' . $routePath . 'data/(.+)$');
        $this->assertNotFalse($generalPos);
        $this->assertLessThan($generalPos, $rulePos, 'script rule must win before the general data rule');
        $generalLine = \substr($apache, $generalPos, (int) \strpos($apache, "\n", $generalPos) - $generalPos);
        $ifPos = \strpos($apache, '<If "env(\'RAZY_DATA_DIR\') == \'1\' || env(\'REDIRECT_RAZY_DATA_DIR\') == \'1\'">', $scopePos);
        $this->assertNotFalse($ifPos);
        $this->assertLessThan($rulePos, $ifPos, 'SetHandler none covers the data prefix, not only a script URL');
        $this->assertStringNotContainsString('RAZY_DATA_SCRIPT', $apache);

        $active = 'RewriteRule ^' . $routePath . 'data/.+\.(html|htm|svg|xml|xhtml|js|mjs)$ - [NC,E=RAZY_DATA_ACTIVE:1,E=REDIRECT_RAZY_DATA_ACTIVE:1]';
        $this->assertStringContainsString($active, $apache);
        $activePos = (int) \strpos($apache, $active);
        $activeLine = \substr($apache, $activePos, (int) \strpos($apache, "\n", $activePos) - $activePos);
        $this->assertContains('NC', $this->rewriteFlags($activeLine), $activeLine);
        $this->assertContains('NC', $this->rewriteFlags($generalLine), $generalLine);
        $this->assertContains('END', $this->rewriteFlags($generalLine), $generalLine);
        foreach (['Content-Disposition "attachment"', 'Content-Security-Policy "sandbox"', 'X-Content-Type-Options "nosniff"'] as $header) {
            $this->assertStringContainsString('Header set ' . $header . ' env=RAZY_DATA_ACTIVE', $apache);
            $this->assertStringContainsString('Header set ' . $header . ' env=REDIRECT_RAZY_DATA_ACTIVE', $apache);
        }
        $this->assertStringContainsString('SetHandler none', $apache);
        $this->assertStringContainsString('RemoveHandler .php .phtml .phar', $apache);

        $indexIf = '<If "%{REQUEST_URI} =~ m#(?i)^/' . $routePath . 'data(/|$)# || %{REQUEST_URI} -strcmatch \'%{CONTEXT_PREFIX}/' . $routePath . 'data\' || %{REQUEST_URI} -strcmatch \'%{CONTEXT_PREFIX}/' . $routePath . 'data/*\'">';
        $indexAt = \strpos($apache, $indexIf);
        $this->assertNotFalse($indexAt, $indexIf);
        $indexBlock = \substr($apache, $indexAt, (int) \strpos($apache, '</If>', $indexAt) - $indexAt);
        $this->assertStringContainsString('DirectoryIndex disabled', $indexBlock);
        $this->assertStringContainsString('Options -Indexes', $indexBlock);
        $this->assertStringNotContainsString('SetHandler', $indexBlock);
        $this->assertDoesNotMatchRegularExpression(
            '/DirectoryIndex(?! disabled)/',
            $indexBlock,
            'the data mount must not name an index document',
        );
    }

    /**
     * @return list<string>
     */
    private function rewriteFlags(string $line): array
    {
        $this->assertSame(1, \preg_match('/\[([^\]]+)\]/', $line, $m), $line);

        return \array_map('trim', \explode(',', $m[1]));
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @\rmdir($file->getPathname()) : @\unlink($file->getPathname());
        }
        @\rmdir($root);
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
