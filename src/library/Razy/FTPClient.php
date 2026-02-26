<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * FTP/FTPS client for file transfer operations. Provides upload, download,
 * directory listing, rename, delete, and directory management over FTP
 * with optional TLS/SSL encryption (FTPS).
 *
 * Requires PHP ext-ftp.
 *
 *
 * @license MIT
 */

namespace Razy;

use FTP\Connection;
use InvalidArgumentException;
use Razy\Exception\FTPException;

/**
 * FTP/FTPS client for remote file transfer.
 *
 * Provides a fluent, object-oriented API around PHP's built-in FTP functions
 * with support for passive mode, TLS encryption (FTPS), binary/ASCII transfer,
 * recursive directory operations, and session logging.
 *
 * Usage:
 *   $ftp = new FTPClient('ftp.example.com');
 *   $ftp->login('user', 'pass');
 *   $ftp->upload('/local/file.txt', '/remote/file.txt');
 *   $ftp->disconnect();
 *
 * @class FTPClient
 */
class FTPClient
{
    /** @var int Binary transfer mode (images, archives, executables) */
    public const MODE_BINARY = FTP_BINARY;

    /** @var int ASCII transfer mode (text files) */
    public const MODE_ASCII = FTP_ASCII;

    /** @var Connection|null The active FTP connection resource */
    private ?Connection $connection = null;

    /** @var bool Whether passive mode is enabled */
    private bool $passive = true;

    /** @var int<1,2> Default transfer mode (FTP_ASCII=1 or FTP_BINARY=2) */
    private int $transferMode = FTP_BINARY;

    /** @var array<int, string> Session log entries for debugging */
    private array $logs = [];

    /** @var bool Whether connected via FTPS (TLS/SSL) */
    private bool $secure = false;

    /**
     * FTPClient constructor.
     *
     * Opens an FTP connection to the specified host. To use FTPS (implicit SSL),
     * set $ssl to true. The connection is established but not authenticated
     * until login() is called.
     *
     * @param string $host FTP server hostname or IP address
     * @param int $port FTP port (default: 21)
     * @param int $timeout Connection timeout in seconds (default: 30)
     * @param bool $ssl Use FTPS (FTP over TLS/SSL) (default: false)
     *
     * @throws Error If the FTP extension is not loaded or connection fails
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 21,
        private readonly int $timeout = 30,
        bool $ssl = false,
    ) {
        if (!\extension_loaded('ftp')) {
            throw new FTPException('The FTP extension (ext-ftp) is required for FTPClient.');
        }

        $this->secure = $ssl;

        $conn = $ssl
            ? @\ftp_ssl_connect($host, $port, $timeout)
            : @\ftp_connect($host, $port, $timeout);

        if (!$conn) {
            throw new FTPException("Failed to connect to FTP server: {$host}:{$port}");
        }

        $this->connection = $conn;

        $this->log("Connected to {$host}:{$port}" . ($ssl ? ' (FTPS)' : ''));
    }

    /**
     * Destructor ??automatically disconnects if still connected.
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    // ==================== AUTHENTICATION ====================

    /**
     * Authenticate with the FTP server.
     *
     * @param string $username FTP username (default: 'anonymous')
     * @param string $password FTP password (default: '' for anonymous)
     *
     * @return $this
     *
     * @throws Error If login fails or no connection exists
     */
    public function login(string $username = 'anonymous', string $password = ''): static
    {
        $this->requireConnection();

        if (!@\ftp_login($this->connection, $username, $password)) {
            throw new FTPException("FTP login failed for user: {$username}");
        }

        $this->log("Logged in as {$username}");

        // Enable passive mode by default after login
        if ($this->passive) {
            \ftp_pasv($this->connection, true);
            $this->log('Passive mode enabled');
        }

        return $this;
    }

    // ==================== CONNECTION SETTINGS ====================

    /**
     * Enable or disable passive mode.
     *
     * Passive mode is recommended for connections through firewalls and NAT.
     * Must be called after login() to take effect immediately.
     *
     * @param bool $passive True to enable passive mode (default: true)
     *
     * @return $this
     */
    public function setPassive(bool $passive = true): static
    {
        $this->passive = $passive;

        if ($this->connection) {
            \ftp_pasv($this->connection, $passive);
            $this->log('Passive mode ' . ($passive ? 'enabled' : 'disabled'));
        }

        return $this;
    }

    /**
     * Set the default transfer mode.
     *
     * @param int $mode FTPClient::MODE_BINARY or FTPClient::MODE_ASCII
     *
     * @return $this
     *
     * @throws InvalidArgumentException If mode is invalid
     */
    public function setTransferMode(int $mode): static
    {
        if ($mode !== FTP_BINARY && $mode !== FTP_ASCII) {
            throw new InvalidArgumentException('Transfer mode must be FTPClient::MODE_BINARY or FTPClient::MODE_ASCII.');
        }

        $this->transferMode = $mode;

        return $this;
    }

