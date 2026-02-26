<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * SFTP client for secure file transfer operations over SSH.
 * Provides upload, download, directory listing, rename, delete,
 * and directory management using PHP's ssh2 extension or a pure
 * PHP stream-based fallback.
 *
 * Requires PHP ext-ssh2 for full functionality.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\SSHException;

/**
 * SFTP client for secure file transfer over SSH.
 *
 * Provides an object-oriented API for SFTP operations including file
 * upload/download, directory management, permissions, and symlinks.
 * Supports password authentication, public-key authentication, and
 * SSH agent forwarding.
 *
 * Usage:
 *   $sftp = new SFTPClient('sftp.example.com');
 *   $sftp->loginWithPassword('user', 'pass');
 *   $sftp->upload('/local/file.txt', '/remote/file.txt');
 *   $sftp->disconnect();
 *
 * @class SFTPClient
 */
class SFTPClient
{
    /** @var resource|null The SSH2 connection resource */
    private mixed $session = null;

    /** @var resource|null The SFTP subsystem resource */
    private mixed $sftp = null;

    /** @var array<int, string> Session log entries for debugging */
    private array $logs = [];

    /** @var bool Whether the session is authenticated */
    private bool $authenticated = false;

    /**
     * SFTPClient constructor.
     *
     * Opens an SSH connection to the specified host. The connection is
     * established but not authenticated until one of the login methods
     * is called.
     *
     * @param string $host SSH server hostname or IP address
     * @param int $port SSH port (default: 22)
     * @param int $timeout Connection timeout in seconds (default: 30)
     *
     * @throws Error If the ssh2 extension is not loaded or connection fails
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 22,
        private readonly int $timeout = 30,
    ) {
        if (!\extension_loaded('ssh2')) {
            throw new SSHException('The SSH2 extension (ext-ssh2) is required for SFTPClient.');
        }

        $this->session = @\ssh2_connect($host, $port);

        if (!$this->session) {
            throw new SSHException("Failed to connect to SSH server: {$host}:{$port}");
        }

        $this->log("Connected to {$host}:{$port}");
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
     * Authenticate with username and password.
     *
     * @param string $username SSH username
     * @param string $password SSH password
     *
     * @return $this
     *
     * @throws Error If authentication fails
     */
    public function loginWithPassword(string $username, string $password): static
    {
        $this->requireSession();

        if (!@\ssh2_auth_password($this->session, $username, $password)) {
            throw new SSHException("SSH password authentication failed for user: {$username}");
        }

        $this->authenticated = true;
        $this->initSftp();
        $this->log("Authenticated as {$username} (password)");

        return $this;
    }

    /**
     * Authenticate with public key.
     *
     * @param string $username SSH username
     * @param string $publicKeyFile Path to the public key file
     * @param string $privateKeyFile Path to the private key file
     * @param string|null $passphrase Passphrase for the private key (if encrypted)
     *
     * @return $this
     *
     * @throws Error If authentication fails or key files don't exist
     */
    public function loginWithKey(
        string $username,
        string $publicKeyFile,
        string $privateKeyFile,
        ?string $passphrase = null,
    ): static {
        $this->requireSession();

        if (!\is_file($publicKeyFile)) {
            throw new SSHException("Public key file not found: {$publicKeyFile}");
        }

        if (!\is_file($privateKeyFile)) {
            throw new SSHException("Private key file not found: {$privateKeyFile}");
        }

        $result = @\ssh2_auth_pubkey_file(
            $this->session,
            $username,
            $publicKeyFile,
            $privateKeyFile,
            $passphrase ?? '',
        );

        if (!$result) {
            throw new SSHException("SSH public key authentication failed for user: {$username}");
        }

        $this->authenticated = true;
        $this->initSftp();
        $this->log("Authenticated as {$username} (public key)");

        return $this;
    }

    /**
     * Authenticate using the SSH agent.
     *
     * @param string $username SSH username
     *
     * @return $this
     *
     * @throws Error If authentication fails
     */
    public function loginWithAgent(string $username): static
    {
        $this->requireSession();

        if (!\function_exists('ssh2_auth_agent')) {
            throw new SSHException('SSH agent authentication requires ssh2_auth_agent() (available in libssh2 >= 1.2.3).');
        }

        if (!@\ssh2_auth_agent($this->session, $username)) {
            throw new SSHException("SSH agent authentication failed for user: {$username}");
        }

        $this->authenticated = true;
        $this->initSftp();
        $this->log("Authenticated as {$username} (agent)");

        return $this;
    }

