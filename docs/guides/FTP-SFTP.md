# FTP & SFTP Guide

Complete guide to Razy's FTP/FTPS and SFTP clients — connection, authentication, file transfer, directory management, and integration patterns.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [FTP Client](#ftp-client)
   - [Connection & Authentication](#ftp-connection--authentication)
   - [File Operations](#ftp-file-operations)
   - [Directory Operations](#ftp-directory-operations)
   - [Settings & Modes](#settings--modes)
4. [SFTP Client](#sftp-client)
   - [Connection & Authentication](#sftp-connection--authentication)
   - [File Operations](#sftp-file-operations)
   - [Directory Operations](#sftp-directory-operations)
   - [SSH Commands](#ssh-commands)
5. [Common API](#common-api)
6. [Integration Patterns](#integration-patterns)
7. [Security Best Practices](#security-best-practices)
8. [Troubleshooting](#troubleshooting)

---

## Overview

Razy provides two dedicated file transfer clients:

| Class | Protocol | Extension | Default Port |
|-------|----------|-----------|--------------|
| `FTPClient` | FTP / FTPS (FTP over TLS) | `ext-ftp` (bundled) | 21 |
| `SFTPClient` | SFTP (SSH File Transfer) | `ext-ssh2` (optional) | 22 |

Both classes follow the same design principles:
- **Instance-based**: Each connection is a separate object
- **Fluent API**: Most methods return `$this` for chaining
- **Session logging**: Built-in debug logging for troubleshooting
- **Error handling**: Throws `Razy\Error` on all failures
- **Auto-disconnect**: Destructor closes the connection automatically

### When to Use Which

| Scenario | Recommended |
|----------|-------------|
| Legacy servers, shared hosting | `FTPClient` |
| Need TLS encryption over FTP | `FTPClient` (FTPS mode) |
| Modern servers with SSH access | `SFTPClient` |
| Key-based authentication | `SFTPClient` |
| Firewall-friendly (single port) | `SFTPClient` |
| No ext-ssh2 available | `FTPClient` |

---

## Architecture

```
FTPClient (ext-ftp)                      SFTPClient (ext-ssh2)
├── __construct(host, port, timeout, ssl) ├── __construct(host, port, timeout)
├── login(user, pass)                    ├── loginWithPassword(user, pass)
│                                        ├── loginWithKey(user, pub, priv, phrase?)
│                                        ├── loginWithAgent(user)
│                                        │
├── upload(local, remote)                ├── upload(local, remote, perms?)
├── download(remote, local)              ├── download(remote, local)
├── uploadString(content, remote)        ├── uploadString(content, remote, perms?)
├── downloadString(remote)               ├── downloadString(remote)
├── delete(remote)                       ├── delete(remote)
├── rename(old, new)                     ├── rename(old, new)
├── chmod(remote, mode)                  ├── chmod(remote, mode)
│                                        ├── symlink(target, link)
│                                        ├── readlink(link)
│                                        ├── realpath(path)
│                                        │
├── mkdir(dir)                           ├── mkdir(dir, perms?, recursive?)
├── mkdirRecursive(dir)                  ├── rmdir(dir)
├── rmdir(dir)                           ├── rmdirRecursive(dir)
├── rmdirRecursive(dir)                  ├── listFiles(dir?)
├── listFiles(dir?)                      │
├── listDetails(dir?)                    ├── stat(path)
├── pwd()                                ├── lstat(path)
├── chdir(dir)                           ├── exists(path)
├── cdup()                               ├── isDir(path)
├── size(remote)                         ├── isFile(path)
├── lastModified(remote)                 ├── isLink(path)
├── exists(remote)                       ├── size(path)
├── isDir(remote)                        ├── lastModified(path)
│                                        │
├── raw(command)                         ├── exec(command)
├── systemType()                         ├── getFingerprint(flags?)
│                                        ├── getAuthMethods(user)
│                                        │
├── disconnect()                         ├── disconnect()
├── isConnected()                        ├── isConnected()
├── isSecure()                           │
├── getConnection()                      │
├── getLogs()                            ├── getLogs()
└── clearLogs()                          └── clearLogs()
```

---

## FTP Client

### Requirements

- PHP `ext-ftp` (bundled with PHP, usually enabled by default)

### FTP Connection & Authentication

```php
use Razy\FTPClient;

// Plain FTP
$ftp = new FTPClient('ftp.example.com');
$ftp->login('username', 'password');

// FTPS (FTP over TLS/SSL)
$ftp = new FTPClient('ftp.example.com', 21, 30, true);
$ftp->login('username', 'password');

// Anonymous FTP
$ftp = new FTPClient('ftp.example.com');
$ftp->login(); // defaults to 'anonymous'

// Custom port and timeout
$ftp = new FTPClient('ftp.example.com', 2121, 60);
$ftp->login('user', 'pass');
```

### FTP File Operations

```php
// Upload a file
$ftp->upload('/local/path/report.pdf', '/remote/reports/report.pdf');

// Download a file
$ftp->download('/remote/data/export.csv', '/local/downloads/export.csv');

// Upload string content directly
$ftp->uploadString('{"key": "value"}', '/remote/config.json');

// Download to string
$content = $ftp->downloadString('/remote/config.json');

// Delete a file
$ftp->delete('/remote/old-file.txt');

// Rename / move
$ftp->rename('/remote/draft.txt', '/remote/final.txt');

// Set permissions
$ftp->chmod('/remote/script.sh', 0755);

// Check file info
$size = $ftp->size('/remote/file.txt');           // bytes or -1
$mtime = $ftp->lastModified('/remote/file.txt');  // Unix timestamp or -1
$exists = $ftp->exists('/remote/file.txt');        // bool
```

### FTP Directory Operations

```php
// Get current directory
$cwd = $ftp->pwd();

// Change directory
$ftp->chdir('/remote/subdir');
$ftp->cdup(); // go up one level

// Create directories
$ftp->mkdir('/remote/newdir');
$ftp->mkdirRecursive('/remote/deep/nested/path');

// Remove directories
$ftp->rmdir('/remote/emptydir');
$ftp->rmdirRecursive('/remote/dir-with-contents');

// List files
$files = $ftp->listFiles('/remote/subdir');
// ['file1.txt', 'file2.txt', 'subdir/']

// Detailed listing (raw FTP LIST output)
$details = $ftp->listDetails('/remote/subdir');
// ['drwxr-xr-x 2 user group 4096 Jan 01 12:00 subdir', ...]

// Check if path is a directory
$isDir = $ftp->isDir('/remote/subdir');
```

### Settings & Modes

```php
// Passive mode (default: enabled)
$ftp->setPassive(true);   // recommended for firewalls/NAT
$ftp->setPassive(false);  // active mode

// Transfer mode
$ftp->setTransferMode(FTPClient::MODE_BINARY); // default — for all file types
$ftp->setTransferMode(FTPClient::MODE_ASCII);  // for text files only

// Override mode per operation
$ftp->upload('/local/text.txt', '/remote/text.txt', FTPClient::MODE_ASCII);

// Connection timeout
$ftp->setTimeout(60);

// Raw FTP commands
$response = $ftp->raw('STAT');
$sysType = $ftp->systemType(); // 'UNIX', 'Windows_NT', etc.
```

### Fluent Chaining

```php
$ftp = new FTPClient('ftp.example.com');
$ftp->login('user', 'pass')
    ->setPassive(true)
    ->chdir('/uploads')
    ->upload('/local/file1.txt', 'file1.txt')
    ->upload('/local/file2.txt', 'file2.txt')
    ->disconnect();
```

---

## SFTP Client

### Requirements

- PHP `ext-ssh2` (PECL package, not bundled with PHP)
- Install: `pecl install ssh2` or your OS package manager

### SFTP Connection & Authentication

```php
use Razy\SFTPClient;

// Password authentication
$sftp = new SFTPClient('ssh.example.com');
$sftp->loginWithPassword('username', 'password');

// Public key authentication
$sftp = new SFTPClient('ssh.example.com');
$sftp->loginWithKey(
    'username',
    '/home/user/.ssh/id_rsa.pub',
    '/home/user/.ssh/id_rsa',
    'optional-passphrase'  // null if no passphrase
);

// SSH agent authentication
$sftp = new SFTPClient('ssh.example.com');
$sftp->loginWithAgent('username');

// Custom port
$sftp = new SFTPClient('ssh.example.com', 2222, 60);
$sftp->loginWithPassword('user', 'pass');
```

### SFTP File Operations

```php
// Upload with permissions
$sftp->upload('/local/app.php', '/remote/app.php', 0644);

// Download
$sftp->download('/remote/data.json', '/local/data.json');

// String upload/download
$sftp->uploadString('<?php echo "hello";', '/remote/test.php', 0644);
$content = $sftp->downloadString('/remote/config.yml');

// Delete
$sftp->delete('/remote/old-file.txt');

// Rename / move
$sftp->rename('/remote/draft.txt', '/remote/final.txt');

// Permissions
$sftp->chmod('/remote/script.sh', 0755);

// Symlinks
$sftp->symlink('/remote/current', '/remote/releases/v2.0');
$target = $sftp->readlink('/remote/current');
$resolved = $sftp->realpath('/remote/path/../file.txt');

// File info
$stat = $sftp->stat('/remote/file.txt');
// ['size' => 1024, 'uid' => 1000, 'gid' => 1000, 'mode' => 33188, 'atime' => ..., 'mtime' => ...]

$size = $sftp->size('/remote/file.txt');
$mtime = $sftp->lastModified('/remote/file.txt');
$exists = $sftp->exists('/remote/file.txt');
$isDir = $sftp->isDir('/remote/path');
$isFile = $sftp->isFile('/remote/file.txt');
$isLink = $sftp->isLink('/remote/symlink');
```

### SFTP Directory Operations

```php
// Create directory
$sftp->mkdir('/remote/newdir', 0755);
$sftp->mkdir('/remote/deep/nested/path', 0755, true); // recursive

// Remove directory
$sftp->rmdir('/remote/emptydir');
$sftp->rmdirRecursive('/remote/dir-with-contents');

// List files (excludes . and ..)
$files = $sftp->listFiles('/remote/subdir');
```

### SSH Commands

```php
// Execute a remote command
$output = $sftp->exec('ls -la /var/www');
$output = $sftp->exec('whoami');
$output = $sftp->exec('df -h');

// Server fingerprint verification
$fingerprint = $sftp->getFingerprint();
// e.g., 'E4:A1:2B:...'

// Check available auth methods
$methods = $sftp->getAuthMethods('username');
// ['password', 'publickey']
```

---

## Common API

Both clients share these patterns:

### Connection Status

```php
$client->isConnected();  // bool
$client->disconnect();   // explicit close
// Auto-closes on __destruct()
```

### Logging

```php
// Get all session log entries
$logs = $client->getLogs();
foreach ($logs as $entry) {
    echo $entry . PHP_EOL;
}
// [2026-02-17 10:30:00] Connected to ftp.example.com:21
// [2026-02-17 10:30:01] Logged in as user
// [2026-02-17 10:30:01] Passive mode enabled
// ...

// Clear logs
$client->clearLogs();
```

---

## Integration Patterns

### Deployment Script

```php
use Razy\FTPClient;

$ftp = new FTPClient('deploy.example.com', 21, 30, true);
$ftp->login('deploy', $password)
    ->setPassive(true);

// Upload all files from a build directory
$buildDir = __DIR__ . '/build';
$files = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($buildDir, \FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    $localPath = $file->getPathname();
    $remotePath = '/www/' . str_replace([$buildDir, '\\'], ['', '/'], $localPath);

    if ($file->isDir()) {
        $ftp->mkdirRecursive($remotePath);
    } else {
        $ftp->upload($localPath, $remotePath);
    }
}

$ftp->disconnect();
```

### Backup Download

```php
use Razy\SFTPClient;

$sftp = new SFTPClient('backup.example.com');
$sftp->loginWithKey('backup', '/keys/backup.pub', '/keys/backup');

$files = $sftp->listFiles('/backups/daily');
foreach ($files as $file) {
    $sftp->download(
        "/backups/daily/{$file}",
        "/local/backups/{$file}"
    );
}

$sftp->disconnect();
```

### Configuration Sync

```php
use Razy\SFTPClient;

$sftp = new SFTPClient('app-server.internal');
$sftp->loginWithPassword('admin', $password);

// Read remote config
$remoteConfig = $sftp->downloadString('/etc/myapp/config.yml');

// Update and push back
$updatedConfig = str_replace('debug: true', 'debug: false', $remoteConfig);
$sftp->uploadString($updatedConfig, '/etc/myapp/config.yml', 0644);

// Restart the service
$sftp->exec('systemctl restart myapp');

$sftp->disconnect();
```

---

## Security Best Practices

1. **Prefer SFTP over FTP** — SFTP encrypts the entire session including credentials.
2. **Use FTPS if FTP is required** — Pass `ssl: true` to the FTPClient constructor.
3. **Use key-based authentication** — Avoid passwords where possible; use `loginWithKey()`.
4. **Verify server fingerprints** — Use `getFingerprint()` to validate the server identity before authentication.
5. **Store credentials securely** — Never hardcode passwords; use environment variables or `Crypt::Decrypt()`.
6. **Use passive mode** — FTP passive mode (`setPassive(true)`) is more firewall-friendly.
7. **Set file permissions explicitly** — Always specify permissions when uploading via SFTP.
8. **Disconnect explicitly** — Call `disconnect()` when done, don't rely solely on the destructor.
9. **Log for auditing** — Use `getLogs()` to record transfer activity.
10. **Limit SSH command execution** — Validate and sanitize any user input passed to `exec()`.

---

## Troubleshooting

### FTP Connection Refused

- Check that port 21 (or custom port) is open in the firewall.
- Verify the hostname resolves correctly.
- For FTPS, the server must support implicit or explicit TLS.

### FTP Passive Mode Issues

- Some servers require passive mode; others don't support it.
- Toggle with `setPassive(true/false)`.
- Passive mode is enabled by default after `login()`.

### SFTP "ext-ssh2 not available"

- Install via PECL: `pecl install ssh2`
- Or via OS package manager: `apt install php-ssh2`
- Verify with `php -m | grep ssh2`.

### SFTP Authentication Failures

- **Password**: Verify the server allows password auth (`getAuthMethods()`).
- **Key**: Ensure key files are readable by PHP, correct format (OpenSSH/PEM).
- **Agent**: Requires `ssh2_auth_agent()` (libssh2 >= 1.2.3).

### Large File Transfers

- Increase the timeout: `setTimeout(300)` for FTP.
- Use binary mode for non-text files (default).
- For SFTP, consider chunked reads for very large files.

### Transfer Mode Issues (FTP)

- Use `MODE_BINARY` (default) for all file types unless specifically dealing with text-only line-ending conversion.
- ASCII mode converts line endings, which corrupts binary files.