    /**
     * Set the FTP timeout for network operations.
     *
     * @param int $seconds Timeout in seconds
     *
     * @return $this
     */
    public function setTimeout(int $seconds): static
    {
        $this->requireConnection();

        \ftp_set_option($this->connection, FTP_TIMEOUT_SEC, $seconds);
        $this->log("Timeout set to {$seconds}s");

        return $this;
    }

    // ==================== FILE OPERATIONS ====================

    /**
     * Upload a local file to the remote server.
     *
     * @param string $localPath Path to the local file
     * @param string $remotePath Remote destination path
     * @param int|null $mode Transfer mode (null = use default)
     *
     * @return $this
     *
     * @throws Error If the local file doesn't exist or upload fails
     */
    public function upload(string $localPath, string $remotePath, ?int $mode = null): static
    {
        $this->requireConnection();

        if (!\is_file($localPath)) {
            throw new FTPException("Local file not found: {$localPath}");
        }

        $mode ??= $this->transferMode;

        if (!@\ftp_put($this->connection, $remotePath, $localPath, $mode)) {
            throw new FTPException("Failed to upload: {$localPath} ??{$remotePath}");
        }

        $this->log("Uploaded: {$localPath} ??{$remotePath}");

        return $this;
    }

    /**
     * Download a remote file to the local filesystem.
     *
     * @param string $remotePath Remote file path
     * @param string $localPath Local destination path
     * @param int|null $mode Transfer mode (null = use default)
     *
     * @return $this
     *
     * @throws Error If download fails
     */
    public function download(string $remotePath, string $localPath, ?int $mode = null): static
    {
        $this->requireConnection();

        $mode ??= $this->transferMode;

        // Ensure the local directory exists
        $localDir = \dirname($localPath);
        if (!\is_dir($localDir)) {
            \mkdir($localDir, 0o755, true);
        }

        if (!@\ftp_get($this->connection, $localPath, $remotePath, $mode)) {
            throw new FTPException("Failed to download: {$remotePath} ??{$localPath}");
        }

        $this->log("Downloaded: {$remotePath} ??{$localPath}");

        return $this;
    }

    /**
     * Upload a string as a remote file.
     *
     * @param string $content String content to upload
     * @param string $remotePath Remote destination path
     * @param int|null $mode Transfer mode (null = use default)
     *
     * @return $this
     *
     * @throws Error If upload fails
     */
    public function uploadString(string $content, string $remotePath, ?int $mode = null): static
    {
        $this->requireConnection();

        $mode ??= $this->transferMode;
        $stream = \fopen('php://temp', 'r+');
        \fwrite($stream, $content);
        \rewind($stream);

        if (!@\ftp_fput($this->connection, $remotePath, $stream, $mode)) {
            \fclose($stream);
            throw new FTPException("Failed to upload string to: {$remotePath}");
        }

        \fclose($stream);
        $this->log("Uploaded string to: {$remotePath} (" . \strlen($content) . ' bytes)');

        return $this;
    }

    /**
     * Download a remote file and return its content as a string.
     *
     * @param string $remotePath Remote file path
     * @param int|null $mode Transfer mode (null = use default)
     *
     * @return string File content
     *
     * @throws Error If download fails
     */
    public function downloadString(string $remotePath, ?int $mode = null): string
    {
        $this->requireConnection();

        $mode ??= $this->transferMode;
        $stream = \fopen('php://temp', 'r+');

        if (!@\ftp_fget($this->connection, $stream, $remotePath, $mode)) {
            \fclose($stream);
            throw new FTPException("Failed to download: {$remotePath}");
        }

        \rewind($stream);
        $content = \stream_get_contents($stream);
        \fclose($stream);

        $this->log("Downloaded string from: {$remotePath} (" . \strlen($content) . ' bytes)');

        return $content;
    }

    /**
     * Delete a file on the remote server.
     *
     * @param string $remotePath Remote file path to delete
     *
     * @return $this
     *
     * @throws Error If deletion fails
     */
    public function delete(string $remotePath): static
    {
        $this->requireConnection();

        if (!@\ftp_delete($this->connection, $remotePath)) {
            throw new FTPException("Failed to delete file: {$remotePath}");
        }

        $this->log("Deleted: {$remotePath}");

        return $this;
    }

    /**
     * Rename or move a file/directory on the remote server.
     *
     * @param string $oldPath Current remote path
     * @param string $newPath New remote path
     *
     * @return $this
     *
     * @throws Error If rename fails
     */
    public function rename(string $oldPath, string $newPath): static
    {
        $this->requireConnection();

        if (!@\ftp_rename($this->connection, $oldPath, $newPath)) {
            throw new FTPException("Failed to rename: {$oldPath} ??{$newPath}");
        }

        $this->log("Renamed: {$oldPath} ??{$newPath}");

        return $this;
    }