    // ==================== FILE OPERATIONS ====================

    /**
     * Upload a local file to the remote server.
     *
     * @param string $localPath Path to the local file
     * @param string $remotePath Remote destination path
     * @param int $permissions File permissions (default: 0644)
     *
     * @return $this
     *
     * @throws Error If the local file doesn't exist or upload fails
     */
    public function upload(string $localPath, string $remotePath, int $permissions = 0o644): static
    {
        $this->requireSftp();

        if (!\is_file($localPath)) {
            throw new SSHException("Local file not found: {$localPath}");
        }

        $content = \file_get_contents($localPath);
        if ($content === false) {
            throw new SSHException("Failed to read local file: {$localPath}");
        }

        $stream = @\fopen("ssh2.sftp://{$this->sftp}{$remotePath}", 'w');
        if (!$stream) {
            throw new SSHException("Failed to open remote file for writing: {$remotePath}");
        }

        $written = \fwrite($stream, $content);
        \fclose($stream);

        if ($written === false) {
            throw new SSHException("Failed to write to remote file: {$remotePath}");
        }

        // Set permissions
        @\ssh2_sftp_chmod($this->sftp, $remotePath, $permissions);

        $this->log("Uploaded: {$localPath} ??{$remotePath} ({$written} bytes)");

        return $this;
    }

    /**
     * Download a remote file to the local filesystem.
     *
     * @param string $remotePath Remote file path
     * @param string $localPath Local destination path
     *
     * @return $this
     *
     * @throws Error If download fails
     */
    public function download(string $remotePath, string $localPath): static
    {
        $this->requireSftp();

        // Ensure the local directory exists
        $localDir = \dirname($localPath);
        if (!\is_dir($localDir)) {
            \mkdir($localDir, 0o755, true);
        }

        $content = $this->downloadString($remotePath);

        if (\file_put_contents($localPath, $content) === false) {
            throw new SSHException("Failed to write local file: {$localPath}");
        }

        $this->log("Downloaded: {$remotePath} ??{$localPath}");

        return $this;
    }

    /**
     * Upload a string as a remote file.
     *
     * @param string $content String content to upload
     * @param string $remotePath Remote destination path
     * @param int $permissions File permissions (default: 0644)
     *
     * @return $this
     *
     * @throws Error If upload fails
     */
    public function uploadString(string $content, string $remotePath, int $permissions = 0o644): static
    {
        $this->requireSftp();

        $stream = @\fopen("ssh2.sftp://{$this->sftp}{$remotePath}", 'w');
        if (!$stream) {
            throw new SSHException("Failed to open remote file for writing: {$remotePath}");
        }

        $written = \fwrite($stream, $content);
        \fclose($stream);

        if ($written === false) {
            throw new SSHException("Failed to write to remote file: {$remotePath}");
        }

        @\ssh2_sftp_chmod($this->sftp, $remotePath, $permissions);

        $this->log("Uploaded string to: {$remotePath} ({$written} bytes)");

        return $this;
    }

