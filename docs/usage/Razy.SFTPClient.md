# Razy\SFTPClient

## Summary
- SFTP file transfer client using the `ext-ssh2` extension.
- Instance-based: each object represents one SSH/SFTP session.
- Supports password, public-key, and SSH-agent authentication.
- Full SFTP operations plus remote command execution via `exec()`.
- Fluent API — most mutating methods return `$this`.
- Session logging for debugging and auditing.

## Construction
- `new SFTPClient(string $host, int $port = 22, int $timeout = 30)`.
- Connects immediately via `ssh2_connect()`.
- Throws `Razy\Error` if connection fails or `ext-ssh2` is not loaded.

## Key Methods

### Authentication

| Method | Return | Description |
|--------|--------|-------------|
| `loginWithPassword(string $user, string $pass)` | `$this` | Password authentication |
| `loginWithKey(string $user, string $pubKey, string $privKey, ?string $passphrase = null)` | `$this` | Public key authentication |
| `loginWithAgent(string $user)` | `$this` | SSH agent authentication |

### Connection

| Method | Return | Description |
|--------|--------|-------------|
| `disconnect()` | `$this` | Close session |
| `isConnected()` | `bool` | Check connection status |
| `getFingerprint(int $flags = SSH2_FINGERPRINT_MD5 \| SSH2_FINGERPRINT_HEX)` | `string` | Server host key fingerprint |
| `getAuthMethods(string $user)` | `array` | List accepted auth methods |

### File Operations

| Method | Return | Description |
|--------|--------|-------------|
| `upload(string $local, string $remote, int $perms = 0644)` | `$this` | Upload local file |
| `download(string $remote, string $local)` | `$this` | Download file |
| `uploadString(string $content, string $remote, int $perms = 0644)` | `$this` | Upload string as file |
| `downloadString(string $remote)` | `string` | Download file as string |
| `delete(string $remote)` | `$this` | Delete file |
| `rename(string $old, string $new)` | `$this` | Rename or move |
| `chmod(string $remote, int $mode)` | `$this` | Set permissions |

### Symlinks

| Method | Return | Description |
|--------|--------|-------------|
| `symlink(string $target, string $link)` | `$this` | Create symbolic link |
| `readlink(string $link)` | `string` | Read symlink target |
| `realpath(string $path)` | `string` | Resolve absolute path |

### File Info

| Method | Return | Description |
|--------|--------|-------------|
| `stat(string $path)` | `array\|false` | Full stat (follows symlinks) |
| `lstat(string $path)` | `array\|false` | Stat without following symlinks |
| `exists(string $path)` | `bool` | Check existence |
| `isDir(string $path)` | `bool` | Check if directory |
| `isFile(string $path)` | `bool` | Check if regular file |
| `isLink(string $path)` | `bool` | Check if symlink |
| `size(string $path)` | `int` | File size (-1 on failure) |
| `lastModified(string $path)` | `int` | Unix mtime (-1 on failure) |

### Directory Operations

| Method | Return | Description |
|--------|--------|-------------|
| `mkdir(string $dir, int $perms = 0755, bool $recursive = false)` | `$this` | Create directory |
| `rmdir(string $dir)` | `$this` | Remove empty directory |
| `rmdirRecursive(string $dir)` | `$this` | Remove directory tree |
| `listFiles(string $dir = '.')` | `array` | List names (excludes `.` and `..`) |

### SSH Command Execution

| Method | Return | Description |
|--------|--------|-------------|
| `exec(string $command)` | `string` | Execute shell command and return output |

### Logging

| Method | Return | Description |
|--------|--------|-------------|
| `getLogs()` | `array` | Get session log entries |
| `clearLogs()` | `$this` | Clear the log array |

## Usage Example

```php
use Razy\SFTPClient;

// Connect and authenticate with key
$sftp = new SFTPClient('server.example.com');
$sftp->loginWithKey('deploy', '/keys/deploy.pub', '/keys/deploy');

// Upload application
$sftp->upload('/dist/app.phar', '/opt/app/app.phar', 0755);
$sftp->upload('/dist/config.yaml', '/opt/app/config.yaml');

// Remote server management
$sftp->exec('systemctl restart myapp');
$output = $sftp->exec('systemctl status myapp');
echo $output;

// File operations
if ($sftp->exists('/opt/app/backup')) {
    $sftp->rmdirRecursive('/opt/app/backup');
}
$sftp->mkdir('/opt/app/backup');

// Download logs
$logContent = $sftp->downloadString('/var/log/myapp/error.log');

// Symlink management
$sftp->symlink('/opt/app/releases/v1.2', '/opt/app/current');

// Debug session
print_r($sftp->getLogs());

$sftp->disconnect();
```

## Usage Notes
- Connection is established in the constructor; authentication is separate (call `loginWith*()` after construction).
- SFTP subsystem is auto-initialized on the first file/dir operation after authentication.
- `upload()` and `uploadString()` accept a permissions parameter (default `0644`).
- `stat()` returns an associative array with keys: `size`, `uid`, `gid`, `mode`, `atime`, `mtime`.
- `listFiles()` filters out `.` and `..` entries automatically.
- `exec()` can run arbitrary commands on the remote server (requires shell access).
- `getFingerprint()` returns the server's host key fingerprint for verification.
- All operations log to an internal array, retrievable via `getLogs()`.
- Throws `Razy\Error` on failures.

## Requirements
- PHP extension: `ext-ssh2` (`pecl install ssh2`).
