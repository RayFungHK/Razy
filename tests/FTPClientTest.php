<?php
/**
 * Unit tests for Razy\FTPClient.
 *
 * This file is part of Razy v0.5.
 * Tests constructor validation, parameter checks, and transfer mode settings.
 * FTP operations requiring a live server are tested via integration tests.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\FTPException;
use Razy\FTPClient;

#[CoversClass(FTPClient::class)]
class FTPClientTest extends TestCase
{
    // ==================== EXTENSION CHECK ====================

    public function testFtpExtensionLoaded(): void
    {
        $this->assertTrue(
            extension_loaded('ftp'),
            'The FTP extension (ext-ftp) must be loaded to run these tests.'
        );
    }

    // ==================== CONSTRUCTOR ====================

    public function testConstructorFailsWithInvalidHost(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('Failed to connect');

        // Use non-routable IP to trigger fast failure
        new FTPClient('192.0.2.1', 21, 2);
    }

    // ==================== TRANSFER MODE ====================

    public function testSetTransferModeAcceptsBinary(): void
    {
        // We can't connect to a real server, so test the constant values
        $this->assertEquals(FTP_BINARY, FTPClient::MODE_BINARY);
        $this->assertEquals(FTP_ASCII, FTPClient::MODE_ASCII);
    }

    public function testSetTransferModeRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Transfer mode must be');

        // Create a mock that bypasses the constructor connection
        $client = $this->createPartialMockWithoutConnection();
        $client->setTransferMode(999);
    }

    // ==================== CONSTANTS ====================

    public function testModeConstants(): void
    {
        $this->assertIsInt(FTPClient::MODE_BINARY);
        $this->assertIsInt(FTPClient::MODE_ASCII);
        $this->assertNotEquals(FTPClient::MODE_BINARY, FTPClient::MODE_ASCII);
    }

    // ==================== DISCONNECTED STATE ====================

    public function testDisconnectOnAlreadyDisconnected(): void
    {
        // Create mock without connection, verify disconnect is safe
        $client = $this->createPartialMockWithoutConnection();
        $client->disconnect();

        $this->assertFalse($client->isConnected());
    }

    public function testIsConnectedReturnsFalseWithoutConnection(): void
    {
        $client = $this->createPartialMockWithoutConnection();

        $this->assertFalse($client->isConnected());
    }

    public function testIsSecureReturnsFalseByDefault(): void
    {
        $client = $this->createPartialMockWithoutConnection();

        $this->assertFalse($client->isSecure());
    }

    public function testGetConnectionReturnsNullWhenDisconnected(): void
    {
        $client = $this->createPartialMockWithoutConnection();

        $this->assertNull($client->getConnection());
    }

    // ==================== LOGGING ====================

    public function testGetLogsReturnsArray(): void
    {
        $client = $this->createPartialMockWithoutConnection();

        $this->assertIsArray($client->getLogs());
    }

    public function testClearLogsEmptiesArray(): void
    {
        $client = $this->createPartialMockWithoutConnection();
        $client->clearLogs();

        $this->assertCount(0, $client->getLogs());
    }

    // ==================== REQUIRE CONNECTION ERRORS ====================

    public function testLoginWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->login('user', 'pass');
    }

    public function testUploadWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->upload('/tmp/test.txt', '/remote/test.txt');
    }

    public function testDownloadWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->download('/remote/test.txt', '/tmp/test.txt');
    }

    public function testDeleteWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->delete('/remote/test.txt');
    }

    public function testRenameWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->rename('/old.txt', '/new.txt');
    }

    public function testPwdWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->pwd();
    }

    public function testChdirWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->chdir('/remote');
    }

    public function testMkdirWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->mkdir('/remote/newdir');
    }

    public function testRmdirWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);
        $this->expectExceptionMessage('No active FTP connection');

        $client = $this->createPartialMockWithoutConnection();
        $client->rmdir('/remote/dir');
    }

    public function testListFilesWithoutConnectionReturnsEmpty(): void
    {
        // listFiles doesn't throw, it calls requireConnection
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->listFiles();
    }

    public function testSizeWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->size('/remote/file.txt');
    }

    public function testChmodWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->chmod('/remote/file.txt', 0644);
    }

    public function testRawWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->raw('STAT');
    }

    public function testUploadStringWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->uploadString('content', '/remote/file.txt');
    }

    public function testDownloadStringWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->downloadString('/remote/file.txt');
    }

    public function testCdupWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->cdup();
    }

    public function testSetTimeoutWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->setTimeout(60);
    }

    public function testSystemTypeWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->systemType();
    }

    public function testExistsWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->exists('/remote/file.txt');
    }

    public function testIsDirWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->isDir('/remote/dir');
    }

    public function testLastModifiedWithoutConnectionThrows(): void
    {
        $this->expectException(FTPException::class);

        $client = $this->createPartialMockWithoutConnection();
        $client->lastModified('/remote/file.txt');
    }

    // ==================== HELPER ====================

    /**
     * Create an FTPClient instance with the connection property set to null,
     * bypassing the constructor's connection attempt.
     */
    private function createPartialMockWithoutConnection(): FTPClient
    {
        $reflection = new \ReflectionClass(FTPClient::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        return $instance;
    }
}