    /**
     * Set permissions on a remote file (chmod).
     *
     * @param string $remotePath Remote file path
     * @param int $mode Octal permission mode (e.g. 0644, 0755)
     *
     * @return $this
     *
     * @throws Error If chmod fails
     */
    public function chmod(string $remotePath, int $mode): static
    {
        $this->requireConnection();

        if (@\ftp_chmod($this->connection, $mode, $remotePath) === false) {
            throw new FTPException("Failed to chmod {$remotePath} to " . \decoct($mode));
        }

        $this->log("Chmod: {$remotePath} ??" . \decoct($mode));

        return $this;
    }

    // ==================== DIRECTORY OPERATIONS ====================

    /**
     * Get the current working directory.
     *
     * @return string Current remote directory path
     *
     * @throws Error If the operation fails
     */
    public function pwd(): string
    {
        $this->requireConnection();

        $dir = @\ftp_pwd($this->connection);
        if ($dir === false) {
            throw new FTPException('Failed to get current directory.');
        }

        return $dir;
    }

    /**
     * Change the current working directory.
     *
     * @param string $directory Remote directory path
     *
     * @return $this
     *
     * @throws Error If the directory change fails
     */
    public function chdir(string $directory): static
    {
        $this->requireConnection();

        if (!@\ftp_chdir($this->connection, $directory)) {
            throw new FTPException("Failed to change directory: {$directory}");
        }

        $this->log("Changed directory: {$directory}");

        return $this;
    }

    /**
     * Move up to the parent directory.
     *
     * @return $this
     *
     * @throws Error If the operation fails
     */
    public function cdup(): static
    {
        $this->requireConnection();

        if (!@\ftp_cdup($this->connection)) {
            throw new FTPException('Failed to move to parent directory.');
        }

        $this->log('Changed to parent directory');

        return $this;
    }

    /**
     * Create a directory on the remote server.
     *
     * @param string $directory Remote directory path to create
     *
     * @return $this
     *
     * @throws Error If directory creation fails
     */
    public function mkdir(string $directory): static
    {
        $this->requireConnection();

        if (@\ftp_mkdir($this->connection, $directory) === false) {
            throw new FTPException("Failed to create directory: {$directory}");
        }

        $this->log("Created directory: {$directory}");

        return $this;
    }

    /**
     * Recursively create a directory path on the remote server.
     *
     * Creates all intermediate directories as needed, similar to `mkdir -p`.
     *
     * @param string $directory Remote directory path to create
     *
     * @return $this
     */
    public function mkdirRecursive(string $directory): static
    {
        $this->requireConnection();

        $parts = \explode('/', \trim($directory, '/'));
        $currentDir = $this->pwd();
        $path = (\str_starts_with($directory, '/')) ? '/' : '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $path .= $part . '/';

            // Try to change into it; if that fails, create it
            if (!@\ftp_chdir($this->connection, $path)) {
                @\ftp_mkdir($this->connection, $path);
            }
        }

        // Restore the original directory
        @\ftp_chdir($this->connection, $currentDir);
        $this->log("Created directory path: {$directory}");

