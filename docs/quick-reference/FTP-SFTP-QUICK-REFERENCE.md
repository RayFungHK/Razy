# FTP & SFTP Quick Reference

Quick lookup for the Razy FTP/FTPS and SFTP file transfer clients.

---

## FTPClient (`Razy\FTPClient`)

```php
use Razy\FTPClient;
```

### Connection

| Method | Return | Description |
|--------|--------|-------------|
| `new FTPClient($host, $port = 21, $timeout = 30, $ssl = false)` | `FTPClient` | Connect (FTPS if `$ssl = true`) |
| `login($user = 'anonymous', $pass = '')` | `$this` | Authenticate |
| `disconnect()` | `$this` | Close connection |
| `isConnected()` | `bool` | Check connection status |
| `isSecure()` | `bool` | Check if using FTPS |
| `getConnection()` | `?Connection` | Get raw FTP resource |

### Settings

| Method | Return | Description |
|--------|--------|-------------|
| `setPassive($passive = true)` | `$this` | Toggle passive mode |
| `setTransferMode($mode)` | `$this` | `MODE_BINARY` or `MODE_ASCII` |
| `setTimeout($seconds)` | `$this` | Set network timeout |

### File Operations

| Method | Return | Description |
|--------|--------|-------------|
| `upload($local, $remote, $mode?)` | `$this` | Upload file |
| `download($remote, $local, $mode?)` | `$this` | Download file |
| `uploadString($content, $remote, $mode?)` | `$this` | Upload string as file |
| `downloadString($remote, $mode?)` | `string` | Download file as string |
| `delete($remote)` | `$this` | Delete file |
| `rename($old, $new)` | `$this` | Rename or move |
| `chmod($remote, $mode)` | `$this` | Set permissions |
| `size($remote)` | `int` | File size (-1 on failure) |
| `lastModified($remote)` | `int` | Mtime (-1 on failure) |
| `exists($remote)` | `bool` | Check existence |
| `isDir($remote)` | `bool` | Check if directory |

### Directory Operations

| Method | Return | Description |
|--------|--------|-------------|
| `pwd()` | `string` | Current directory |
| `chdir($dir)` | `$this` | Change directory |
| `cdup()` | `$this` | Go to parent |
| `mkdir($dir)` | `$this` | Create directory |
| `mkdirRecursive($dir)` | `$this` | Create nested directories |
| `rmdir($dir)` | `$this` | Remove empty directory |
| `rmdirRecursive($dir)` | `$this` | Remove directory tree |
| `listFiles($dir = '.')` | `array` | List names |
| `listDetails($dir = '.')` | `array` | Raw LIST output |

### Other

| Method | Return | Description |
|--------|--------|-------------|
| `raw($command)` | `array` | Execute raw FTP command |
| `systemType()` | `string` | Server OS type |
| `getLogs()` | `array` | Session log |
| `clearLogs()` | `$this` | Clear logs |

---

## SFTPClient (`Razy\SFTPClient`)

```php
use Razy\SFTPClient;
```

### Connection & Auth

| Method | Return | Description |
|--------|--------|-------------|
| `new SFTPClient($host, $port = 22, $timeout = 30)` | `SFTPClient` | Connect via SSH |
| `loginWithPassword($user, $pass)` | `$this` | Password auth |
| `loginWithKey($user, $pubKey, $privKey, $phrase?)` | `$this` | Public key auth |
| `loginWithAgent($user)` | `$this` | SSH agent auth |
| `disconnect()` | `$this` | Close connection |
| `isConnected()` | `bool` | Check status |
| `getFingerprint($flags?)` | `string` | Server fingerprint |
| `getAuthMethods($user)` | `array` | Accepted auth methods |

### File Operations

| Method | Return | Description |
|--------|--------|-------------|
| `upload($local, $remote, $perms = 0644)` | `$this` | Upload file |
| `download($remote, $local)` | `$this` | Download file |
| `uploadString($content, $remote, $perms = 0644)` | `$this` | Upload string |
| `downloadString($remote)` | `string` | Download as string |
| `delete($remote)` | `$this` | Delete file |
| `rename($old, $new)` | `$this` | Rename or move |
| `chmod($remote, $mode)` | `$this` | Set permissions |
| `symlink($target, $link)` | `$this` | Create symlink |
| `readlink($link)` | `string` | Read symlink target |
| `realpath($path)` | `string` | Resolve absolute path |

### File Info

| Method | Return | Description |
|--------|--------|-------------|
| `stat($path)` | `array\|false` | Full stat (size, mode, uid, gid, mtime, atime) |
| `lstat($path)` | `array\|false` | Stat without following symlinks |
| `exists($path)` | `bool` | Check existence |
| `isDir($path)` | `bool` | Is directory |
| `isFile($path)` | `bool` | Is regular file |
| `isLink($path)` | `bool` | Is symlink |
| `size($path)` | `int` | File size (-1 on failure) |
| `lastModified($path)` | `int` | Mtime (-1 on failure) |

### Directory Operations

| Method | Return | Description |
|--------|--------|-------------|
| `mkdir($dir, $perms = 0755, $recursive = false)` | `$this` | Create directory |
| `rmdir($dir)` | `$this` | Remove empty directory |
| `rmdirRecursive($dir)` | `$this` | Remove directory tree |
| `listFiles($dir = '.')` | `array` | List names (no . or ..) |

### SSH

| Method | Return | Description |
|--------|--------|-------------|
| `exec($command)` | `string` | Execute shell command |
| `getLogs()` | `array` | Session log |
| `clearLogs()` | `$this` | Clear logs |

---

## Quick Example

```php
// FTP
$ftp = new FTPClient('ftp.example.com', 21, 30, true);
$ftp->login('user', 'pass')
    ->upload('/local/file.txt', '/remote/file.txt')
    ->disconnect();

// SFTP
$sftp = new SFTPClient('ssh.example.com');
$sftp->loginWithKey('deploy', '/keys/id.pub', '/keys/id')
     ->upload('/dist/app.phar', '/opt/app/app.phar', 0755)
     ->exec('systemctl restart app')
     ->disconnect();
```

---

## Extension Requirements

| Client | Extension | Install |
|--------|-----------|---------|
| `FTPClient` | `ext-ftp` | Bundled with PHP (usually enabled) |
| `SFTPClient` | `ext-ssh2` | `pecl install ssh2` |