    /**
     * Download a remote file and return its content as a string.
     *
     * @param string $remotePath Remote file path
     *
     * @return string File content
     *
     * @throws Error If download fails
     */
    public function downloadString(string $remotePath): string
    {
        $this->requireSftp();

        $stream = @\fopen("ssh2.sftp://{$this->sftp}{$remotePath}", 'r');
        if (!$stream) {
            throw new SSHException("Failed to open remote file for reading: {$remotePath}");
        }

        $content = \stream_get_contents($stream);
        \fclose($stream);

        if ($content === false) {
            throw new SSHException("Failed to read remote file: {$remotePath}");
        }

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
        $this->requireSftp();

        if (!@\ssh2_sftp_unlink($this->sftp, $remotePath)) {
            throw new SSHException("Failed to delete file: {$remotePath}");
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
        $this->requireSftp();

        if (!@\ssh2_sftp_rename($this->sftp, $oldPath, $newPath)) {
            throw new SSHException("Failed to rename: {$oldPath} ??{$newPath}");
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
        $this->requireSftp();

        if (!@\ssh2_sftp_chmod($this->sftp, $remotePath, $mode)) {
            throw new SSHException("Failed to chmod {$remotePath} to " . \decoct($mode));
        }

        $this->log("Chmod: {$remotePath} ??" . \decoct($mode));

        return $this;
    }

    /**
     * Create a symbolic link.
     *
     * @param string $target Path the symlink points to
     * @param string $linkPath Path of the symlink to create
     *
     * @return $this
     *
     * @throws Error If symlink creation fails
     */
    public function symlink(string $target, string $linkPath): static
    {
        $this->requireSftp();

        if (!@\ssh2_sftp_symlink($this->sftp, $target, $linkPath)) {
            throw new SSHException("Failed to create symlink: {$linkPath} ??{$target}");
        }

        $this->log("Symlink: {$linkPath} ??{$target}");

        return $this;
    }

    /**
     * Read the target of a symbolic link.
     *
     * @param string $linkPath Remote symlink path
     *
     * @return string The target path
     *
     * @throws Error If readlink fails
     */
    public function readlink(string $linkPath): string
    {
        $this->requireSftp();

        $target = @\ssh2_sftp_readlink($this->sftp, $linkPath);
        if ($target === false) {
            throw new SSHException("Failed to read symlink: {$linkPath}");
        }

        return $target;
    }

    /**
     * Resolve the real path of a remote file.
     *
     * @param string $remotePath Remote path (may contain . or ..)
     *
     * @return string The resolved absolute path
     *
     * @throws Error If realpath fails
     */
    public function realpath(string $remotePath): string
    {
        $this->requireSftp();

        $resolved = @\ssh2_sftp_realpath($this->sftp, $remotePath);
        if ($resolved === false) {
            throw new SSHException("Failed to resolve path: {$remotePath}");
        }

        return $resolved;
    }

    // ==================== DIRECTORY OPERATIONS ====================

    /**
     * Create a directory on the remote server.
     *
     * @param string $directory Remote directory path to create
     * @param int $permissions Directory permissions (default: 0755)
     * @param bool $recursive Create parent directories as needed (default: false)
     *
     * @return $this
     *
     * @throws Error If directory creation fails
     */
    public function mkdir(string $directory, int $permissions = 0o755, bool $recursive = false): static
    {
        $this->requireSftp();

        if (!@\ssh2_sftp_mkdir($this->sftp, $directory, $permissions, $recursive)) {
            throw new SSHException("Failed to create directory: {$directory}");
        }

        $this->log("Created directory: {$directory}" . ($recursive ? ' (recursive)' : ''));

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
        $this->requireSftp();

        if (!@\ssh2_sftp_rmdir($this->sftp, $directory)) {
            throw new SSHException("Failed to remove directory: {$directory}");
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
        $this->requireSftp();

        $items = $this->listFiles($directory);

        foreach ($items as $item) {
            $fullPath = \rtrim($directory, '/') . '/' . $item;
            $stat = $this->stat($fullPath);

            if ($stat && ($stat['mode'] & 0o040000)) {
                // Directory
                $this->rmdirRecursive($fullPath);
            } else {
                // File
                @\ssh2_sftp_unlink($this->sftp, $fullPath);
            }
        }

        @\ssh2_sftp_rmdir($this->sftp, $directory);
        $this->log("Recursively removed: {$directory}");

        return $this;
    }

    /**
     * List files and directories in a remote directory.
     *
     * @param string $directory Remote directory path (default: current directory)
     *
     * @return array<string> Array of file/directory names (excluding . and ..)
     */
    public function listFiles(string $directory = '.'): array
    {
        $this->requireSftp();

        $handle = @\opendir("ssh2.sftp://{$this->sftp}{$directory}");
        if (!$handle) {
            return [];
        }

        $files = [];
        while (($entry = \readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $files[] = $entry;
        }

        \closedir($handle);
        \sort($files);

        return $files;
    }

    /**
     * Get detailed file information (stat).
     *
     * @param string $remotePath Remote file path
     *
     * @return array<string, int>|false File stat array or false on failure.
     *                                  Keys: size, uid, gid, mode, atime, mtime
     */
    public function stat(string $remotePath): array|false
    {
        $this->requireSftp();

        return @\ssh2_sftp_stat($this->sftp, $remotePath);
    }

    /**
     * Get detailed file information without following symlinks (lstat).
     *
     * @param string $remotePath Remote file path
     *
     * @return array<string, int>|false File stat array or false on failure
     */
    public function lstat(string $remotePath): array|false
    {
        $this->requireSftp();

        return @\ssh2_sftp_lstat($this->sftp, $remotePath);
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
        return $this->stat($remotePath) !== false;
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
        $stat = $this->stat($remotePath);

        return $stat !== false && ($stat['mode'] & 0o040000) !== 0;
    }

    /**
     * Check if a remote path is a regular file.
     *
     * @param string $remotePath Remote path to check
     *
     * @return bool True if the path is a regular file
     */
    public function isFile(string $remotePath): bool
    {
        $stat = $this->stat($remotePath);

        return $stat !== false && ($stat['mode'] & 0o100000) !== 0;
    }

    /**
     * Check if a remote path is a symbolic link.
     *
     * @param string $remotePath Remote path to check
     *
     * @return bool True if the path is a symlink
     */
    public function isLink(string $remotePath): bool
    {
        $lstat = $this->lstat($remotePath);

        return $lstat !== false && ($lstat['mode'] & 0o120000) !== 0;
    }

    /**
     * Get the size of a remote file in bytes.
     *
     * @param string $remotePath Remote file path
     *
     * @return int File size in bytes, or -1 on failure
     */
    public function size(string $remotePath): int
    {
        $stat = $this->stat($remotePath);

        return $stat ? ($stat['size'] ?? -1) : -1;
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
        $stat = $this->stat($remotePath);

        return $stat ? ($stat['mtime'] ?? -1) : -1;
    }

    // ==================== SSH COMMANDS ====================

    /**
     * Execute a command on the remote server via SSH.
     *
     * @param string $command The shell command to execute
     *
     * @return string Command output
     *
     * @throws Error If command execution fails
     */
    public function exec(string $command): string
    {
        $this->requireSession();

        $stream = @\ssh2_exec($this->session, $command);
        if (!$stream) {
            throw new SSHException("Failed to execute SSH command: {$command}");
        }

        \stream_set_blocking($stream, true);
        $output = \stream_get_contents($stream);
        \fclose($stream);

        $this->log("Exec: {$command}");

        return $output !== false ? $output : '';
    }

    // ==================== CONNECTION MANAGEMENT ====================

    /**
     * Disconnect from the SSH/SFTP server.
     *
     * @return $this
     */
    public function disconnect(): static
    {
        if ($this->session) {
            // ssh2_disconnect() is available in newer ext-ssh2 versions
            if (\function_exists('ssh2_disconnect')) {
                @\ssh2_disconnect($this->session);
            }
            $this->session = null;
            $this->sftp = null;
            $this->authenticated = false;
            $this->log('Disconnected');
        }

        return $this;
    }

    /**
     * Check if the client is connected and authenticated.
     *
     * @return bool True if connected and authenticated
     */
    public function isConnected(): bool
    {
        return $this->session !== null && $this->authenticated;
    }

    /**
     * Get the server's SSH fingerprint.
     *
     * @param int $flags Fingerprint flags (SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX by default)
     *
     * @return string The server's fingerprint string
     */
    public function getFingerprint(int $flags = SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX): string
    {
        $this->requireSession();

        return \ssh2_fingerprint($this->session, $flags);
    }

    /**
     * Get the list of authentication methods accepted by the server.
     *
     * @param string $username Username to check
     *
     * @return array<string> List of accepted auth methods (e.g., 'password', 'publickey')
     */
    public function getAuthMethods(string $username): array
    {
        $this->requireSession();

        $methods = @\ssh2_auth_none($this->session, $username);

        if (\is_array($methods)) {
            return $methods;
        }

        // If ssh2_auth_none returns true, 'none' auth succeeded
        return $methods === true ? ['none'] : [];
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
     * Initialize the SFTP subsystem after authentication.
     *
     * @throws Error If SFTP initialization fails
     */
    private function initSftp(): void
    {
        $this->sftp = @\ssh2_sftp($this->session);

        if (!$this->sftp) {
            throw new SSHException('Failed to initialize SFTP subsystem.');
        }

        $this->log('SFTP subsystem initialized');
    }

    /**
     * Assert that an SSH session exists.
     *
     * @throws Error If no session exists
     */
    private function requireSession(): void
    {
        if (!$this->session) {
            throw new SSHException('No active SSH session. Call connect() first.');
        }
    }

    /**
     * Assert that the SFTP subsystem is initialized.
     *
     * @throws Error If SFTP is not initialized
     */
    private function requireSftp(): void
    {
        if (!$this->sftp) {
            throw new SSHException('SFTP subsystem not initialized. Call login first.');
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
