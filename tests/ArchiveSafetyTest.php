<?php

/**
 * Unit tests for Razy\ArchiveSafety — zip-slip / symlink / transport hardening.
 *
 * Vectors mirror the 2026 audit finding §S2 (PackageManager + RepoInstaller
 * extractTo with attacker-controlled entry names).
 *
 * This file is part of Razy v1.0.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\ArchiveSafety;
use ZipArchive;

#[CoversClass(ArchiveSafety::class)]
class ArchiveSafetyTest extends TestCase
{
    public static function hostileEntryProvider(): array
    {
        return [
            'parent traversal' => ['../evil.php'],
            'nested traversal' => ['pkg/../../evil.php'],
            'deep traversal' => ['a/b/c/../../../../windows/system32/evil.php'],
            'windows separators' => ['..\..\evil.php'],
            'mixed separators' => ['pkg/..\evil.php'],
            'absolute unix' => ['/etc/passwd'],
            'absolute after prefix' => ['pkg/../../../etc/passwd'],
            'drive letter' => ['C:\evil.php'],
            'drive letter slash' => ['C:/evil.php'],
            'UNC share' => ['\\\server\share\evil.php'],
            'phar wrapper' => ['phar://evil.zip/x.php'],
            'zip wrapper' => ['zip://payload/x.php'],
            'nul byte' => ["pkg/evil\0.php"],
            'newline smuggling' => ["pkg/evil\n.php"],
            'dot segment' => ['./evil.php'],
            'empty segment double slash' => ['pkg//evil.php'],
            'dot-dot only' => ['..'],
            'empty name' => [''],
        ];
    }

    public static function safeEntryProvider(): array
    {
        return [
            'flat file' => ['README.md'],
            'nested path' => ['src/Library/Thing.php'],
            'deep nesting' => ['a/b/c/d/e/f.txt'],
            'dots in name' => ['pkg-1.0.2.tar/thing.php'],
            'leading dotfile' => ['.editorconfig'],
            'trailing dot seg' => ['dir.old/file.php'],
            'unicode' => ['docs/說明.md'],
            'spaces' => ['my dir/my file.php'],
            'directory entry' => ['pkg/src/'],
        ];
    }

    public static function packageNameProvider(): array
    {
        return [
            'plain' => ['vendor/pkg', 'vendor/pkg'],
            'dots' => ['vendor.v1/pkg_name-2', 'vendor.v1/pkg_name-2'],
            'traversal' => ['vendor/../../evil', null],
            'absolute' => ['/etc/passwd', null],
            'drive' => ['C:evil', null],
            'shell metachars' => ['vendor/;rm -rf', null],
            'empty segment' => ['vendor//pkg', null],
            'dot dot segment' => ['../pkg', null],
            'trailing separator' => ['vendor/', null],
            'nul' => ["vendor/p\0g", null],
        ];
    }

    public static function urlProvider(): array
    {
        return [
            ['https://packagist.org/p.zip', false, true],
            ['http://mirror.local/p.zip', false, false],
            ['http://mirror.local/p.zip', true, true],
            ['HTTPS://UPPER.example/x', false, true],
            ['/var/local/repo/pkg.zip', false, true],       // scheme-less = local path
            ['sftp://host/pool.zip', false, true],
            ['ftp://host/pool.zip', false, true],
            ['smb://share/pool.zip', false, true],
            ['gopher://evil/x', false, false],
            ['phar://x/y', false, false],
        ];
    }

    #[DataProvider('hostileEntryProvider')]
    public function testHostileEntryNamesAreRejected(string $name): void
    {
        $this->assertFalse(ArchiveSafety::validateEntryName($name), "must reject: {$name}");
    }

    #[DataProvider('safeEntryProvider')]
    public function testSafeEntryNamesAreAccepted(string $name): void
    {
        $this->assertTrue(ArchiveSafety::validateEntryName($name), "must accept: {$name}");
    }

    public function testValidateArchiveFlagsTraversalEntryInRealZip(): void
    {
        $zipPath = \tempnam(\sys_get_temp_dir(), 'archtest') . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('good/ok.txt', 'fine');
        $zip->addFromString('../escape.txt', 'pwned');
        $this->assertTrue($zip->close());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $bad = ArchiveSafety::validateArchive($zip);
        $zip->close();
        @\unlink($zipPath);

        $this->assertCount(1, $bad);
        $this->assertSame('../escape.txt', $bad[0]);
    }

    public function testValidateArchiveAcceptsCleanZip(): void
    {
        $zipPath = \tempnam(\sys_get_temp_dir(), 'archtest') . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('pkg/src/Main.php', 'ok');
        $zip->addFromString('pkg/README.md', 'ok');
        $this->assertTrue($zip->close());

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $bad = ArchiveSafety::validateArchive($zip);
        $zip->close();
        @\unlink($zipPath);

        $this->assertSame([], $bad);
    }

    public function testFindSymlinksDetectsLinkWhenSupported(): void
    {
        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razysym_' . \bin2hex(\random_bytes(6));
        \mkdir($dir . DIRECTORY_SEPARATOR . 'sub', 0o700, true);
        \file_put_contents($dir . '/sub/real.txt', 'x');

        $linkMade = @\symlink($dir . '/sub/real.txt', $dir . '/sub/link.txt');
        if (!$linkMade && !\function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable');
        }
        if (!$linkMade) {
            // Windows without privileges: negative-control only.
            $this->assertSame([], ArchiveSafety::findSymlinks($dir));
            @\unlink($dir . '/sub/real.txt');
            @\rmdir($dir . '/sub');
            @\rmdir($dir);

            return;
        }

        $links = ArchiveSafety::findSymlinks($dir);
        @\unlink($dir . '/sub/link.txt');
        @\unlink($dir . '/sub/real.txt');
        @\rmdir($dir . '/sub');
        @\rmdir($dir);

        $this->assertCount(1, $links);
        $this->assertStringContainsString('link.txt', $links[0]);
    }

    public function testFindSymlinksEmptyAndMissingDir(): void
    {
        $this->assertSame([], ArchiveSafety::findSymlinks(\sys_get_temp_dir() . '/razynope_' . \bin2hex(\random_bytes(6))));
    }

    #[DataProvider('packageNameProvider')]
    public function testSanitizePackageName(string $name, ?string $expected): void
    {
        $this->assertSame($expected, ArchiveSafety::sanitizePackageName($name));
    }

    public function testPurgeDirectoryRemovesTree(): void
    {
        $dir = \sys_get_temp_dir() . '/razypurge_' . \bin2hex(\random_bytes(6));
        \mkdir($dir . '/a/b', 0o700, true);
        \file_put_contents($dir . '/a/b/f.txt', 'x');
        \file_put_contents($dir . '/top.txt', 'x');

        ArchiveSafety::purgeDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    #[DataProvider('urlProvider')]
    public function testIsSecureUrl(string $url, bool $allowInsecure, bool $expected): void
    {
        $this->assertSame($expected, ArchiveSafety::isSecureUrl($url, $allowInsecure));
    }
}
