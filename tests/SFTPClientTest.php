<?php
/**
 * Unit tests for Razy\SFTPClient.
 *
 * This file is part of Razy v0.5.
 * Tests constructor validation, parameter checks, and disconnected state handling.
 * SFTP operations requiring a live SSH server are tested via integration tests.
 *
 * Note: Tests in this file will be skipped if ext-ssh2 is not installed.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\SSHException;
use Razy\SFTPClient;

#[CoversClass(SFTPClient::class)]
class SFTPClientTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('ssh2')) {
            $this->markTestSkipped('The SSH2 extension (ext-ssh2) is not available.');
        }
    }

    // ==================== CONSTRUCTOR ====================

    public function testConstructorFailsWithInvalidHost(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('Failed to connect');

        // Use non-routable IP with short timeout to trigger fast failure
        new SFTPClient('192.0.2.1', 22, 2);
    }

    // ==================== DISCONNECTED STATE ====================

    public function testDisconnectOnAlreadyDisconnected(): void
    {
        $client = $this->createInstanceWithoutConnection();
        $client->disconnect();

        $this->assertFalse($client->isConnected());
    }

    public function testIsConnectedReturnsFalseWithoutSession(): void
    {
        $client = $this->createInstanceWithoutConnection();

        $this->assertFalse($client->isConnected());
    }

    // ==================== LOGGING ====================

    public function testGetLogsReturnsArray(): void
    {
        $client = $this->createInstanceWithoutConnection();

        $this->assertIsArray($client->getLogs());
    }

    public function testClearLogsEmptiesArray(): void
    {
        $client = $this->createInstanceWithoutConnection();
        $client->clearLogs();

        $this->assertCount(0, $client->getLogs());
    }

    // ==================== REQUIRE SESSION / SFTP ERRORS ====================

    public function testLoginWithPasswordWithoutSessionThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('No active SSH session');

        $client = $this->createInstanceWithoutConnection();
        $client->loginWithPassword('user', 'pass');
    }

    public function testLoginWithKeyWithoutSessionThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('No active SSH session');

        $client = $this->createInstanceWithoutConnection();
        $client->loginWithKey('user', '/path/to/pub.key', '/path/to/priv.key');
    }

    public function testLoginWithAgentWithoutSessionThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('No active SSH session');

        $client = $this->createInstanceWithoutConnection();
        $client->loginWithAgent('user');
    }

    public function testUploadWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->upload('/tmp/test.txt', '/remote/test.txt');
    }

    public function testDownloadWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->download('/remote/test.txt', '/tmp/test.txt');
    }

    public function testDeleteWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->delete('/remote/test.txt');
    }

    public function testRenameWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->rename('/old.txt', '/new.txt');
    }

    public function testChmodWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->chmod('/remote/file.txt', 0644);
    }

    public function testMkdirWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->mkdir('/remote/newdir');
    }

    public function testRmdirWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->rmdir('/remote/dir');
    }

    public function testListFilesWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->listFiles();
    }

    public function testStatWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->stat('/remote/file.txt');
    }

    public function testExistsWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->exists('/remote/file.txt');
    }

    public function testIsDirWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->isDir('/remote/dir');
    }

    public function testIsFileWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->isFile('/remote/file.txt');
    }

    public function testIsLinkWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->isLink('/remote/link');
    }

    public function testSizeWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->size('/remote/file.txt');
    }

    public function testLastModifiedWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->lastModified('/remote/file.txt');
    }

    public function testSymlinkWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->symlink('/target', '/link');
    }

    public function testReadlinkWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->readlink('/link');
    }

    public function testRealpathWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->realpath('/remote/path/../file.txt');
    }

    public function testUploadStringWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->uploadString('content', '/remote/file.txt');
    }

    public function testDownloadStringWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->downloadString('/remote/file.txt');
    }

    public function testExecWithoutSessionThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('No active SSH session');

        $client = $this->createInstanceWithoutConnection();
        $client->exec('ls -la');
    }

    public function testLstatWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->lstat('/remote/file.txt');
    }

    public function testRmdirRecursiveWithoutSftpThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('SFTP subsystem not initialized');

        $client = $this->createInstanceWithoutConnection();
        $client->rmdirRecursive('/remote/dir');
    }

    public function testGetFingerprintWithoutSessionThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('No active SSH session');

        $client = $this->createInstanceWithoutConnection();
        $client->getFingerprint();
    }

    public function testGetAuthMethodsWithoutSessionThrows(): void
    {
        $this->expectException(SSHException::class);
        $this->expectExceptionMessage('No active SSH session');

        $client = $this->createInstanceWithoutConnection();
        $client->getAuthMethods('user');
    }

    // ==================== HELPER ====================

    /**
     * Create an SFTPClient instance bypassing the constructor.
     */
    private function createInstanceWithoutConnection(): SFTPClient
    {
        $reflection = new \ReflectionClass(SFTPClient::class);
        return $reflection->newInstanceWithoutConstructor();
    }
}
