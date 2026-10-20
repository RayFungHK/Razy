<?php

/**
 * RZ-09: the safe static file server — traversal guard (realpath + prefix,
 * null bytes, encoded dot segments), content types and cache headers.
 *
 * @license MIT
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Http\StaticFile;

#[CoversClass(StaticFile::class)]
class StaticFileTest extends TestCase
{
    private string $base;

    private string $root;

    protected function setUp(): void
    {
        $this->base = \sys_get_temp_dir() . '/razy_static_' . \bin2hex(\random_bytes(6));
        $this->root = $this->base . '/webassets';
        \mkdir($this->root . '/js', 0o777, true);
        \file_put_contents($this->root . '/js/app.js', 'export const a = 1;');
        \file_put_contents($this->root . '/.env', 'SECRET=1');
        \file_put_contents($this->base . '/secret.php', '<?php // outside the root');
        \mkdir($this->base . '/webassets-evil', 0o777, true);
        \file_put_contents($this->base . '/webassets-evil/x.js', 'sibling dir sharing the prefix');
        @\symlink($this->base . '/secret.php', $this->root . '/link.js');
    }

    protected function tearDown(): void
    {
        $remove = static function (string $dir) use (&$remove): void {
            foreach (\scandir($dir) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $dir . '/' . $entry;
                \is_dir($path) && !\is_link($path) ? $remove($path) : \unlink($path);
            }
            \rmdir($dir);
        };
        $remove($this->base);
    }

    public static function hostilePaths(): array
    {
        return [
            'dot dot' => ['../secret.php'],
            'nested dot dot' => ['js/../../secret.php'],
            'encoded dot dot' => ['%2e%2e/secret.php'],
            'mixed-case encoded' => ['%2E%2e/secret.php'],
            'double encoded' => ['%252e%252e/secret.php'],
            'encoded slash' => ['..%2fsecret.php'],
            'backslash' => ['..\secret.php'],
            'encoded backslash' => ['..%5csecret.php'],
            'null byte' => ["js/app.js\0.png"],
            'encoded null byte' => ['js/app.js%00.png'],
            'dot segment' => ['./js/app.js'],
            'dotfile' => ['.env'],
            'sibling prefix' => ['../webassets-evil/x.js'],
            'directory' => ['js'],
            'empty' => [''],
            'missing' => ['js/nope.js'],
        ];
    }

    public static function contentTypes(): array
    {
        return [
            ['app.js', 'text/javascript; charset=utf-8'],
            ['app.mjs', 'text/javascript; charset=utf-8'],
            ['site.css', 'text/css; charset=utf-8'],
            ['data.json', 'application/json'],
            ['app.js.map', 'application/json'],
            ['icon.svg', 'image/svg+xml'],
            ['font.woff2', 'font/woff2'],
            ['lib.wasm', 'application/wasm'],
            ['photo.JPG', 'image/jpeg'],
            ['page.html', 'text/html; charset=utf-8'],
            ['unknown.xyz', 'application/octet-stream'],
            ['noextension', 'application/octet-stream'],
        ];
    }

    #[DataProvider('hostilePaths')]
    public function testResolveRejectsAnythingOutsideTheRoot(string $relative): void
    {
        $this->assertNull(StaticFile::resolve($this->root, $relative));
    }

    public function testResolveRejectsASymlinkLeavingTheRoot(): void
    {
        if (!\is_link($this->root . '/link.js')) {
            $this->markTestSkipped('symlinks unavailable');
        }
        $this->assertNull(StaticFile::resolve($this->root, 'link.js'));
    }

    public function testResolveReturnsTheRealPathOfAFileInsideTheRoot(): void
    {
        $this->assertSame(\realpath($this->root . '/js/app.js'), StaticFile::resolve($this->root, 'js/app.js'));
        $this->assertSame(\realpath($this->root . '/js/app.js'), StaticFile::resolve($this->root, '/js//app.js'));
        $this->assertNull(StaticFile::resolve($this->base . '/missing-root', 'js/app.js'));
    }

    #[DataProvider('contentTypes')]
    public function testContentTypeIsPickedFromTheExtension(string $file, string $type): void
    {
        $this->assertSame($type, StaticFile::contentType($file));
    }

    public function testMatchingVersionIsImmutable(): void
    {
        $file = new StaticFile($this->root . '/js/app.js', 'abc123');
        $response = $file->response('GET', null, true, 31536000, 0);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']);
        $this->assertSame('text/javascript; charset=utf-8', $response['headers']['Content-Type']);
        $this->assertSame('nosniff', $response['headers']['X-Content-Type-Options'], 'also where no dist sends security headers (Standalone)');
        $this->assertSame((string) \filesize($this->root . '/js/app.js'), $response['headers']['Content-Length']);
        $this->assertSame('public, max-age=31536000, immutable', $response['headers']['Cache-Control']);
        $this->assertSame('"abc123"', $response['headers']['ETag']);
        $this->assertArrayHasKey('Last-Modified', $response['headers']);
    }

    public function testUnversionedRequestRevalidates(): void
    {
        $response = (new StaticFile($this->root . '/js/app.js', 'abc123'))->response('GET', null, false, 31536000, 0);
        $this->assertSame('public, max-age=0, must-revalidate', $response['headers']['Cache-Control']);

        $response = (new StaticFile($this->root . '/js/app.js', 'abc123'))->response('GET', null, false, 31536000, 600);
        $this->assertSame('public, max-age=600', $response['headers']['Cache-Control']);
    }

    public function testIfNoneMatchGivesNotModifiedWithoutABody(): void
    {
        $file = new StaticFile($this->root . '/js/app.js', 'abc123');

        $response = $file->response('GET', 'W/"other", "abc123"', false, 31536000, 0);
        $this->assertSame(304, $response['status']);
        $this->assertFalse($response['body']);
        $this->assertArrayNotHasKey('Content-Length', $response['headers']);

        $this->assertSame(200, $file->response('GET', '"other"', false, 31536000, 0)['status']);
    }

    public function testHeadSendsHeadersOnly(): void
    {
        $response = (new StaticFile($this->root . '/js/app.js', 'abc123'))->response('HEAD', null, false, 31536000, 0);
        $this->assertSame(200, $response['status']);
        $this->assertFalse($response['body']);
        $this->assertArrayHasKey('Content-Length', $response['headers']);
    }

    public function testSendEmitsHeadersAndStreamsTheBody(): void
    {
        $lines = [];
        $status = 0;
        \ob_start();
        (new StaticFile($this->root . '/js/app.js', 'abc123'))->send(
            ['REQUEST_METHOD' => 'GET'],
            true,
            31536000,
            0,
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            function (int $code) use (&$status): void {
                $status = $code;
            },
        );
        $body = (string) \ob_get_clean();

        $this->assertSame(200, $status);
        $this->assertSame('export const a = 1;', $body);
        $this->assertContains('Cache-Control: public, max-age=31536000, immutable', $lines);
    }
}
