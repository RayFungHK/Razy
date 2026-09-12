<?php

/**
 * Unit tests for Razy\PackageVerifier (+ its PackageIntegrityException and the
 * RepositoryManager default-registry/fetch-policy hooks) — registry S0/S2.
 *
 * No network: the hash check and the claim/url decision logic are pure
 * functions run against tiny temp files; the terminal download-loop wiring
 * (verify-before-extractTo ordering, HTTPS guards, loud warnings) is pinned by
 * source-text assertions in the same style as ScaffoldCommandTest.
 *
 * This file is part of Razy v1.0.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\PackageIntegrityException;
use Razy\PackageManager;
use Razy\PackageVerifier;
use Razy\RepositoryManager;

#[CoversClass(PackageVerifier::class)]
#[CoversClass(PackageIntegrityException::class)]
#[CoversClass(RepositoryManager::class)]
class PackageVerifierTest extends TestCase
{
    /** @var list<string> temp files/dirs to remove in tearDown */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        $this->tempPaths = [];
        PackageManager::$allowInsecureTransport = false;
    }

    // ── resolveExpectedChecksum: claim semantics ─────────────────────

    public static function checksumClaimProvider(): array
    {
        $hash = \hash('sha256', 'artifact-bytes');
        $other = \hash('sha256', 'other-artifact');

        return [
            'v2 release map with sha256' => [
                ['releases' => ['1.0.0' => ['sha256' => $hash, 'size' => 123]]], '1.0.0', true, $hash,
            ],
            'v2 release map uppercase digest normalized' => [
                ['releases' => ['1.0.0' => ['sha256' => \strtoupper($hash)]]], '1.0.0', true, $hash,
            ],
            'v2 release block present but sha256 missing (claimed-but-missing)' => [
                ['releases' => ['1.0.0' => ['size' => 123]]], '1.0.0', true, null,
            ],
            'v2 release sha256 empty string (claimed-but-missing)' => [
                ['releases' => ['1.0.0' => ['sha256' => '']]], '1.0.0', true, null,
            ],
            'v2 release metadata malformed (claimed-but-missing)' => [
                ['releases' => ['1.0.0' => 'not-an-object']], '1.0.0', true, null,
            ],
            'v1 index predates checksums (warn path)' => [
                ['description' => 'x', 'author' => 'y', 'latest' => '1.0.0', 'versions' => ['1.0.0']], '1.0.0', false, null,
            ],
            'flat entry sha256' => [
                ['sha256' => $hash], '1.0.0', true, $hash,
            ],
            'flat sha256 empty (claimed-but-missing)' => [
                ['sha256' => ''], '1.0.0', true, null,
            ],
            'flat sha256 null (projection default = not claimed)' => [
                ['sha256' => null, 'versions' => ['1.0.0']], '1.0.0', false, null,
            ],
            'per-version claim falls back to flat for unknown version' => [
                ['releases' => ['1.0.0' => ['sha256' => $hash]], 'sha256' => $other], '2.0.0', true, $other,
            ],
        ];
    }

    // ── assertSecureUrl: transport policy ────────────────────────────

    public static function urlPolicyProvider(): array
    {
        return [
            'https artifact' => ['https://github.com/o/r/releases/download/t/1.0.0.phar', false, true],
            'plain http rejected by default' => ['http://mirror.local/packs/1.0.0.phar', false, false],
            'plain http allowed only via explicit opt-in' => ['http://mirror.local/packs/1.0.0.phar', true, true],
            'file url allowed as local-dev source' => ['file:///srv/registry/1.0.0.phar', false, true],
            'scheme-less local path allowed' => ['/srv/registry/1.0.0.phar', false, true],
            'exotic scheme rejected' => ['gopher://evil/x.phar', false, false],
        ];
    }

    #[DataProvider('checksumClaimProvider')]
    public function testResolveExpectedChecksumPinsDecision(
        array $entry,
        string $version,
        bool $required,
        ?string $expectedSha,
    ): void {
        $claim = PackageVerifier::resolveExpectedChecksum($entry, $version);

        $this->assertSame($required, $claim['required']);
        $this->assertSame($expectedSha, $claim['sha256']);
        $this->assertNotSame('', $claim['reason'], 'every decision carries an operator-facing reason');
    }

    public function testMissingChecksumReasonIsHonestAboutUnpublishedChecksums(): void
    {
        $claim = PackageVerifier::resolveExpectedChecksum(['latest' => '1.0.0'], '1.0.0');

        $this->assertFalse($claim['required']);
        $this->assertStringContainsString('predates checksum', $claim['reason']);
    }

    // ── verifyFile / verifyString: digest enforcement ────────────────

    public function testVerifyFileAcceptsMatchingChecksum(): void
    {
        $path = $this->tempFile('payload');
        PackageVerifier::verifyFile($path, \hash('sha256', 'payload'));

        $this->addToAssertionCount(1);
    }

    public function testVerifyFileAcceptsUppercaseExpectedDigest(): void
    {
        $path = $this->tempFile('payload');
        PackageVerifier::verifyFile($path, \strtoupper(\hash('sha256', 'payload')));

        $this->addToAssertionCount(1);
    }

    public function testVerifyFileRejectsMismatchedChecksum(): void
    {
        $path = $this->tempFile('payload');
        $wrong = \hash('sha256', 'tampered');

        $thrown = $this->captureIntegrity(fn () => PackageVerifier::verifyFile($path, $wrong));

        $this->assertNotNull($thrown, 'a tampered artifact must abort the install');
        $this->assertStringContainsString('Checksum mismatch', $thrown->getMessage());
    }

    public function testVerifyFileRejectsMissingArtifact(): void
    {
        $missing = \sys_get_temp_dir() . '/razypv_missing_' . \bin2hex(\random_bytes(6)) . '.phar';

        $thrown = $this->captureIntegrity(fn () => PackageVerifier::verifyFile($missing, \hash('sha256', 'x')));

        $this->assertNotNull($thrown, 'missing-while-claimed must abort, not proceed');
        $this->assertStringContainsString('missing or unreadable', $thrown->getMessage());
    }

    public function testVerifyFileRejectsMalformedExpectedDigest(): void
    {
        $path = $this->tempFile('payload');

        foreach (['nothex', \str_repeat('g', 64), \substr(\hash('sha256', 'x'), 0, 32)] as $malformed) {
            $thrown = $this->captureIntegrity(fn () => PackageVerifier::verifyFile($path, $malformed));

            $this->assertNotNull($thrown, "malformed claim must be refused: {$malformed}");
            $this->assertStringContainsString('Malformed sha256', $thrown->getMessage());
        }
    }

    public function testVerifyStringAcceptsMatchingAndRejectsMismatchedBytes(): void
    {
        PackageVerifier::verifyString('phar-bytes', \hash('sha256', 'phar-bytes'));

        $thrown = $this->captureIntegrity(fn () => PackageVerifier::verifyString('phar-bytes', \hash('sha256', 'other')));

        $this->assertNotNull($thrown);
        $this->assertStringContainsString('Checksum mismatch', $thrown->getMessage());
    }

    #[DataProvider('urlPolicyProvider')]
    public function testAssertSecureUrlEnforcesPolicy(string $url, bool $allowInsecure, bool $expectPass): void
    {
        $thrown = $this->captureIntegrity(fn () => PackageVerifier::assertSecureUrl($url, $allowInsecure));

        if ($expectPass) {
            $this->assertNull($thrown, "URL must pass policy: {$url}");

            return;
        }

        $this->assertNotNull($thrown, "URL must be rejected: {$url}");
        $this->assertStringContainsString('HTTPS required', $thrown->getMessage());
    }

    public function testInsecureTransportAllowedReadsTrustedOperatorSettings(): void
    {
        $original = PackageManager::$allowInsecureTransport;

        try {
            PackageManager::$allowInsecureTransport = false;
            $this->assertFalse(PackageVerifier::insecureTransportAllowed(), 'env opt-in unset in test runs');

            PackageManager::$allowInsecureTransport = true;
            $this->assertTrue(PackageVerifier::insecureTransportAllowed());
        } finally {
            PackageManager::$allowInsecureTransport = $original;
        }
    }

    // ── Registry resolution: default official registry (G1) ──────────

    public function testDefaultOfficialRegistryConstantIsPinned(): void
    {
        $this->assertSame('https://github.com/RayFungHK/Razy-Repository/', RepositoryManager::DEFAULT_OFFICIAL_URL);
        $this->assertSame('main', RepositoryManager::DEFAULT_OFFICIAL_BRANCH);
        $this->assertSame(
            ['https://github.com/RayFungHK/Razy-Repository' => 'main'],
            RepositoryManager::defaultRepositories(),
            'default map is non-empty and trailing-slash normalized',
        );
    }

    public function testResolveRepositoriesFallsBackToDefaultWhenUserFileAbsent(): void
    {
        $absent = \sys_get_temp_dir() . '/razypv_absent_' . \bin2hex(\random_bytes(6)) . '.inc.php';

        $this->assertSame(RepositoryManager::defaultRepositories(), RepositoryManager::resolveRepositories($absent));
    }

    public function testResolveRepositoriesKeepsUserFileAuthoritative(): void
    {
        $file = $this->tempConfigFile(['https://github.com/acme/packs/' => 'dev']);

        $repositories = RepositoryManager::resolveRepositories($file);

        $this->assertSame(['https://github.com/acme/packs/' => 'dev'], $repositories);
        $this->assertArrayNotHasKey('https://github.com/RayFungHK/Razy-Repository', $repositories);
    }

    public function testResolveRepositoriesFallsBackWhenUserFileEmpty(): void
    {
        $file = $this->tempConfigFile([]);

        $this->assertSame(RepositoryManager::defaultRepositories(), RepositoryManager::resolveRepositories($file));
    }

    public function testGetModuleInfoPassesChecksumMetadataThroughToInstallers(): void
    {
        $hash = \hash('sha256', 'demo phar');

        $manager = new class(['https://registry.test/repo' => 'main']) extends RepositoryManager {
            public ?array $fakeIndex = null;

            public function fetchIndex(string $repoUrl): ?array
            {
                return $this->fakeIndex;
            }
        };

        // v2 entry: releases.<v>.sha256 must survive the getModuleInfo projection.
        $manager->fakeIndex = [
            'demo/demo_index' => [
                'description' => 'demo', 'author' => 'rzy', 'latest' => '1.0.0',
                'versions' => ['1.0.0'],
                'releases' => ['1.0.0' => ['sha256' => $hash, 'size' => 10]],
            ],
        ];
        $info = $manager->getModuleInfo('demo/demo_index');
        $this->assertIsArray($info);
        $claim = PackageVerifier::resolveExpectedChecksum($info, '1.0.0');
        $this->assertTrue($claim['required']);
        $this->assertSame($hash, $claim['sha256']);

        // v1 entry: checksum-less metadata projects to the warn path.
        $manager->fakeIndex = [
            'demo/demo_index' => [
                'description' => 'demo', 'author' => 'rzy', 'latest' => '1.0.0',
                'versions' => ['1.0.0'],
            ],
        ];
        $v1 = $manager->getModuleInfo('demo/demo_index');
        $this->assertIsArray($v1);
        $v1Claim = PackageVerifier::resolveExpectedChecksum($v1, '1.0.0');
        $this->assertFalse($v1Claim['required']);
    }

    // ── Download-loop wiring: decision order pinned at the call sites ─

    public function testInstallVerifiesChecksumBeforeExtractOnBothPharPaths(): void
    {
        $src = $this->readSource('src/system/terminal/install.inc.php');
        $marker = '$depClaim = PackageVerifier::resolveExpectedChecksum';
        $markerPos = \strpos($src, $marker);
        $this->assertNotFalse($markerPos, 'dependency-fetch region marker must exist');

        $mainRegion = \substr($src, 0, $markerPos);
        $depRegion = \substr($src, $markerPos);

        $this->assertVerifyBeforeExtract($mainRegion, 'install main path');
        $this->assertVerifyBeforeExtract($depRegion, 'install dependency path');
        $this->assertStringContainsString('PackageVerifier::assertSecureUrl($downloadUrl)', $mainRegion);
        $this->assertStringContainsString('PackageVerifier::assertSecureUrl($depUrl)', $depRegion);
        $this->assertStringContainsString('ArchiveSafety::isSecureUrl', $src, 'safeFetchUrl (disclaimer/terms) also guarded');
    }

    public function testSyncVerifiesChecksumBeforeExtract(): void
    {
        $src = $this->readSource('src/system/terminal/sync.inc.php');

        $this->assertVerifyBeforeExtract($src, 'sync');
        $this->assertStringContainsString('PackageVerifier::assertSecureUrl($downloadUrl)', $src);
    }

    public function testPkgInstallVerifiesBytesBeforeSavingPhar(): void
    {
        $src = $this->readSource('src/system/terminal/pkg.inc.php');

        $verifyPos = \strpos($src, 'PackageVerifier::verifyString');
        $savePos = \strpos($src, 'file_put_contents($targetPharPath');
        $this->assertNotFalse($verifyPos, 'pkg install must verify before saving');
        $this->assertNotFalse($savePos);
        $this->assertLessThan($savePos, $verifyPos, 'mismatch must abort before the phar hits packages/');
        $this->assertStringContainsString('PackageVerifier::assertSecureUrl($downloadUrl)', $src);
    }

    public function testMissingChecksumWarningIsLoudAtEveryFetchPath(): void
    {
        $needle = 'CANNOT be verified';

        $this->assertGreaterThanOrEqual(2, \substr_count($this->readSource('src/system/terminal/install.inc.php'), $needle));
        $this->assertGreaterThanOrEqual(1, \substr_count($this->readSource('src/system/terminal/sync.inc.php'), $needle));
        $this->assertGreaterThanOrEqual(1, \substr_count($this->readSource('src/system/terminal/pkg.inc.php'), $needle));
    }

    public function testIndexFetchHonoursTransportPolicy(): void
    {
        $src = $this->readSource('src/library/Razy/RepositoryManager.php');

        $this->assertStringContainsString('ArchiveSafety::isSecureUrl', $src, 'httpGet() must gate index/manifest fetches');
        $this->assertStringContainsString('PackageVerifier::insecureTransportAllowed', $src);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function assertVerifyBeforeExtract(string $region, string $label): void
    {
        $verifyPos = \strpos($region, 'PackageVerifier::verifyFile');
        $extractPos = \strpos($region, '->extractTo(');
        $this->assertNotFalse($verifyPos, "{$label}: checksum verification call missing");
        $this->assertNotFalse($extractPos, "{$label}: extraction call missing");
        $this->assertLessThan($extractPos, $verifyPos, "{$label}: mismatch must abort BEFORE extractTo");
    }

    private function readSource(string $relativePath): string
    {
        $src = \file_get_contents(SYSTEM_ROOT . '/' . $relativePath);
        $this->assertIsString($src, "source under test must exist: {$relativePath}");

        return $src;
    }

    /**
     * Run a callable expected to fail-closed and return the caught exception
     * (null when it passed) — allows several assertions per case without
     * consuming expectException().
     */
    private function captureIntegrity(callable $fn): ?PackageIntegrityException
    {
        try {
            $fn();
        } catch (PackageIntegrityException $e) {
            return $e;
        }

        return null;
    }

    private function tempFile(string $contents): string
    {
        $path = \sys_get_temp_dir() . '/razypv_' . \bin2hex(\random_bytes(6)) . '.phar';
        \file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }

    /**
     * @param array<mixed> $map
     */
    private function tempConfigFile(array $map): string
    {
        $path = \sys_get_temp_dir() . '/razypv_repo_' . \bin2hex(\random_bytes(6)) . '.inc.php';
        \file_put_contents($path, '<?php return ' . \var_export($map, true) . ';');
        $this->tempPaths[] = $path;

        return $path;
    }
}
