<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * S1 caller-migration pins (OAuth dossier): production code must not
 * hand-roll cURL outside the hardened HttpClient door. House style for
 * network-bound behaviour is source-pin assertions (MigrateCommandTest
 * precedent) — these tests do not send a single byte.
 */
#[CoversNothing]
class S1CallerMigrationTest extends TestCase
{
    public function testPublishHasNoHandRolledCurl(): void
    {
        $src = $this->src('system/terminal/publish.inc.php');
        $this->assertStringNotContainsString('curl_', $src, 'all ten GitHub API sites moved to the shared door');
        $this->assertStringContainsString('githubApiRequest', $src);
        $this->assertStringContainsString('HttpTransportException', $src, 'transport failures carry the reason into the operator-facing error');
        $this->assertStringContainsString("'application/octet-stream'", $src, 'asset upload kept its content type via raw_body');
    }

    public function testRepoInstallerHasNoHandRolledCurl(): void
    {
        $src = $this->src('library/Razy/RepoInstaller.php');
        $this->assertStringNotContainsString('curl_', $src, 'four JSON reads, two HEAD probes and the streaming download all migrated');
        $this->assertStringContainsString('githubJson', $src);
        $this->assertStringContainsString("'sink' => \$tempFile", $src, 'archive download stays streamed, never buffered');
        $this->assertStringContainsString('isSecureUrl', $src, 'the operator-facing HTTPS gate (with its own message) survived migration');
    }

    public function testRepositoryManagerReadsThroughTheClient(): void
    {
        $src = $this->src('library/Razy/RepositoryManager.php');
        $this->assertStringNotContainsString('curl_init', $src);
        $this->assertStringContainsString('HttpTransportException', $src, 'unreachable repository used to be a silent null; it notifies now');
    }

    public function testHttpTransportUsesTheClientDoor(): void
    {
        $src = $this->src('library/Razy/PackageManager/HttpTransport.php');
        $this->assertStringNotContainsString('curl_', $src);
        $this->assertStringNotContainsString('file_get_contents', $src, 'the stream-context reader era is over');
        $this->assertStringNotContainsString('isResponseOk', $src, 'the first-status-line reader died with the migration (redirects now judged at the final response)');
        $this->assertStringContainsString("'sink' => \$destinationPath", $src);
    }

    public function testClientHonoursTheNewOptions(): void
    {
        $src = $this->src('library/Razy/Http/HttpClient.php');
        $this->assertStringContainsString("\$options['raw_body']", $src);
        $this->assertStringContainsString("\$options['sink']", $src);
        $this->assertStringContainsString('CURLOPT_XFERINFOFUNCTION', $src, 'progress survived migration on the modern (non-deprecated) callback');
        $this->assertStringContainsString('if ($sinkOpened) {', $src, 'a sink the client opened is always closed - no handle leak');
    }

    private function src(string $relative): string
    {
        $path = SYSTEM_ROOT . '/src/' . $relative;
        $this->assertFileExists($path);

        return (string) \file_get_contents($path);
    }
}
