# Razy\FTPClient

## Summary
- FTP/FTPS file transfer client using PHP's built-in `ext-ftp`.
- Instance-based: each object represents one connection.
- Supports both plain FTP and implicit FTPS (SSL/TLS).
- Fluent API — most mutating methods return `$this`.
- Session logging for debugging and auditing.

## Construction
- `new FTPClient(string $host, int $port = 21, int $timeout = 30, bool $ssl = false)`.
- Connects immediately via `ftp_connect()` or `ftp_ssl_connect()`.
- Throws `Razy\Error` if connection fails or `ext-ftp` is not loaded.

## Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `MODE_BINARY` | `FTP_BINARY` | Binary transfer mode (default) |
| `MODE_ASCII` | `FTP_ASCII` | ASCII transfer mode |

## Key Methods

### Authentication & Connection

| Method | Return | Description |
|--------|--------|-------------|
| `login(string $user = 'anonymous', string $pass = '')` | `$this` | FTP LOGIN, enables passive mode |
| `disconnect()` | `$this` | Close connection |
| `isConnected()` | `bool` | Check connection status |
| `isSecure()` | `bool` | Whether using FTPS |
| `getConnection()` | `?Connection` | Raw FTP\Connection resource |

### Settings

| Method | Return | Description |
|--------|--------|-------------|
| `setPassive(bool $passive = true)` | `$this` | Toggle passive mode |
| `setTransferMode(int $mode)` | `$this` | Set binary or ASCII mode |
| `setTimeout(int $seconds)` | `$this` | Set network timeout |

### File Operations

| Method | Return | Description |
|--------|--------|-------------|
| `upload(string $local, string $remote, ?int $mode = null)` | `$this` | Upload local file to server |
| `download(string $remote, string $local, ?int $mode = null)` | `$this` | Download file from server |
| `uploadString(string $content, string $remote, ?int $mode = null)` | `$this` | Upload string content as file |
| `downloadString(string $remote, ?int $mode = null)` | `string` | Download file content as string |
| `delete(string $remote)` | `$this` | Delete remote file |
| `rename(string $old, string $new)` | `$this` | Rename or move file |
| `chmod(string $remote, int $mode)` | `$this` | Set file permissions |

### File Info

| Method | Return | Description |
|--------|--------|-------------|
| `size(string $remote)` | `int` | File size in bytes (-1 on failure) |
| `lastModified(string $remote)` | `int` | Unix mtime (-1 on failure) |
| `exists(string $remote)` | `bool` | Check if file/dir exists |
| `isDir(string $remote)` | `bool` | Check if path is a directory |

### Directory Operations

| Method | Return | Description |
|--------|--------|-------------|
| `pwd()` | `string` | Print working directory |
| `chdir(string $dir)` | `$this` | Change directory |
| `cdup()` | `$this` | Go to parent directory |
| `mkdir(string $dir)` | `$this` | Create directory |
| `mkdirRecursive(string $dir)` | `$this` | Create nested directory structure |
| `rmdir(string $dir)` | `$this` | Remove empty directory |
| `rmdirRecursive(string $dir)` | `$this` | Remove directory tree recursively |
| `listFiles(string $dir = '.')` | `array` | List file/dir names |
| `listDetails(string $dir = '.')` | `array` | Raw LIST output |

### Raw & System

| Method | Return | Description |
|--------|--------|-------------|
| `raw(string $command)` | `array` | Execute raw FTP command |
| `systemType()` | `string` | Server OS type |

### Logging

| Method | Return | Description |
|--------|--------|-------------|
| `getLogs()` | `array` | Get session log entries |
| `clearLogs()` | `$this` | Clear the log array |

## Usage Example

```php
use Razy\FTPClient;

// Connect with FTPS
$ftp = new FTPClient('ftp.example.com', 21, 30, true);
$ftp->login('deploy', 's3cret');

// Upload a release
$ftp->chdir('/releases')
    ->mkdir('v1.2.0')
    ->chdir('v1.2.0')
    ->upload('/dist/app.phar', 'app.phar')
    ->upload('/dist/config.yaml', 'config.yaml');

// Download logs
$logContent = $ftp->downloadString('/var/log/app.log');

// List and cleanup old releases
$releases = $ftp->listFiles('/releases');
foreach ($releases as $dir) {
    if ($dir < 'v1.0.0') {
        $ftp->rmdirRecursive("/releases/$dir");
    }
}

// Check session log
print_r($ftp->getLogs());

$ftp->disconnect();
```

## Usage Notes
- Connection is established in the constructor; no separate `connect()` needed.
- `login()` automatically enables passive mode (can be changed with `setPassive(false)`).
- All file-transfer methods accept an optional `$mode` override; otherwise uses the default `MODE_BINARY`.
- `uploadString()` and `downloadString()` use `php://temp` streams internally.
- `mkdirRecursive()` splits the path and creates each segment.
- `rmdirRecursive()` walks the tree depth-first, deleting files then directories.
- All operations log to an internal array, retrievable via `getLogs()`.
- Throws `Razy\Error` on failures (connection, auth, file ops).

## Requirements
- PHP extension: `ext-ftp` (bundled with PHP, usually enabled by default).