        return $this;
    }

    /**
     * Remove a directory on the remote server.
     *
     * @param string $directory Remote directory path to remove (must be empty)
     *
     * @return $this
     *
     * @throws Error If directory removal fails
     */
    public function rmdir(string $directory): static
    {
        $this->requireConnection();

        if (!@\ftp_rmdir($this->connection, $directory)) {
            throw new FTPException("Failed to remove directory: {$directory}");
        }

        $this->log("Removed directory: {$directory}");

        return $this;
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * @param string $directory Remote directory path to remove
     *
     * @return $this
     */
    public function rmdirRecursive(string $directory): static
    {
        $this->requireConnection();

        $items = @\ftp_nlist($this->connection, $directory);

        if ($items !== false) {
            foreach ($items as $item) {
                $basename = \basename($item);
                if ($basename === '.' || $basename === '..') {
                    continue;
                }

                // Attempt to delete as a file; if that fails, treat as directory
                if (!@\ftp_delete($this->connection, $item)) {
                    $this->rmdirRecursive($item);
                }
            }
        }

        @\ftp_rmdir($this->connection, $directory);
        $this->log("Recursively removed: {$directory}");

        return $this;
    }

    /**
     * List files and directories in a remote directory.
     *
     * @param string $directory Remote directory path (default: current directory)
     *
     * @return array<string> Array of file/directory names
     */
    public function listFiles(string $directory = '.'): array
    {
        $this->requireConnection();

        $list = @\ftp_nlist($this->connection, $directory);

        if ($list === false) {
            return [];
        }

        // Filter out . and ..
        return \array_values(\array_filter($list, fn ($item) => !\in_array(\basename($item), ['.', '..'])));
    }

    /**
     * Get a detailed directory listing (raw format).
     *
     * Returns the output of the FTP LIST command, which typically includes
     * file permissions, owner, size, date, and name.
     *
     * @param string $directory Remote directory path (default: current directory)
     *
     * @return array<string> Array of raw listing lines
     */
    public function listDetails(string $directory = '.'): array
    {
        $this->requireConnection();

        $list = @\ftp_rawlist($this->connection, $directory);

        return $list ?: [];
    }

    /**
     * Get the size of a remote file in bytes.
     *
     * @param string $remotePath Remote file path
     *
     * @return int File size in bytes, or -1 if the file doesn't exist or is a directory
     */
    public function size(string $remotePath): int
    {
        $this->requireConnection();

        return \ftp_size($this->connection, $remotePath);
    }

    /**
     * Get the last modified time of a remote file.
     *
     * @param string $remotePath Remote file path
     *
     * @return int Unix timestamp of last modification, or -1 on failure
     */
    public function lastModified(string $remotePath): int
    {
        $this->requireConnection();

        return \ftp_mdtm($this->connection, $remotePath);
    }

    /**
     * Check if a remote path exists.
     *
     * @param string $remotePath Remote path to check
     *
     * @return bool True if the path exists
     */
    public function exists(string $remotePath): bool
    {
        $this->requireConnection();

        // Try size first (works for files)
        if (\ftp_size($this->connection, $remotePath) !== -1) {
            return true;
        }

        // Try changing to it (works for directories)
        $currentDir = @\ftp_pwd($this->connection);
        if (@\ftp_chdir($this->connection, $remotePath)) {
            @\ftp_chdir($this->connection, $currentDir);
            return true;
        }

        return false;
    }

    /**
     * Check if a remote path is a directory.
     *
     * @param string $remotePath Remote path to check
     *
     * @return bool True if the path is a directory
     */
    public function isDir(string $remotePath): bool
    {
        $this->requireConnection();

        $currentDir = @\ftp_pwd($this->connection);
        if (@\ftp_chdir($this->connection, $remotePath)) {
            @\ftp_chdir($this->connection, $currentDir);
            return true;
        }

        return false;
    }

    // ==================== RAW COMMANDS ====================

    /**
     * Execute a raw FTP command (SITE command).
     *
     * @param string $command The FTP command to execute
     *
     * @return array<string> Server response lines
     */
    public function raw(string $command): array
    {
        $this->requireConnection();

        $response = @\ftp_raw($this->connection, $command);
        $this->log("RAW: {$command} ??" . \implode(' ', $response));

        return $response;
    }

    /**
     * Get the system type of the remote server.
     *
     * @return string System type identifier (e.g., 'UNIX', 'Windows_NT')
     */
    public function systemType(): string
    {
        $this->requireConnection();

        return \ftp_systype($this->connection) ?: 'UNKNOWN';
    }

    // ==================== CONNECTION MANAGEMENT ====================

    /**
     * Disconnect from the FTP server.
     *
     * @return $this
     */
    public function disconnect(): static
    {
        if ($this->connection) {
            @\ftp_close($this->connection);
            $this->connection = null;
            $this->log('Disconnected');
        }

        return $this;
    }

    /**
     * Check if the client is currently connected.
     *
     * @return bool True if connected
     */
    public function isConnected(): bool
    {
        if (!$this->connection) {
            return false;
        }

        // Test connection by getting the current directory
        return @\ftp_pwd($this->connection) !== false;
    }

    /**
     * Check if using FTPS (TLS/SSL).
     *
     * @return bool True if connected via FTPS
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * Get the underlying FTP connection resource.
     *
     * Provides access to the raw FTP\Connection for advanced operations
     * not covered by this class.
     *
     * @return Connection|null The FTP connection, or null if not connected
     */
    public function getConnection(): ?Connection
    {
        return $this->connection;
    }

    // ==================== LOGGING ====================

    /**
     * Retrieve the session logs.
     *
     * @return array<int, string> Array of log entries
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Clear the session logs.
     *
     * @return $this
     */
    public function clearLogs(): static
    {
        $this->logs = [];

        return $this;
    }

    // ==================== INTERNAL ====================

    /**
     * Assert that a connection is active.
     *
     * @throws Error If no active connection exists
     */
    private function requireConnection(): void
    {
        if (!$this->connection) {
            throw new FTPException('No active FTP connection. Call connect() first.');
        }
    }

    /**
     * Append an entry to the session log.
     *
     * @param string $message Log message
     */
    private function log(string $message): void
    {
        $this->logs[] = '[' . \date('Y-m-d H:i:s') . '] ' . $message;
    }
}
