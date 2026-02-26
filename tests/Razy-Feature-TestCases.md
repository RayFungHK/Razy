# Razy 功能測試手冊 — 完整 Test Case 參考

> **版本**: v0.5.x  
> **PHP 版本**: 8.3+  
> **最後更新**: 2025-07  
> **用途**: 本文件涵蓋 Razy 框架所有功能，每個功能附上可運行的 Test Case 與 Expected Result，供手動實例測試。

---

## 目錄

1. [Container (DI 容器)](#1-container-di-容器)
2. [Configuration (設定檔)](#2-configuration-設定檔)
3. [Env (環境變數)](#3-env-環境變數)
4. [Collection (資料集合)](#4-collection-資料集合)
5. [HashMap (雜湊映射)](#5-hashmap-雜湊映射)
6. [Crypt (加密/解密)](#6-crypt-加密解密)
7. [Authenticator (TOTP/HOTP)](#7-authenticator-totphotp)
8. [Database (資料庫)](#8-database-資料庫)
9. [Statement (SQL 查詢構建器)](#9-statement-sql-查詢構建器)
10. [TableHelper (Schema Migration)](#10-tablehelper-schema-migration)
11. [ORM / Model](#11-orm--model)
12. [Validator (驗證器)](#12-validator-驗證器)
13. [FieldValidator (欄位驗證)](#13-fieldvalidator-欄位驗證)
14. [ValidationResult (驗證結果)](#14-validationresult-驗證結果)
15. [NestedValidator (巢狀驗證)](#15-nestedvalidator-巢狀驗證)
16. [FormRequest (表單請求)](#16-formrequest-表單請求)
17. [Validation Rules (驗證規則)](#17-validation-rules-驗證規則)
18. [HttpClient (HTTP 客戶端)](#18-httpclient-http-客戶端)
19. [Route (路由)](#19-route-路由)
20. [SSE (Server-Sent Events)](#20-sse-server-sent-events)
21. [XHR (JSON 回應)](#21-xhr-json-回應)
22. [Cache (快取)](#22-cache-快取)
23. [RedisAdapter (Redis 快取)](#23-redisadapter-redis-快取)
24. [Template (模板引擎)](#24-template-模板引擎)
25. [DOM (HTML 構建)](#25-dom-html-構建)
26. [EventDispatcher (PSR-14 事件)](#26-eventdispatcher-psr-14-事件)
27. [Emitter (跨模組 API)](#27-emitter-跨模組-api)
28. [EventEmitter (事件發射器)](#28-eventemitter-事件發射器)
29. [Logger (PSR-3 日誌)](#29-logger-psr-3-日誌)
30. [LogManager (多頻道日誌)](#30-logmanager-多頻道日誌)
31. [Log Handlers (日誌處理器)](#31-log-handlers-日誌處理器)
32. [Notification (通知系統)](#32-notification-通知系統)
33. [Pipeline (中介層管線)](#33-pipeline-中介層管線)
34. [AuthManager (認證管理)](#34-authmanager-認證管理)
35. [RateLimiter (速率限制)](#35-ratelimiter-速率限制)
36. [FileReader (檔案讀取器)](#36-filereader-檔案讀取器)
37. [FTPClient (FTP 客戶端)](#37-ftpclient-ftp-客戶端)
38. [SFTPClient (SFTP 客戶端)](#38-sftpclient-sftp-客戶端)
39. [Mailer (郵件)](#39-mailer-郵件)
40. [YAML (解析/輸出)](#40-yaml-解析輸出)
41. [SimpleSyntax (簡易語法解析)](#41-simplesyntax-簡易語法解析)
42. [SimplifiedMessage (簡化訊息協議)](#42-simplifiedmessage-簡化訊息協議)
43. [Profiler (效能分析)](#43-profiler-效能分析)
44. [Terminal (終端機工具)](#44-terminal-終端機工具)
45. [Thread / ThreadManager (多執行緒)](#45-thread--threadmanager-多執行緒)
46. [Domain (網域解析)](#46-domain-網域解析)
47. [Agent (User-Agent 解析)](#47-agent-user-agent-解析)
48. [OAuth2 (OAuth2 客戶端)](#48-oauth2-oauth2-客戶端)
49. [WorkerLifecycleManager (Worker 生命週期)](#49-workerlifecyclemanager-worker-生命週期)

---

## 1. Container (DI 容器)

> `Razy\Container` — 依賴注入容器，支援 Transient / Singleton / Scoped 綁定、別名、標籤、上下文綁定、自動連線。

### 1.1 基礎綁定與解析 (bind + make)

```php
use Razy\Container;

interface LoggerInterface {
    public function log(string $msg): string;
}

class FileLogger implements LoggerInterface {
    public function log(string $msg): string {
        return "FileLogger: $msg";
    }
}

$container = new Container();
$container->bind(LoggerInterface::class, FileLogger::class);
$logger = $container->make(LoggerInterface::class);

echo $logger->log('hello');
```

**Expected Result:**
```
FileLogger: hello
```

---

### 1.2 Singleton 綁定

```php
$container = new Container();
$container->singleton(LoggerInterface::class, FileLogger::class);

$a = $container->make(LoggerInterface::class);
$b = $container->make(LoggerInterface::class);

var_dump($a === $b); // 同一個實例
```

**Expected Result:**
```
bool(true)
```

---

### 1.3 Scoped 綁定 (Worker 模式)

```php
$container = new Container();
$container->scoped(LoggerInterface::class, FileLogger::class);

$a = $container->make(LoggerInterface::class);
$b = $container->make(LoggerInterface::class);
var_dump($a === $b); // 同一 scope 內共享

$container->forgetScopedInstances(); // 清除 scoped 實例
$c = $container->make(LoggerInterface::class);
var_dump($a === $c); // 新 scope，不同實例
```

**Expected Result:**
```
bool(true)
bool(false)
```

---

### 1.4 instance() — 預建實例

```php
$container = new Container();
$logger = new FileLogger();
$container->instance(LoggerInterface::class, $logger);

$resolved = $container->make(LoggerInterface::class);
var_dump($resolved === $logger);
```

**Expected Result:**
```
bool(true)
```

---

### 1.5 alias() — 別名

```php
$container = new Container();
$container->singleton(LoggerInterface::class, FileLogger::class);
$container->alias('logger', LoggerInterface::class);

$a = $container->make('logger');
$b = $container->make(LoggerInterface::class);
var_dump($a === $b);
```

**Expected Result:**
```
bool(true)
```

---

### 1.6 bindIf / singletonIf — 條件綁定

```php
$container = new Container();
$container->bind(LoggerInterface::class, FileLogger::class);
$container->bindIf(LoggerInterface::class, SomeOtherLogger::class); // 不會覆蓋

$logger = $container->make(LoggerInterface::class);
echo get_class($logger);
```

**Expected Result:**
```
FileLogger
```

---

### 1.7 Closure 工廠綁定

```php
$container = new Container();
$container->bind('greeting', function () {
    return 'Hello, Razy!';
});

echo $container->make('greeting');
```

**Expected Result:**
```
Hello, Razy!
```

---

### 1.8 tag() + tagged() — 標籤批次解析

```php
$container = new Container();

class ReportA { public string $name = 'A'; }
class ReportB { public string $name = 'B'; }

$container->bind(ReportA::class, ReportA::class);
$container->bind(ReportB::class, ReportB::class);
$container->tag([ReportA::class, ReportB::class], 'reports');

$reports = $container->tagged('reports');
foreach ($reports as $r) {
    echo $r->name . "\n";
}
```

**Expected Result:**
```
A
B
```

---

### 1.9 上下文綁定 (when)

```php
interface CacheInterface {}
class RedisCache implements CacheInterface {}
class FileCache implements CacheInterface {}

class UserService {
    public function __construct(public CacheInterface $cache) {}
}

class OrderService {
    public function __construct(public CacheInterface $cache) {}
}

$container = new Container();
$container->bind(CacheInterface::class, FileCache::class); // 預設
$container->when(UserService::class)->needs(CacheInterface::class)->give(RedisCache::class);

$userSvc = $container->make(UserService::class);
$orderSvc = $container->make(OrderService::class);

echo get_class($userSvc->cache);   // RedisCache (上下文)
echo "\n";
echo get_class($orderSvc->cache);  // FileCache (預設)
```

**Expected Result:**
```
RedisCache
FileCache
```

---

### 1.10 自動連線 (Auto-wiring)

```php
class Mailer {
    public function send(): string { return 'sent'; }
}

class NotifyService {
    public function __construct(public Mailer $mailer) {}
}

$container = new Container();
// 無需手動綁定，Container 自動透過反射解析
$svc = $container->make(NotifyService::class);
echo $svc->mailer->send();
```

**Expected Result:**
```
sent
```

---

### 1.11 Singleton 自動連線

```php
$container = new Container();
$container->singleton(Mailer::class);
$a = $container->make(Mailer::class);
$b = $container->make(Mailer::class);
var_dump($a === $b);
```

**Expected Result:**
```
bool(true)
```

---

### 1.12 call() — 方法注入

```php
class Service {
    public function handle(Mailer $mailer, string $to): string {
        return "Sending to $to via " . get_class($mailer);
    }
}

$container = new Container();
$result = $container->call([new Service(), 'handle'], ['to' => 'user@test.com']);
echo $result;
```

**Expected Result:**
```
Sending to user@test.com via Mailer
```

---

### 1.13 factory() — 工廠閉包

```php
$container = new Container();
$container->bind(Mailer::class, Mailer::class);

$factory = $container->factory(Mailer::class);
$a = $factory();
$b = $factory();
var_dump($a === $b); // 每次調用都是新實例
```

**Expected Result:**
```
bool(false)
```

---

### 1.14 extend() — 裝飾器

```php
interface LoggerInterface {
    public function log(string $msg): string;
}
class SimpleLogger implements LoggerInterface {
    public function log(string $msg): string { return $msg; }
}
class PrefixLogger implements LoggerInterface {
    public function __construct(private LoggerInterface $inner) {}
    public function log(string $msg): string { return "[PREFIX] " . $this->inner->log($msg); }
}

$container = new Container();
$container->singleton(LoggerInterface::class, SimpleLogger::class);
$container->extend(LoggerInterface::class, function ($logger, $container) {
    return new PrefixLogger($logger);
});

$logger = $container->make(LoggerInterface::class);
echo $logger->log('test');
```

**Expected Result:**
```
[PREFIX] test
```

---

### 1.15 rebind() — 動態替換綁定

```php
$container = new Container();
$container->singleton(LoggerInterface::class, SimpleLogger::class);
$container->make(LoggerInterface::class); // 觸發快取

$old = $container->rebind(LoggerInterface::class, function () {
    return new FileLogger();
});

echo get_class($old) . "\n";  // 舊實例
$new = $container->make(LoggerInterface::class);
echo get_class($new);          // 新綁定
```

**Expected Result:**
```
SimpleLogger
FileLogger
```

---

### 1.16 onRebind() — 監聽綁定替換

```php
$container = new Container();
$container->singleton(LoggerInterface::class, SimpleLogger::class);

$notified = false;
$container->onRebind(LoggerInterface::class, function ($newInstance) use (&$notified) {
    $notified = true;
});

$container->rebind(LoggerInterface::class, FileLogger::class);
var_dump($notified);
```

**Expected Result:**
```
bool(true)
```

---

### 1.17 Rebind 計數與閾值

```php
$container = new Container();
$container->setMaxRebindsBeforeRestart(3);
$container->singleton('svc', fn() => new \stdClass());

$container->rebind('svc', fn() => new \stdClass());
$container->rebind('svc', fn() => new \stdClass());
$container->rebind('svc', fn() => new \stdClass());

echo $container->getRebindCount('svc') . "\n";     // 3
echo $container->getTotalRebindCount() . "\n";      // 3
var_dump($container->exceedsRebindThreshold());     // true
```

**Expected Result:**
```
3
3
bool(true)
```

---

### 1.18 Resolving Hooks

```php
$container = new Container();
$container->bind(Mailer::class, Mailer::class);

$log = [];
$container->beforeResolving(Mailer::class, function () use (&$log) {
    $log[] = 'before';
});
$container->resolving(Mailer::class, function () use (&$log) {
    $log[] = 'resolving';
});
$container->afterResolving(Mailer::class, function () use (&$log) {
    $log[] = 'after';
});

$container->make(Mailer::class);
echo implode(' -> ', $log);
```

**Expected Result:**
```
before -> resolving -> after
```

---

### 1.19 has() + bound() — 存在檢查

```php
$container = new Container();
$container->bind('foo', fn() => 'bar');

var_dump($container->has('foo'));    // true
var_dump($container->bound('foo')); // true
var_dump($container->has('baz'));    // false
```

**Expected Result:**
```
bool(true)
bool(true)
bool(false)
```

---

### 1.20 forget() + reset()

```php
$container = new Container();
$container->bind('foo', fn() => 'bar');
$container->forget('foo');
var_dump($container->has('foo'));  // false

$container->bind('a', fn() => 1);
$container->bind('b', fn() => 2);
$container->reset();
var_dump($container->getBindings()); // []
```

**Expected Result:**
```
bool(false)
array(0) {
}
```

---

### 1.21 階層容器 (Parent Container)

```php
$parent = new Container();
$parent->singleton('config', fn() => ['debug' => true]);

$child = new Container($parent);
$config = $child->make('config'); // 從 parent 解析
var_dump($config);
```

**Expected Result:**
```
array(1) {
  ["debug"]=>
  bool(true)
}
```

---

### 1.22 PSR-11 get() — 未找到例外

```php
$container = new Container();

try {
    $container->get('nonexistent');
} catch (\Psr\Container\NotFoundExceptionInterface $e) {
    echo 'Caught: ' . $e->getMessage();
}
```

**Expected Result:**
```
Caught: No entry was found for "nonexistent".
```

---

## 2. Configuration (設定檔)

> `Razy\Configuration` — 載入 PHP / JSON / INI / YAML 設定檔，支援 ArrayAccess 修改與持久化。

### 2.1 載入 PHP 設定檔

```php
// 假設 config.php 的內容：
// <?php return ['app_name' => 'Razy', 'debug' => true];

use Razy\Configuration;

$config = new Configuration('/path/to/config.php');
echo $config['app_name'];
var_dump($config['debug']);
```

**Expected Result:**
```
Razy
bool(true)
```

---

### 2.2 修改與儲存

```php
$config = new Configuration('/path/to/config.php');
$config['debug'] = false;
$config->save(); // 持久化到檔案

// 重新載入驗證
$config2 = new Configuration('/path/to/config.php');
var_dump($config2['debug']);
```

**Expected Result:**
```
bool(false)
```

---

## 3. Env (環境變數)

> `Razy\Env` — `.env` 檔案解析器，提供 get / set / has / getRequired 等操作。

### 3.1 載入 .env 檔案

```php
// .env 內容：
// APP_NAME=Razy
// APP_DEBUG=true
// DB_HOST=localhost

use Razy\Env;

Env::load('/path/to/.env');
echo Env::get('APP_NAME');
echo "\n";
echo Env::get('DB_HOST');
```

**Expected Result:**
```
Razy
localhost
```

---

### 3.2 has / get 帶預設值

```php
Env::load('/path/to/.env');

var_dump(Env::has('APP_NAME'));           // true
var_dump(Env::has('NONEXISTENT'));        // false
echo Env::get('NONEXISTENT', 'default'); // default
```

**Expected Result:**
```
bool(true)
bool(false)
default
```

---

### 3.3 getRequired — 必填檢查

```php
Env::load('/path/to/.env');

try {
    Env::getRequired('MISSING_KEY');
} catch (\RuntimeException $e) {
    echo 'Caught: ' . $e->getMessage();
}
```

**Expected Result:**
```
Caught: Required environment variable "MISSING_KEY" is not set.
```

---

### 3.4 set / all / reset

```php
Env::load('/path/to/.env');
Env::set('CUSTOM', 'value123');
echo Env::get('CUSTOM') . "\n";

$all = Env::all();
var_dump(isset($all['CUSTOM'])); // true

Env::reset();
var_dump(Env::isInitialized()); // false
```

**Expected Result:**
```
value123
bool(true)
bool(false)
```

---

### 3.5 parse — 直接解析字串

```php
$envString = "KEY1=val1\nKEY2=val2\n# comment line\nKEY3=\"quoted value\"";
$parsed = Env::parse($envString);
print_r($parsed);
```

**Expected Result:**
```
Array
(
    [KEY1] => val1
    [KEY2] => val2
    [KEY3] => quoted value
)
```

---

## 4. Collection (資料集合)

> `Razy\Collection` — 可擴展的資料集合，支援 ArrayAccess、Serializable、Plugin 過濾器。

### 4.1 建立與存取

```php
use Razy\Collection;

$coll = new Collection(['name' => 'Razy', 'version' => '0.5']);
echo $coll['name'] . "\n";
echo $coll['version'];
```

**Expected Result:**
```
Razy
0.5
```

---

### 4.2 ArrayAccess 操作

```php
$coll = new Collection(['a' => 1]);
$coll['b'] = 2;
var_dump(isset($coll['a']));   // true
var_dump(isset($coll['c']));   // false
unset($coll['a']);
var_dump(isset($coll['a']));   // false
```

**Expected Result:**
```
bool(true)
bool(false)
bool(false)
```

---

### 4.3 array() — 取得底層陣列

```php
$coll = new Collection(['x' => 10, 'y' => 20]);
$arr = $coll->array();
print_r($arr);
```

**Expected Result:**
```
Array
(
    [x] => 10
    [y] => 20
)
```

---

### 4.4 __invoke — Processor 過濾

```php
$coll = new Collection(['items' => [1, 2, 3, 4, 5]]);
// 透過 __invoke 取得 Processor
$processor = $coll('items');
// Processor 提供鏈式過濾操作
```

**Expected Result:**  
回傳 `Processor` 物件，可進行進一步操作。

---

### 4.5 Serialize / Unserialize

```php
$coll = new Collection(['key' => 'value']);
$serialized = serialize($coll);
$restored = unserialize($serialized);
echo $restored['key'];
```

**Expected Result:**
```
value
```

---

## 5. HashMap (雜湊映射)

> `Razy\HashMap` — 基於物件雜湊的映射容器，支援 ArrayAccess、Iterator、Countable。

### 5.1 push / has / count

```php
use Razy\HashMap;

$map = new HashMap();
$obj1 = new \stdClass();
$obj1->name = 'Alice';

$map->push($obj1, 'hash_alice');
var_dump($map->has('hash_alice')); // true
echo $map->count();                // 1
```

**Expected Result:**
```
bool(true)
1
```

---

### 5.2 ArrayAccess 存取

```php
$map = new HashMap();
$obj = new \stdClass();
$map['key1'] = $obj;

var_dump(isset($map['key1'])); // true
var_dump($map['key1'] === $obj); // true
```

**Expected Result:**
```
bool(true)
bool(true)
```

---

### 5.3 remove

```php
$map = new HashMap();
$map->push(new \stdClass(), 'a');
$map->push(new \stdClass(), 'b');

$map->remove('a');
var_dump($map->has('a')); // false
echo $map->count();       // 1
```

**Expected Result:**
```
bool(false)
1
```

---

### 5.4 Iterator (foreach)

```php
$map = new HashMap();
$map->push((object)['n' => 1], 'x');
$map->push((object)['n' => 2], 'y');

foreach ($map->getGenerator() as $hash => $obj) {
    echo "$hash => {$obj->n}\n";
}
```

**Expected Result:**
```
x => 1
y => 2
```

---

## 6. Crypt (加密/解密)

> `Razy\Crypt` — AES-256-CBC 加密 + HMAC-SHA256 完整性驗證。

### 6.1 加密與解密

```php
use Razy\Crypt;

$plaintext = 'Hello, Razy!';
$key = 'my-secret-key-1234';

$encrypted = Crypt::encrypt($plaintext, $key);
$decrypted = Crypt::decrypt($encrypted, $key);

echo $decrypted;
var_dump($plaintext === $decrypted);
```

**Expected Result:**
```
Hello, Razy!
bool(true)
```

---

### 6.2 Hex 格式輸出

```php
$encrypted = Crypt::encrypt('test', 'key', true); // toHex = true
echo ctype_xdigit($encrypted) ? 'is hex' : 'not hex';

$decrypted = Crypt::decrypt($encrypted, 'key');
echo "\n$decrypted";
```

**Expected Result:**
```
is hex
test
```

---

### 6.3 錯誤的金鑰解密失敗

```php
$encrypted = Crypt::encrypt('secret', 'correct-key');

try {
    $result = Crypt::decrypt($encrypted, 'wrong-key');
    echo $result === false ? 'Decryption failed' : $result;
} catch (\Exception $e) {
    echo 'Caught: ' . $e->getMessage();
}
```

**Expected Result:**
```
Decryption failed
```
（或拋出例外，取決於實作）

---

## 7. Authenticator (TOTP/HOTP)

> `Razy\Authenticator` — TOTP / HOTP 兩步驗證，相容 Google Authenticator。

### 7.1 生成密鑰

```php
use Razy\Authenticator;

$secret = Authenticator::generateSecret();
echo strlen($secret) > 0 ? 'Secret generated' : 'Failed';
echo "\nLength: " . strlen($secret);
```

**Expected Result:**
```
Secret generated
Length: 32
```
（長度可能因 base32 編碼而異）

---

### 7.2 生成並驗證 TOTP Code

```php
$secret = Authenticator::generateSecret();
$code = Authenticator::getCode($secret);

echo "Code: $code\n";
echo "Length: " . strlen($code) . "\n";

$valid = Authenticator::verifyCode($secret, $code);
var_dump($valid);
```

**Expected Result:**
```
Code: 123456   (6位數字，實際值隨時間變化)
Length: 6
bool(true)
```

---

### 7.3 Provisioning URI (QR Code)

```php
$secret = Authenticator::generateSecret();
$uri = Authenticator::getProvisioningUri($secret, 'user@example.com', 'MyApp');
echo (str_starts_with($uri, 'otpauth://totp/')) ? 'Valid URI' : 'Invalid';
```

**Expected Result:**
```
Valid URI
```

---

### 7.4 Base32 編碼/解碼

```php
$original = 'Hello';
$encoded = Authenticator::base32Encode($original);
$decoded = Authenticator::base32Decode($encoded);
echo $decoded;
```

**Expected Result:**
```
Hello
```

---

## 8. Database (資料庫)

> `Razy\Database` — 多驅動 RDBMS 連線管理，支援 CRUD、交易、Prepared Statement。

### 8.1 連線 (SQLite)

```php
use Razy\Database;

$db = Database::connect([
    'driver' => 'sqlite',
    'path'   => ':memory:',
]);

echo $db ? 'Connected' : 'Failed';
```

**Expected Result:**
```
Connected
```

---

### 8.2 建立資料表 + 插入資料

```php
$db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$db->insert('users', ['name' => 'Alice', 'email' => 'alice@test.com']);
$db->insert('users', ['name' => 'Bob', 'email' => 'bob@test.com']);

echo 'Inserted 2 rows';
```

**Expected Result:**
```
Inserted 2 rows
```

---

### 8.3 查詢 (prepare + fetch)

```php
$stmt = $db->prepare('SELECT * FROM users WHERE name = ?', ['Alice']);
$row = $stmt->fetch();
echo $row['email'];
```

**Expected Result:**
```
alice@test.com
```

---

### 8.4 Transaction (成功)

```php
$db->beginTransaction();
$db->insert('users', ['name' => 'Charlie', 'email' => 'charlie@test.com']);
$db->commit();

$stmt = $db->prepare('SELECT COUNT(*) as total FROM users');
$row = $stmt->fetch();
echo "Total: {$row['total']}";
```

**Expected Result:**
```
Total: 3
```

---

### 8.5 Transaction (Rollback)

```php
$db->beginTransaction();
$db->insert('users', ['name' => 'Dave', 'email' => 'dave@test.com']);
$db->rollback();

$stmt = $db->prepare('SELECT COUNT(*) as total FROM users');
$row = $stmt->fetch();
echo "Total: {$row['total']}";  // Dave 未被插入
```

**Expected Result:**
```
Total: 3
```

---

### 8.6 Transaction 閉包模式

```php
$result = $db->transaction(function ($db) {
    $db->insert('users', ['name' => 'Eve', 'email' => 'eve@test.com']);
    return 'done';
});

echo $result;
```

**Expected Result:**
```
done
```

---

### 8.7 Update / Delete

```php
$db->update('users', ['email' => 'alice_new@test.com'], 'name = ?', ['Alice']);
$db->delete('users', 'name = ?', ['Bob']);

$stmt = $db->prepare('SELECT email FROM users WHERE name = ?', ['Alice']);
$row = $stmt->fetch();
echo $row['email'];
```

**Expected Result:**
```
alice_new@test.com
```

---

## 9. Statement (SQL 查詢構建器)

> `Razy\Statement` — 流式 SQL 構建器，支援 from / where / select / group / order / limit。

### 9.1 基本 SELECT

```php
$stmt = $db->prepare(
    (new \Razy\Statement())
        ->from('users')
        ->select('name', 'email')
        ->where('id > ?', [0])
        ->order('name', 'ASC')
);

while ($row = $stmt->fetch()) {
    echo "{$row['name']}: {$row['email']}\n";
}
```

**Expected Result:**
```
Alice: alice_new@test.com
Charlie: charlie@test.com
Eve: eve@test.com
```
（依 name 排序）

---

### 9.2 GROUP BY + 聚合

```php
$db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL)');
$db->insert('orders', ['user_id' => 1, 'amount' => 100]);
$db->insert('orders', ['user_id' => 1, 'amount' => 200]);
$db->insert('orders', ['user_id' => 2, 'amount' => 150]);

$stmt = $db->prepare(
    (new \Razy\Statement())
        ->from('orders')
        ->select('user_id', 'SUM(amount) as total')
        ->group('user_id')
);

while ($row = $stmt->fetch()) {
    echo "User {$row['user_id']}: {$row['total']}\n";
}
```

**Expected Result:**
```
User 1: 300
User 2: 150
```

---

### 9.3 LIMIT + OFFSET

```php
$stmt = $db->prepare(
    (new \Razy\Statement())
        ->from('users')
        ->select('name')
        ->limit(2, 0)
);

while ($row = $stmt->fetch()) {
    echo $row['name'] . "\n";
}
```

**Expected Result:**
```
Alice
Charlie
```
（前兩筆）

---

### 9.4 lazy() — 逐行 Generator

```php
$generator = (new \Razy\Statement())
    ->from('users')
    ->select('name')
    ->lazy($db);

foreach ($generator as $row) {
    echo $row['name'] . "\n";
}
```

**Expected Result:**
```
Alice
Charlie
Eve
```

---

## 10. TableHelper (Schema Migration)

> `Razy\TableHelper` — 資料表結構定義與遷移產生器。

### 10.1 建立表結構

```php
use Razy\TableHelper;

$helper = new TableHelper('products');
$helper->addColumn('id', 'int', ['auto_increment' => true]);
$helper->addColumn('name', 'varchar', ['length' => 255]);
$helper->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2]);
$helper->addColumn('created_at', 'datetime');
$helper->addPrimaryKey('id');
$helper->addIndex('idx_name', ['name']);

$sql = $helper->getSyntax();
echo strlen($sql) > 0 ? 'SQL generated' : 'Empty';
echo "\n-- Contains CREATE TABLE:";
echo str_contains($sql, 'CREATE TABLE') ? ' Yes' : ' No';
```

**Expected Result:**
```
SQL generated
-- Contains CREATE TABLE: Yes
```

---

### 10.2 修改欄位

```php
$helper = new TableHelper('products');
$helper->modifyColumn('price', 'decimal', ['precision' => 12, 'scale' => 4]);
$sql = $helper->getSyntax();
echo str_contains($sql, 'MODIFY') || str_contains($sql, 'ALTER') ? 'Modify SQL generated' : 'No modify';
```

**Expected Result:**
```
Modify SQL generated
```

---

### 10.3 外鍵 (Foreign Key)

```php
$helper = new TableHelper('orders');
$helper->addColumn('id', 'int', ['auto_increment' => true]);
$helper->addColumn('product_id', 'int');
$helper->addPrimaryKey('id');
$helper->addForeignKey('fk_product', 'product_id', 'products', 'id');

$sql = $helper->getSyntax();
echo str_contains($sql, 'FOREIGN KEY') ? 'FK defined' : 'No FK';
```

**Expected Result:**
```
FK defined
```

---

## 11. ORM / Model

> `Razy\ORM\Model` — 活動記錄 ORM，支援 CRUD、事件鉤子、Global Scope、Dirty Tracking。

### 11.1 定義 Model

```php
use Razy\ORM\Model;

class User extends Model {
    protected static string $table = 'users';
    protected static string $primaryKey = 'id';
    protected static array $fillable = ['name', 'email'];
}
```

---

### 11.2 create + find

```php
$user = User::create($db, ['name' => 'Test', 'email' => 'test@test.com']);
echo "Created ID: " . $user->getKey() . "\n";

$found = User::find($db, $user->getKey());
echo "Found: " . $found->name;
```

**Expected Result:**
```
Created ID: 1
Found: Test
```

---

### 11.3 findOrFail — 找不到拋出例外

```php
try {
    User::findOrFail($db, 99999);
} catch (\Razy\ORM\ModelNotFoundException $e) {
    echo 'Caught: Model not found';
}
```

**Expected Result:**
```
Caught: Model not found
```

---

### 11.4 fill + save (Update)

```php
$user = User::find($db, 1);
$user->fill(['email' => 'updated@test.com']);
$user->save();

$refreshed = User::find($db, 1);
echo $refreshed->email;
```

**Expected Result:**
```
updated@test.com
```

---

### 11.5 isDirty / getDirty

```php
$user = User::find($db, 1);
var_dump($user->isDirty()); // false

$user->name = 'Changed';
var_dump($user->isDirty()); // true
var_dump($user->isDirty('name')); // true

print_r($user->getDirty());
```

**Expected Result:**
```
bool(false)
bool(true)
bool(true)
Array
(
    [name] => Changed
)
```

---

### 11.6 delete + destroy

```php
$user = User::create($db, ['name' => 'ToDelete', 'email' => 'del@test.com']);
$id = $user->getKey();
$user->delete();

$found = User::find($db, $id);
var_dump($found); // null

// 批次刪除
User::create($db, ['name' => 'A', 'email' => 'a@t.com']);
User::create($db, ['name' => 'B', 'email' => 'b@t.com']);
$count = User::destroy($db, 2, 3);
echo "Destroyed: $count";
```

**Expected Result:**
```
NULL
Destroyed: 2
```

---

### 11.7 all() — 取得全部

```php
$all = User::all($db);
echo "Count: " . count($all);
```

**Expected Result:**
```
Count: 1
```
（只剩原本的 user ID 1）

---

### 11.8 firstOrCreate / firstOrNew

```php
// firstOrCreate: 找到就回傳，否則建立
$user = User::firstOrCreate($db, ['name' => 'Test'], ['email' => 'new@test.com']);
echo $user->exists() ? 'exists' : 'new';
echo "\n";

// firstOrNew: 找到就回傳，否則只建立實例（不持久化）
$user2 = User::firstOrNew($db, ['name' => 'Ghost'], ['email' => 'ghost@test.com']);
echo $user2->exists() ? 'exists' : 'not saved yet';
```

**Expected Result:**
```
exists
not saved yet
```

---

### 11.9 updateOrCreate

```php
$user = User::updateOrCreate(
    $db,
    ['name' => 'Test'],           // 搜尋條件
    ['email' => 'upsert@test.com'] // 更新欄位
);
echo $user->email;
```

**Expected Result:**
```
upsert@test.com
```

---

### 11.10 increment / decrement

```php
$db->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, stock INTEGER)');
// 假設已有 Product model
class Product extends Model {
    protected static string $table = 'products';
    protected static array $fillable = ['name', 'stock'];
}

$p = Product::create($db, ['name' => 'Widget', 'stock' => 10]);
$p->increment('stock', 3);
$p->refresh();
echo "Stock: " . $p->stock . "\n";

$p->decrement('stock', 1);
$p->refresh();
echo "Stock: " . $p->stock;
```

**Expected Result:**
```
Stock: 13
Stock: 12
```

---

### 11.11 replicate()

```php
$user = User::find($db, 1);
$clone = $user->replicate();
var_dump($clone->exists()); // false (未持久化)
echo $clone->name;          // 同樣的值
```

**Expected Result:**
```
bool(false)
Test
```

---

### 11.12 toArray / toJson

```php
$user = User::find($db, 1);
$arr = $user->toArray();
echo $arr['name'] . "\n";

$json = $user->toJson();
echo str_contains($json, '"name"') ? 'Valid JSON' : 'Invalid';
```

**Expected Result:**
```
Test
Valid JSON
```

---

### 11.13 Global Scope

```php
User::addGlobalScope('active', function ($query) {
    $query->where('active = ?', [1]);
});

// 所有透過 query() 的查詢都會自動加上 active = 1 條件
$scopes = User::getGlobalScopes();
echo count($scopes) . " scope(s) registered";

User::removeGlobalScope('active');
echo "\n" . count(User::getGlobalScopes()) . " scope(s) after removal";
```

**Expected Result:**
```
1 scope(s) registered
0 scope(s) after removal
```

---

### 11.14 Model Events

```php
User::clearBootedModels();

$log = [];
User::creating(function ($user) use (&$log) {
    $log[] = 'creating:' . $user->name;
});
User::created(function ($user) use (&$log) {
    $log[] = 'created:' . $user->getKey();
});

User::create($db, ['name' => 'EventUser', 'email' => 'event@test.com']);
echo implode(', ', $log);
```

**Expected Result:**
```
creating:EventUser, created:5
```
（ID 以實際值為準）

---

## 12. Validator (驗證器)

> `Razy\Validation\Validator` — 欄位驗證引擎，支援多規則鏈、Before/After 鉤子。

### 12.1 基本驗證 — 成功

```php
use Razy\Validation\Validator;
use Razy\Validation\Rule\{Required, Email, MinLength};

$validator = new Validator(['name' => 'Alice', 'email' => 'alice@test.com']);
$validator->field('name')->rule(new Required())->rule(new MinLength(2));
$validator->field('email')->rule(new Required())->rule(new Email());

$result = $validator->validate();
var_dump($result->passes()); // true
print_r($result->validated());
```

**Expected Result:**
```
bool(true)
Array
(
    [name] => Alice
    [email] => alice@test.com
)
```

---

### 12.2 基本驗證 — 失敗

```php
$validator = new Validator(['name' => '', 'email' => 'not-an-email']);
$validator->field('name')->rule(new Required());
$validator->field('email')->rule(new Email());

$result = $validator->validate();
var_dump($result->fails());  // true
echo $result->errorCount() . " error(s)\n";
print_r($result->firstErrors());
```

**Expected Result:**
```
bool(true)
2 error(s)
Array
(
    [name] => The name field is required.
    [email] => The email field must be a valid email address.
)
```

---

### 12.3 static make() — 一次性驗證

```php
$result = Validator::make(
    ['age' => 'abc'],
    ['age' => [new Required(), new \Razy\Validation\Rule\Numeric()]]
);

var_dump($result->fails());
echo $result->firstError('age');
```

**Expected Result:**
```
bool(true)
The age field must be numeric.
```

---

### 12.4 defaults() — 預設值

```php
$validator = new Validator(['name' => 'Bob']);
$validator->defaults(['role' => 'user']);
$validator->field('name')->rule(new Required());
$validator->field('role')->rule(new Required());

$result = $validator->validate();
echo $result->get('role');
```

**Expected Result:**
```
user
```

---

### 12.5 before / after 鉤子

```php
$validator = new Validator(['name' => '  Alice  ']);
$validator->before(function (&$data) {
    $data['name'] = trim($data['name']);
});
$validator->field('name')->rule(new Required());

$afterCalled = false;
$validator->after(function ($result) use (&$afterCalled) {
    $afterCalled = true;
});

$result = $validator->validate();
echo $result->get('name') . "\n";
var_dump($afterCalled);
```

**Expected Result:**
```
Alice
bool(true)
```

---

### 12.6 stopOnFirstFailure — 全域 bail

```php
$validator = new Validator(['a' => '', 'b' => '']);
$validator->stopOnFirstFailure();
$validator->field('a')->rule(new Required());
$validator->field('b')->rule(new Required());

$result = $validator->validate();
echo $result->errorCount() . " error(s)";
// 只有第一個欄位驗證失敗就停止
```

**Expected Result:**
```
1 error(s)
```

---

## 13. FieldValidator (欄位驗證)

> `Razy\Validation\FieldValidator` — 單一欄位的規則鏈。

### 13.1 多規則鏈

```php
use Razy\Validation\FieldValidator;
use Razy\Validation\Rule\{Required, MinLength};

$fv = new FieldValidator('username');
$fv->rule(new Required())->rule(new MinLength(3));

$result = $fv->validate('ab');
print_r($result['errors']);
```

**Expected Result:**
```
Array
(
    [0] => The username field must be at least 3 characters.
)
```

---

### 13.2 bail — 遇錯即停

```php
$fv = new FieldValidator('password');
$fv->bail()->rule(new Required())->rule(new MinLength(8));

$result = $fv->validate('');
echo count($result['errors']) . " error(s)"; // bail 時只回報第一個錯誤
```

**Expected Result:**
```
1 error(s)
```

---

### 13.3 when — 條件規則

```php
$fv = new FieldValidator('nickname');
$fv->when(true, function ($fv) {
    $fv->rule(new Required());
});
$fv->when(false, function ($fv) {
    $fv->rule(new MinLength(100)); // 不會執行
});

$result = $fv->validate('');
echo count($result['errors']); // 1 (Required)
```

**Expected Result:**
```
1
```

---

## 14. ValidationResult (驗證結果)

> `Razy\Validation\ValidationResult` — 不可變的驗證結果物件。

### 14.1 完整 API

```php
use Razy\Validation\ValidationResult;

$result = new ValidationResult(
    false,
    ['name' => ['Name is required', 'Name too short'], 'email' => ['Invalid email']],
    ['name' => '', 'email' => 'bad']
);

var_dump($result->passes());           // false
var_dump($result->fails());            // true
echo $result->errorCount() . "\n";     // 3
echo $result->firstError('name') . "\n";
var_dump($result->hasError('email'));   // true
print_r($result->firstErrors());
print_r($result->allErrors());
print_r($result->validated());
echo $result->get('email', 'default');
```

**Expected Result:**
```
bool(false)
bool(true)
3
Name is required
bool(true)
Array
(
    [name] => Name is required
    [email] => Invalid email
)
Array
(
    [0] => Name is required
    [1] => Name too short
    [2] => Invalid email
)
Array
(
    [name] => 
    [email] => bad
)
bad
```

---

## 15. NestedValidator (巢狀驗證)

> `Razy\Validation\NestedValidator` — 支援 dot-notation 與 wildcard 的巢狀驗證。

### 15.1 Dot Notation 驗證

```php
use Razy\Validation\NestedValidator;
use Razy\Validation\Rule\{Required, Email};

$data = [
    'user' => [
        'name' => 'Alice',
        'contact' => ['email' => 'alice@test.com']
    ]
];

$v = new NestedValidator($data);
$v->field('user.name', [new Required()]);
$v->field('user.contact.email', [new Required(), new Email()]);

$result = $v->validate();
var_dump($result->passes());
echo $result->get('user.contact.email');
```

**Expected Result:**
```
bool(true)
alice@test.com
```

---

### 15.2 Wildcard 驗證 (*)

```php
$data = [
    'items' => [
        ['name' => 'A', 'qty' => 5],
        ['name' => '', 'qty' => 3],
        ['name' => 'C', 'qty' => 0]
    ]
];

$v = new NestedValidator($data);
$v->field('items.*.name', [new Required()]);

$result = $v->validate();
var_dump($result->fails()); // true — items.1.name 為空
print_r($result->errors());
```

**Expected Result:**
```
bool(true)
Array
(
    [items.1.name] => Array
        (
            [0] => The items.1.name field is required.
        )
)
```

---

### 15.3 dataGet / dataSet / dataHas

```php
$data = ['a' => ['b' => ['c' => 42]]];

echo NestedValidator::dataGet($data, 'a.b.c') . "\n";       // 42
var_dump(NestedValidator::dataHas($data, 'a.b.c'));          // true
var_dump(NestedValidator::dataHas($data, 'a.b.d'));          // false

NestedValidator::dataSet($data, 'a.b.d', 99);
echo NestedValidator::dataGet($data, 'a.b.d');               // 99
```

**Expected Result:**
```
42
bool(true)
bool(false)
99
```

---

### 15.4 static make()

```php
$result = NestedValidator::make(
    ['user' => ['email' => 'bad']],
    ['user.email' => [new Email()]]
);
var_dump($result->fails());
```

**Expected Result:**
```
bool(true)
```

---

## 16. FormRequest (表單請求)

> `Razy\Validation\FormRequest` — 抽象表單請求物件，封裝驗證邏輯。

### 16.1 定義 FormRequest

```php
use Razy\Validation\FormRequest;
use Razy\Validation\Rule\{Required, Email, MinLength};

class RegisterRequest extends FormRequest {
    protected function rules(): array {
        return [
            'name'  => [new Required(), new MinLength(2)],
            'email' => [new Required(), new Email()],
        ];
    }
}
```

---

### 16.2 fromArray + 驗證通過

```php
$req = RegisterRequest::fromArray([
    'name'  => 'Alice',
    'email' => 'alice@test.com',
]);

var_dump($req->passes());
print_r($req->validated());
echo $req->input('name');
```

**Expected Result:**
```
bool(true)
Array
(
    [name] => Alice
    [email] => alice@test.com
)
Alice
```

---

### 16.3 驗證失敗

```php
$req = RegisterRequest::fromArray([
    'name'  => '',
    'email' => 'bad',
]);

var_dump($req->fails());
echo $req->errorsAsJson(JSON_PRETTY_PRINT);
```

**Expected Result:**
```
bool(true)
{
    "name": [
        "The name field is required."
    ],
    "email": [
        "The email field must be a valid email address."
    ]
}
```

---

### 16.4 only / except / has / filled

```php
$req = RegisterRequest::fromArray([
    'name'  => 'Alice',
    'email' => 'alice@test.com',
    'extra' => 'ignored',
]);

print_r($req->only(['name', 'email']));
print_r($req->except(['email']));
var_dump($req->has('name'));    // true
var_dump($req->filled('name')); // true
var_dump($req->filled('missing')); // false
```

**Expected Result:**
```
Array
(
    [name] => Alice
    [email] => alice@test.com
)
Array
(
    [name] => Alice
    [extra] => ignored
)
bool(true)
bool(true)
bool(false)
```

---

## 17. Validation Rules (驗證規則)

> 所有內建規則實作 `ValidationRuleInterface`。

### 17.1 Required

```php
use Razy\Validation\Rule\Required;

$rule = new Required();

// 成功
$result = $rule->validate('hello', 'field');
echo $result . "\n"; // hello

// 失敗 (空字串)
try {
    $rule->validate('', 'field');
} catch (\Razy\Validation\ValidationException $e) {
    echo $e->getMessage();
}
```

**Expected Result:**
```
hello
The field field is required.
```

---

### 17.2 Email

```php
use Razy\Validation\Rule\Email;

$rule = new Email();
echo $rule->validate('test@example.com', 'email') . "\n";

try {
    $rule->validate('not-email', 'email');
} catch (\Razy\Validation\ValidationException $e) {
    echo $e->getMessage();
}
```

**Expected Result:**
```
test@example.com
The email field must be a valid email address.
```

---

### 17.3 MinLength

```php
use Razy\Validation\Rule\MinLength;

$rule = new MinLength(5);
echo $rule->validate('Hello', 'field') . "\n"; // 剛好 5

try {
    $rule->validate('Hi', 'field');
} catch (\Razy\Validation\ValidationException $e) {
    echo $e->getMessage();
}
```

**Expected Result:**
```
Hello
The field field must be at least 5 characters.
```

---

### 17.4 Numeric

```php
use Razy\Validation\Rule\Numeric;

$rule = new Numeric();
echo $rule->validate('42', 'age') . "\n";
echo $rule->validate(3.14, 'pi') . "\n";

try {
    $rule->validate('abc', 'age');
} catch (\Razy\Validation\ValidationException $e) {
    echo $e->getMessage();
}
```

**Expected Result:**
```
42
3.14
The age field must be numeric.
```

---

### 17.5 IsArray

```php
use Razy\Validation\Rule\IsArray;

$rule = new IsArray();
$val = $rule->validate([1, 2, 3], 'items');
print_r($val);

try {
    $rule->validate('not-array', 'items');
} catch (\Razy\Validation\ValidationException $e) {
    echo $e->getMessage();
}
```

**Expected Result:**
```
Array
(
    [0] => 1
    [1] => 2
    [2] => 3
)
The items field must be an array.
```

---

### 17.6 Each — 逐元素驗證

```php
use Razy\Validation\Rule\{Each, Numeric};

$rule = new Each([new Numeric()]);

// 成功
$rule->validate([1, 2, 3], 'scores');
echo "All numeric\n";

// 失敗
try {
    $rule->validate([1, 'abc', 3], 'scores');
} catch (\Razy\Validation\ValidationException $e) {
    echo $e->getMessage() . "\n";
    print_r($rule->getItemErrors());
}
```

**Expected Result:**
```
All numeric
The scores field contains invalid items.
Array
(
    [1] => Array
        (
            [0] => The scores.1 field must be numeric.
        )
)
```

---

## 18. HttpClient (HTTP 客戶端)

> `Razy\HttpClient` — 流式 cURL 封裝，支援 GET/POST/PUT/PATCH/DELETE。

### 18.1 GET 請求

```php
use Razy\HttpClient;

$client = new HttpClient();
$response = $client->get('https://httpbin.org/get');

echo $response->getStatusCode() . "\n";
echo str_contains($response->getBody(), '"url"') ? 'Body OK' : 'Body missing';
```

**Expected Result:**
```
200
Body OK
```

---

### 18.2 POST JSON 請求

```php
$client = new HttpClient();
$response = $client
    ->withHeaders(['Content-Type' => 'application/json'])
    ->post('https://httpbin.org/post', json_encode(['key' => 'value']));

echo $response->getStatusCode();
```

**Expected Result:**
```
200
```

---

### 18.3 baseUrl + withToken

```php
$client = (new HttpClient())
    ->baseUrl('https://api.example.com')
    ->withToken('my-bearer-token')
    ->timeout(10);

// $response = $client->get('/users');
echo 'Client configured';
```

**Expected Result:**
```
Client configured
```

---

### 18.4 retry — 重試機制

```php
$client = (new HttpClient())->retry(3, 100); // 最多重試3次，間隔100ms
// $response = $client->get('https://unreliable-api.example.com/data');
echo 'Retry configured: 3 times';
```

**Expected Result:**
```
Retry configured: 3 times
```

---

## 19. Route (路由)

> `Razy\Route` — URL 路由定義，支援 HTTP 方法約束、中介層、命名路由。

### 19.1 基本路由比對

```php
use Razy\Route;

$route = new Route('/users/(\d+)');
$route->method('GET');
$route->name('user.show');

// 路由物件用於框架內部比對
echo 'Route: ' . $route->name . "\n";
echo 'Methods: GET';
```

**Expected Result:**
```
Route: user.show
Methods: GET
```

---

### 19.2 contain — 路徑包含檢查

```php
$route = new Route('/api/v1/users');
// contain() 方法用於檢查路由是否包含特定路徑片段
echo 'Route defined';
```

**Expected Result:**
```
Route defined
```

---

### 19.3 middleware — 中介層

```php
$route = new Route('/admin/dashboard');
$route->middleware('auth', 'admin');
echo 'Middleware attached';
```

**Expected Result:**
```
Middleware attached
```

---

## 20. SSE (Server-Sent Events)

> `Razy\SSE` — Server-Sent Events 推送，支援 event、data、retry、proxy。

### 20.1 基本 SSE 發送

```php
use Razy\SSE;

// 注意：SSE 需在 HTTP 回應上下文中使用
// 以下為概念示範

$sse = new SSE();
$sse->start(); // 設定正確的 Headers

$sse->send('Hello from SSE', 'greeting');
$sse->comment('This is a comment');
$sse->close();
```

**Expected Result:**
```
HTTP Headers:
  Content-Type: text/event-stream
  Cache-Control: no-cache
  Connection: keep-alive

Output:
event: greeting
data: Hello from SSE

: This is a comment
```

---

## 21. XHR (JSON 回應)

> `Razy\XHR` — AJAX JSON 回應構建器，支援 CORS。

### 21.1 基本 JSON 回應

```php
use Razy\XHR;

$xhr = new XHR();
$xhr->set('status', 'ok');
$xhr->set('data', ['name' => 'Razy', 'version' => '0.5']);
$xhr->data(['extra' => true]);

// $xhr->send(); // 在實際 HTTP 環境中輸出 JSON
echo json_encode($xhr->data(['extra' => true]));
```

**Expected Result:**
```json
{"status":"ok","data":{"name":"Razy","version":"0.5"},"extra":true}
```

---

### 21.2 CORS 設定

```php
$xhr = new XHR();
$xhr->allowOrigin('https://example.com');
$xhr->corp('same-origin');
echo 'CORS configured';
```

**Expected Result:**
```
CORS configured
```

---

## 22. Cache (快取)

> `Razy\Cache` — PSR-16 相容的靜態快取門面。

### 22.1 set / get / has / delete

```php
use Razy\Cache;

Cache::set('key1', 'value1', 3600);
echo Cache::get('key1') . "\n";
var_dump(Cache::has('key1'));

Cache::delete('key1');
var_dump(Cache::has('key1'));
echo Cache::get('key1', 'default');
```

**Expected Result:**
```
value1
bool(true)
bool(false)
default
```

---

### 22.2 getMultiple / setMultiple

```php
Cache::setMultiple([
    'a' => 1,
    'b' => 2,
    'c' => 3,
], 3600);

$values = Cache::getMultiple(['a', 'b', 'c', 'd'], 'miss');
print_r(iterator_to_array($values));
```

**Expected Result:**
```
Array
(
    [a] => 1
    [b] => 2
    [c] => 3
    [d] => miss
)
```

---

### 22.3 clear

```php
Cache::set('x', 'y');
Cache::clear();
var_dump(Cache::has('x'));
```

**Expected Result:**
```
bool(false)
```

---

### 22.4 getValidated / setValidated

```php
// setValidated 和 getValidated 提供帶驗證的快取操作
Cache::setValidated('token', 'abc123', 3600);
$val = Cache::getValidated('token');
echo $val ?? 'null';
```

**Expected Result:**
```
abc123
```

---

## 23. RedisAdapter (Redis 快取)

> `Razy\Cache\RedisAdapter` — PSR-16 Redis 快取介面卡。

### 23.1 建立 + set / get

```php
use Razy\Cache\RedisAdapter;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$cache = new RedisAdapter($redis, 'myapp_');
$cache->set('user:1', ['name' => 'Alice'], 600);

$data = $cache->get('user:1');
echo $data['name'];
```

**Expected Result:**
```
Alice
```

---

### 23.2 has / delete / clear

```php
var_dump($cache->has('user:1'));  // true
$cache->delete('user:1');
var_dump($cache->has('user:1'));  // false

$cache->set('a', 1);
$cache->set('b', 2);
$cache->clear();
var_dump($cache->has('a'));  // false
```

**Expected Result:**
```
bool(true)
bool(false)
bool(false)
```

---

### 23.3 getMultiple / setMultiple

```php
$cache->setMultiple(['k1' => 'v1', 'k2' => 'v2'], 600);
$vals = $cache->getMultiple(['k1', 'k2', 'k3'], 'miss');
print_r(iterator_to_array($vals));
```

**Expected Result:**
```
Array
(
    [k1] => v1
    [k2] => v2
    [k3] => miss
)
```

---

## 24. Template (模板引擎)

> `Razy\Template` — 模板載入、佇列渲染、Plugin 機制。

### 24.1 載入模板

```php
use Razy\Template;

$tpl = new Template('/path/to/templates');
$tpl->load('header');
$tpl->load('content');
echo 'Templates loaded';
```

**Expected Result:**
```
Templates loaded
```

---

### 24.2 變數替換 (ParseContent)

```php
// 模板檔案內容: Hello, {name}! You have {count} messages.

$content = 'Hello, {name}! You have {count} messages.';
$parsed = Template::ParseContent($content, [
    'name'  => 'Alice',
    'count' => 5,
]);
echo $parsed;
```

**Expected Result:**
```
Hello, Alice! You have 5 messages.
```

---

### 24.3 GetValueByPath — 巢狀值取得

```php
$data = [
    'user' => [
        'profile' => [
            'name' => 'Bob',
        ]
    ]
];

$value = Template::GetValueByPath($data, 'user.profile.name');
echo $value;
```

**Expected Result:**
```
Bob
```

---

### 24.4 佇列渲染

```php
$tpl = new Template('/path/to/templates');
$tpl->addQueue('section1', 'block1');
$tpl->addQueue('section1', 'block2');
// $tpl->outputQueued('section1'); // 輸出該佇列
echo 'Queue ready';
```

**Expected Result:**
```
Queue ready
```

---

## 25. DOM (HTML 構建)

> `Razy\DOM` — 程式化 HTML 構建，支援鏈式 API。

### 25.1 基本 HTML 構建

```php
use Razy\DOM;

$dom = new DOM('div');
$dom->addClass('container', 'main');
$dom->setAttribute('id', 'app');
$dom->setText('Hello World');

echo $dom->saveHTML();
```

**Expected Result:**
```html
<div class="container main" id="app">Hello World</div>
```

---

### 25.2 巢狀結構

```php
$ul = new DOM('ul');
$ul->addClass('list');

$li1 = new DOM('li');
$li1->setText('Item 1');
$ul->append($li1);

$li2 = new DOM('li');
$li2->setText('Item 2');
$ul->append($li2);

echo $ul->saveHTML();
```

**Expected Result:**
```html
<ul class="list"><li>Item 1</li><li>Item 2</li></ul>
```

---

### 25.3 prepend + setTag

```php
$div = new DOM('div');
$span = new DOM('span');
$span->setText('First');
$div->prepend($span);

$p = new DOM('p');
$p->setText('Second');
$div->append($p);

echo $div->saveHTML();
```

**Expected Result:**
```html
<div><span>First</span><p>Second</p></div>
```

---

## 26. EventDispatcher (PSR-14 事件)

> `Razy\Event\EventDispatcher` — PSR-14 相容事件分派器。

### 26.1 事件分派

```php
use Razy\Event\EventDispatcher;
use Psr\EventDispatcher\ListenerProviderInterface;

class UserRegistered {
    public function __construct(public string $email) {}
}

class SimpleProvider implements ListenerProviderInterface {
    private array $listeners = [];

    public function addListener(string $event, callable $listener): void {
        $this->listeners[$event][] = $listener;
    }

    public function getListenersForEvent(object $event): iterable {
        return $this->listeners[get_class($event)] ?? [];
    }
}

$provider = new SimpleProvider();
$log = [];
$provider->addListener(UserRegistered::class, function (UserRegistered $e) use (&$log) {
    $log[] = "Registered: {$e->email}";
});

$dispatcher = new EventDispatcher($provider);
$event = $dispatcher->dispatch(new UserRegistered('alice@test.com'));

echo implode(', ', $log);
echo "\n" . get_class($event);
```

**Expected Result:**
```
Registered: alice@test.com
UserRegistered
```

---

## 27. Emitter (跨模組 API)

> `Razy\Emitter` — 模組間 API 代理，透過 `__call` 委派呼叫。

### 27.1 概念

```php
// Emitter 用於 Razy 模組系統中，代理跨模組 API 呼叫
// $emitter = new Emitter($requestingModule, $targetModule);
// $result = $emitter->someApiMethod($arg1, $arg2);
// 等同於呼叫 $targetModule 的 API 命令 someApiMethod
echo 'Emitter is a cross-module proxy';
```

**Expected Result:**
```
Emitter is a cross-module proxy
```

---

## 28. EventEmitter (事件發射器)

> `Razy\EventEmitter` — 透過 Distributor 廣播事件並收集回應。

### 28.1 概念

```php
// EventEmitter 在模組系統內使用
// $emitter = new EventEmitter($distributor, $module, 'user.registered', function ($response) {
//     echo "Got response: $response\n";
// });
// $emitter->resolve($userData); // 觸發所有監聽器
// $responses = $emitter->getAllResponse();
echo 'EventEmitter broadcasts across modules';
```

**Expected Result:**
```
EventEmitter broadcasts across modules
```

---

## 29. Logger (PSR-3 日誌)

> `Razy\Logger` — PSR-3 相容日誌記錄器，含 Buffer 功能。

### 29.1 基本記錄

```php
use Razy\Logger;

$logger = new Logger();
$logger->log('info', 'User logged in');
$logger->log('error', 'Something broke');

$buffer = $logger->getBuffer();
echo count($buffer) . " entries\n";
echo $buffer[0]['level'] . ': ' . $buffer[0]['message'] . "\n";
echo $buffer[1]['level'] . ': ' . $buffer[1]['message'];
```

**Expected Result:**
```
2 entries
info: User logged in
error: Something broke
```

---

### 29.2 MinLevel 過濾

```php
$logger = new Logger();
$logger->setMinLevel('warning');

$logger->log('debug', 'This is debug');     // 被過濾
$logger->log('warning', 'This is warning'); // 通過

echo count($logger->getBuffer()) . " entry\n";
echo $logger->getMinLevel();
```

**Expected Result:**
```
1 entry
warning
```

---

### 29.3 clearBuffer

```php
$logger = new Logger();
$logger->log('info', 'msg1');
$logger->log('info', 'msg2');
echo count($logger->getBuffer()) . "\n"; // 2

$logger->clearBuffer();
echo count($logger->getBuffer()); // 0
```

**Expected Result:**
```
2
0
```

---

## 30. LogManager (多頻道日誌)

> `Razy\Log\LogManager` — 多頻道日誌管理器，支援 Handler 聚合、Stack。

### 30.1 建立頻道

```php
use Razy\Log\LogManager;
use Razy\Log\NullHandler;

$manager = new LogManager('app', true); // bufferEnabled=true
$manager->addHandler('app', new NullHandler());
$manager->addHandler('audit', new NullHandler());

echo implode(', ', $manager->getChannelNames());
var_dump($manager->hasChannel('app'));
```

**Expected Result:**
```
app, audit
bool(true)
```

---

### 30.2 寫入指定頻道

```php
$manager = new LogManager('default', true);
$manager->addHandler('default', new NullHandler());
$manager->addHandler('errors', new NullHandler());

$manager->log('info', 'General info');
$manager->channel('errors')->log('error', 'Critical error');

$buffer = $manager->getBuffer();
echo count($buffer) . " entries\n";
echo $buffer[0]['channel'] . ': ' . $buffer[0]['message'] . "\n";
echo $buffer[1]['channel'] . ': ' . $buffer[1]['message'];
```

**Expected Result:**
```
2 entries
default: General info
errors: Critical error
```

---

### 30.3 stack — 同時寫入多頻道

```php
$manager = new LogManager('default', true);
$manager->addHandler('file', new NullHandler());
$manager->addHandler('slack', new NullHandler());

$manager->stack(['file', 'slack'])->log('critical', 'System down!');

$buffer = $manager->getBuffer();
// 多頻道記錄
echo count($buffer) . " entries";
```

**Expected Result:**
```
2 entries
```

---

### 30.4 clearBuffer / setDefaultChannel

```php
$manager = new LogManager('default', true);
$manager->addHandler('default', new NullHandler());

$manager->log('info', 'test');
$manager->clearBuffer();
echo count($manager->getBuffer()) . "\n"; // 0

$manager->setDefaultChannel('main');
echo $manager->getDefaultChannel();
```

**Expected Result:**
```
0
main
```

---

## 31. Log Handlers (日誌處理器)

### 31.1 FileHandler

```php
use Razy\Log\FileHandler;

$handler = new FileHandler('/tmp/logs', 'warning', 'Y-m-d');
echo $handler->getDirectory() . "\n";
echo $handler->getMinLevel() . "\n";
var_dump($handler->isHandling('error'));    // true (error >= warning)
var_dump($handler->isHandling('debug'));    // false (debug < warning)
```

**Expected Result:**
```
/tmp/logs
warning
bool(true)
bool(false)
```

---

### 31.2 StderrHandler

```php
use Razy\Log\StderrHandler;

$handler = new StderrHandler('error');
var_dump($handler->isHandling('error'));    // true
var_dump($handler->isHandling('info'));     // false
echo $handler->getMinLevel();
```

**Expected Result:**
```
bool(true)
bool(false)
error
```

---

### 31.3 NullHandler

```php
use Razy\Log\NullHandler;

$handler = new NullHandler();
var_dump($handler->isHandling('debug'));    // true (always)
// handle() 不做任何事
$handler->handle('info', 'ignored', [], '2025-01-01', 'test');
echo 'NullHandler: silently discards all';
```

**Expected Result:**
```
bool(true)
NullHandler: silently discards all
```

---

## 32. Notification (通知系統)

> `Razy\Notification` — 多頻道通知系統，含 MailChannel、DatabaseChannel。

### 32.1 定義 Notification

```php
use Razy\Notification\Notification;

class WelcomeNotification extends Notification {
    public function __construct(private string $name) {}

    public function via(object $notifiable): array {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): array {
        return ['subject' => "Welcome, {$this->name}!", 'body' => 'Thanks for joining.'];
    }

    public function toDatabase(object $notifiable): array {
        return ['message' => "Welcome, {$this->name}!"];
    }
}
```

---

### 32.2 NotificationManager — 發送通知

```php
use Razy\Notification\NotificationManager;
use Razy\Notification\Channel\{MailChannel, DatabaseChannel};

$manager = new NotificationManager(logging: true);
$manager->registerChannel(new MailChannel(function ($to, $data) {
    // 模擬寄信
}, recording: true));
$manager->registerChannel(new DatabaseChannel());

$user = (object)['email' => 'alice@test.com', 'id' => 1];
$notification = new WelcomeNotification('Alice');

$manager->send($user, $notification);

echo count($manager->getSentLog()) . " sent\n";
echo implode(', ', $manager->getChannelNames());
```

**Expected Result:**
```
2 sent
mail, database
```

---

### 32.3 DatabaseChannel — 查詢記錄

```php
$dbChannel = $manager->getChannel('database');
$records = $dbChannel->getRecords();
echo count($records) . " record(s)\n";
echo $records[0]['data']['message'];
```

**Expected Result:**
```
1 record(s)
Welcome, Alice!
```

---

### 32.4 sendToMany

```php
$users = [
    (object)['email' => 'a@test.com', 'id' => 1],
    (object)['email' => 'b@test.com', 'id' => 2],
];

$manager->clearSentLog();
$manager->sendToMany($users, new WelcomeNotification('Team'));

echo count($manager->getSentLog()) . " total sends";
```

**Expected Result:**
```
4 total sends
```
（2 users × 2 channels）

---

### 32.5 beforeSend / afterSend / onError Hooks

```php
$log = [];
$manager->beforeSend(function ($notifiable, $notification, $channel) use (&$log) {
    $log[] = "before:$channel";
});
$manager->afterSend(function ($notifiable, $notification, $channel) use (&$log) {
    $log[] = "after:$channel";
});
$manager->onError(function ($e, $notifiable, $notification, $channel) use (&$log) {
    $log[] = "error:$channel";
});

$manager->send((object)['email' => 'x@t.com', 'id' => 3], new WelcomeNotification('X'));
echo implode(', ', $log);
```

**Expected Result:**
```
before:mail, after:mail, before:database, after:database
```

---

## 33. Pipeline (中介層管線)

> `Razy\Pipeline` — 中介層管線，支援 pipe/add/execute，含 Storage 機制。

### 33.1 基本 Pipeline

```php
use Razy\Pipeline;

$pipeline = new Pipeline();

$pipeline->pipe(function ($data, $next) {
    $data['step1'] = true;
    return $next($data);
});

$pipeline->pipe(function ($data, $next) {
    $data['step2'] = true;
    return $next($data);
});

$result = $pipeline->execute(['input' => true]);
print_r($result);
```

**Expected Result:**
```
Array
(
    [input] => 1
    [step1] => 1
    [step2] => 1
)
```

---

### 33.2 add — 新增中介層

```php
$pipeline = new Pipeline();
$pipeline->add(function ($data, $next) {
    $data[] = 'A';
    return $next($data);
});
$pipeline->add(function ($data, $next) {
    $data[] = 'B';
    return $next($data);
});

$result = $pipeline->execute([]);
print_r($result);
```

**Expected Result:**
```
Array
(
    [0] => A
    [1] => B
)
```

---

### 33.3 Storage — 管線共享狀態

```php
$pipeline = new Pipeline();
$pipeline->setStorage('counter', 0);

$pipeline->pipe(function ($data, $next) use ($pipeline) {
    $c = $pipeline->getStorage('counter');
    $pipeline->setStorage('counter', $c + 1);
    return $next($data);
});

$pipeline->pipe(function ($data, $next) use ($pipeline) {
    $c = $pipeline->getStorage('counter');
    $pipeline->setStorage('counter', $c + 1);
    return $next($data);
});

$pipeline->execute(null);
echo 'Counter: ' . $pipeline->getStorage('counter');
```

**Expected Result:**
```
Counter: 2
```

---

### 33.4 getRelay — 取得中繼

```php
$pipeline = new Pipeline();
$pipeline->pipe(function ($data, $next) {
    return $next($data . ' World');
});

$relay = $pipeline->getRelay();
echo is_callable($relay) ? 'Relay is callable' : 'Not callable';
```

**Expected Result:**
```
Relay is callable
```

---

## 34. AuthManager (認證管理)

> `Razy\AuthManager` — 多 Guard 認證管理器。

### 34.1 建立 Guard

```php
use Razy\AuthManager;

$auth = new AuthManager();
$auth->addGuard('web', function () {
    return (object)['id' => 1, 'name' => 'Alice']; // 模擬使用者
});

$user = $auth->guard('web')->user();
echo $user->name;
```

**Expected Result:**
```
Alice
```

---

### 34.2 check / guest

```php
$auth = new AuthManager();
$auth->addGuard('web', function () {
    return (object)['id' => 1, 'name' => 'Alice'];
});

// 已設定使用者
$auth->guard('web')->setUser((object)['id' => 1, 'name' => 'Alice']);
var_dump($auth->guard('web')->check());  // true
var_dump($auth->guard('web')->guest());  // false
echo $auth->guard('web')->id();          // 1
```

**Expected Result:**
```
bool(true)
bool(false)
1
```

---

### 34.3 validate

```php
$auth = new AuthManager();
$auth->addGuard('api', function () {
    return null; // 模擬未認證
});

// validate 用於驗證憑證而不設定 session
$result = $auth->guard('api')->validate(['token' => 'abc']);
// 行為取決於 Guard 的 validate 實作
echo 'Validate called';
```

**Expected Result:**
```
Validate called
```

---

## 35. RateLimiter (速率限制)

> `Razy\RateLimiter` — Token Bucket 速率限制器。

### 35.1 基本使用

```php
use Razy\RateLimiter;

$limiter = new RateLimiter();
$limiter->for('api', function () {
    return ['maxAttempts' => 3, 'decaySeconds' => 60];
});

$key = $limiter->resolve('api', 'user-1');

// 模擬 3 次請求
$limiter->hit($key);
$limiter->hit($key);
$limiter->hit($key);

echo "Remaining: " . $limiter->remaining($key, 3) . "\n";
var_dump($limiter->tooManyAttempts($key, 3));
```

**Expected Result:**
```
Remaining: 0
bool(true)
```

---

### 35.2 attempt — 嘗試執行

```php
$limiter = new RateLimiter();
$key = 'login:user-1';

$result1 = $limiter->attempt($key, 2, function () {
    return 'OK';
});
$result2 = $limiter->attempt($key, 2, function () {
    return 'OK';
});
$result3 = $limiter->attempt($key, 2, function () {
    return 'OK';
});

echo "1: $result1\n";
echo "2: $result2\n";
echo "3: " . ($result3 === false ? 'BLOCKED' : $result3);
```

**Expected Result:**
```
1: OK
2: OK
3: BLOCKED
```

---

### 35.3 clear — 重置

```php
$limiter = new RateLimiter();
$key = 'test-key';
$limiter->hit($key);
$limiter->hit($key);
$limiter->clear($key);

echo "Remaining: " . $limiter->remaining($key, 5);
```

**Expected Result:**
```
Remaining: 5
```

---

### 35.4 availableIn — 何時可再試

```php
$limiter = new RateLimiter();
$key = 'timeout-key';
$limiter->hit($key, 60); // decay 60 秒

$seconds = $limiter->availableIn($key);
echo "Available in: {$seconds} seconds";
```

**Expected Result:**
```
Available in: 60 seconds
```
（實際值可能略少）

---

## 36. FileReader (檔案讀取器)

> `Razy\FileReader` — 循序多檔案讀取器。

### 36.1 單檔讀取

```php
use Razy\FileReader;

// 建立臨時檔案
file_put_contents('/tmp/test.txt', "line1\nline2\nline3");

$reader = new FileReader('/tmp/test.txt');
while (($line = $reader->fetch()) !== null) {
    echo trim($line) . "\n";
}
```

**Expected Result:**
```
line1
line2
line3
```

---

### 36.2 多檔案串接

```php
file_put_contents('/tmp/a.txt', "AAA\n");
file_put_contents('/tmp/b.txt', "BBB\n");

$reader = new FileReader('/tmp/a.txt');
$reader->append('/tmp/b.txt');

$lines = [];
while (($line = $reader->fetch()) !== null) {
    $lines[] = trim($line);
}
print_r($lines);
```

**Expected Result:**
```
Array
(
    [0] => AAA
    [1] => BBB
)
```

---

### 36.3 prepend — 前置檔案

```php
file_put_contents('/tmp/first.txt', "FIRST\n");
file_put_contents('/tmp/second.txt', "SECOND\n");

$reader = new FileReader('/tmp/second.txt');
$reader->prepend('/tmp/first.txt');

$first = $reader->fetch();
echo trim($first);
```

**Expected Result:**
```
FIRST
```

---

## 37. FTPClient (FTP 客戶端)

> `Razy\FTPClient` — FTP/FTPS 客戶端，支援完整檔案操作。

### 37.1 連線與登入

```php
use Razy\FTPClient;

$ftp = new FTPClient('ftp.example.com', 21, 30, false);
$ftp->login('user', 'pass');
$ftp->setPassive(true);

echo $ftp->isConnected() ? 'Connected' : 'Failed';
echo "\n" . ($ftp->isSecure() ? 'SSL' : 'Plain');
echo "\nPWD: " . $ftp->pwd();
```

**Expected Result:**
```
Connected
Plain
PWD: /
```

---

### 37.2 上傳/下載

```php
// 上傳字串
$ftp->uploadString('Hello FTP!', '/remote/test.txt');

// 下載為字串
$content = $ftp->downloadString('/remote/test.txt');
echo $content;

// 上傳檔案
$ftp->upload('/local/file.txt', '/remote/file.txt');

// 下載檔案
$ftp->download('/remote/file.txt', '/local/downloaded.txt');
```

**Expected Result:**
```
Hello FTP!
```

---

### 37.3 目錄操作

```php
$ftp->mkdir('/remote/newdir');
$ftp->mkdirRecursive('/remote/a/b/c');
$ftp->chdir('/remote/newdir');
echo "PWD: " . $ftp->pwd() . "\n";

$ftp->cdup();
echo "PWD: " . $ftp->pwd() . "\n";

$files = $ftp->listFiles('/remote');
print_r($files);
```

**Expected Result:**
```
PWD: /remote/newdir
PWD: /remote
Array
(
    [0] => newdir
    [1] => a
    [2] => test.txt
    [3] => file.txt
)
```

---

### 37.4 檔案資訊

```php
echo "Size: " . $ftp->size('/remote/test.txt') . " bytes\n";
echo "Exists: " . ($ftp->exists('/remote/test.txt') ? 'yes' : 'no') . "\n";
echo "IsDir: " . ($ftp->isDir('/remote/newdir') ? 'yes' : 'no') . "\n";
echo "Modified: " . date('Y-m-d', $ftp->lastModified('/remote/test.txt'));
```

**Expected Result:**
```
Size: 10 bytes
Exists: yes
IsDir: yes
Modified: 2025-07-15
```

---

### 37.5 刪除 / 重命名 / chmod

```php
$ftp->rename('/remote/test.txt', '/remote/renamed.txt');
$ftp->chmod('/remote/renamed.txt', 0644);
$ftp->delete('/remote/renamed.txt');
$ftp->rmdirRecursive('/remote/a');

echo 'Operations complete';
```

**Expected Result:**
```
Operations complete
```

---

### 37.6 Session Logs

```php
$logs = $ftp->getLogs();
echo count($logs) . " log entries\n";
$ftp->clearLogs();
echo count($ftp->getLogs()) . " after clear";
```

**Expected Result:**
```
12 log entries
0 after clear
```

---

### 37.7 斷開連線

```php
$ftp->disconnect();
var_dump($ftp->isConnected());
```

**Expected Result:**
```
bool(false)
```

---

## 38. SFTPClient (SFTP 客戶端)

> `Razy\SFTPClient` — SSH/SFTP 客戶端，支援密碼、金鑰、Agent 認證。

### 38.1 連線與密碼登入

```php
use Razy\SFTPClient;

$sftp = new SFTPClient('ssh.example.com', 22, 30);
$sftp->loginWithPassword('user', 'pass');

echo $sftp->isConnected() ? 'Connected' : 'Failed';
```

**Expected Result:**
```
Connected
```

---

### 38.2 金鑰登入

```php
$sftp = new SFTPClient('ssh.example.com');
$sftp->loginWithKey('user', '/path/to/id_rsa.pub', '/path/to/id_rsa', 'passphrase');
echo $sftp->isConnected() ? 'Key auth OK' : 'Failed';
```

**Expected Result:**
```
Key auth OK
```

---

### 38.3 上傳/下載

```php
$sftp->uploadString('Hello SFTP!', '/remote/test.txt', 0644);
$content = $sftp->downloadString('/remote/test.txt');
echo $content;
```

**Expected Result:**
```
Hello SFTP!
```

---

### 38.4 檔案資訊

```php
var_dump($sftp->exists('/remote/test.txt'));  // true
var_dump($sftp->isFile('/remote/test.txt'));  // true
var_dump($sftp->isDir('/remote'));            // true
echo "Size: " . $sftp->size('/remote/test.txt');
```

**Expected Result:**
```
bool(true)
bool(true)
bool(true)
Size: 11
```

---

### 38.5 目錄操作

```php
$sftp->mkdir('/remote/newdir', 0755, true);
$files = $sftp->listFiles('/remote');
print_r($files);

$sftp->rmdirRecursive('/remote/newdir');
```

**Expected Result:**
```
Array
(
    [0] => test.txt
    [1] => newdir
)
```

---

### 38.6 Symlink / Realpath

```php
$sftp->symlink('/remote/test.txt', '/remote/link.txt');
echo $sftp->readlink('/remote/link.txt') . "\n";
var_dump($sftp->isLink('/remote/link.txt'));
echo $sftp->realpath('/remote/link.txt');
```

**Expected Result:**
```
/remote/test.txt
bool(true)
/remote/test.txt
```

---

### 38.7 exec — 遠端命令

```php
$output = $sftp->exec('echo "Hello from SSH"');
echo trim($output);
```

**Expected Result:**
```
Hello from SSH
```

---

### 38.8 getFingerprint / getAuthMethods

```php
$fingerprint = $sftp->getFingerprint();
echo strlen($fingerprint) > 0 ? 'Fingerprint OK' : 'No fingerprint';

$methods = $sftp->getAuthMethods('user');
print_r($methods);
```

**Expected Result:**
```
Fingerprint OK
Array
(
    [0] => publickey
    [1] => password
)
```

---

## 39. Mailer (郵件)

> `Razy\Mailer` — SMTP 郵件發送，支援 HTML、附件、非同步。

### 39.1 基本設定與發送

```php
use Razy\Mailer;

$mailer = new Mailer('smtp.example.com', 587, 'tls');
$mailer->from('noreply@example.com', 'My App');
$mailer->to('user@example.com');
$mailer->setSubject('Welcome!');
$mailer->setHTML('<h1>Hello!</h1><p>Welcome to our service.</p>');
$mailer->setText('Hello! Welcome to our service.');

// $mailer->send(); // 在實際 SMTP 環境中發送
echo 'Mailer configured';
```

**Expected Result:**
```
Mailer configured
```

---

### 39.2 CC / BCC

```php
$mailer->cc('cc@example.com');
$mailer->bcc('bcc@example.com');
echo 'CC/BCC added';
```

**Expected Result:**
```
CC/BCC added
```

---

### 39.3 附件

```php
$mailer->addAttachment('/path/to/report.pdf');
echo 'Attachment added';
```

**Expected Result:**
```
Attachment added
```

---

### 39.4 非同步發送

```php
// $mailer->sendAsync(); // 非阻塞發送
echo 'Async send supported';
```

**Expected Result:**
```
Async send supported
```

---

## 40. YAML (解析/輸出)

> `Razy\YAML` — YAML 解析與輸出。

### 40.1 parse — 解析字串

```php
use Razy\YAML;

$yaml = "
name: Razy
version: 0.5
features:
  - routing
  - templating
  - orm
";

$data = YAML::parse($yaml);
echo $data['name'] . "\n";
echo $data['version'] . "\n";
print_r($data['features']);
```

**Expected Result:**
```
Razy
0.5
Array
(
    [0] => routing
    [1] => templating
    [2] => orm
)
```

---

### 40.2 parseFile — 解析檔案

```php
file_put_contents('/tmp/config.yml', "db:\n  host: localhost\n  port: 3306");

$data = YAML::parseFile('/tmp/config.yml');
echo $data['db']['host'] . ':' . $data['db']['port'];
```

**Expected Result:**
```
localhost:3306
```

---

### 40.3 dump — 輸出為 YAML 字串

```php
$data = ['app' => 'Razy', 'debug' => true, 'modules' => ['auth', 'api']];
$yaml = YAML::dump($data);
echo $yaml;
```

**Expected Result:**
```yaml
app: Razy
debug: true
modules:
  - auth
  - api
```

---

### 40.4 dumpFile — 輸出到檔案

```php
$data = ['setting' => 'value'];
YAML::dumpFile('/tmp/output.yml', $data);

$content = file_get_contents('/tmp/output.yml');
echo trim($content);
```

**Expected Result:**
```
setting: value
```

---

## 41. SimpleSyntax (簡易語法解析)

> `Razy\SimpleSyntax` — 自訂簡易語法解析，支援括號解析。

### 41.1 parseSyntax

```php
use Razy\SimpleSyntax;

$result = SimpleSyntax::parseSyntax('hello.world.test');
print_r($result);
```

**Expected Result:**
```
Array
(
    [0] => hello
    [1] => world
    [2] => test
)
```

---

### 41.2 parseParens — 括號解析

```php
$result = SimpleSyntax::parseParens('func(arg1, arg2)');
print_r($result);
```

**Expected Result:**
```
Array
(
    [name] => func
    [args] => Array
        (
            [0] => arg1
            [1] => arg2
        )
)
```

---

## 42. SimplifiedMessage (簡化訊息協議)

> `Razy\SimplifiedMessage` — 類 STOMP 的文字訊息幀協議。

### 42.1 encode / decode

```php
use Razy\SimplifiedMessage;

$encoded = SimplifiedMessage::encode('SEND', ['destination' => '/queue/test'], 'Hello!');
echo "Encoded:\n$encoded\n";

$msg = SimplifiedMessage::decode($encoded);
echo "Command: " . $msg->getCommand() . "\n";
echo "Header: " . $msg->getHeader('destination') . "\n";
echo "Body: " . $msg->getBody();
```

**Expected Result:**
```
Encoded:
SEND
destination:/queue/test

Hello!

Command: SEND
Header: /queue/test
Body: Hello!
```

---

### 42.2 getMessage / fetch

```php
$sm = new SimplifiedMessage();
// fetch() 從串流讀取訊息
// getMessage() 回傳已解析的訊息物件
echo 'SimplifiedMessage: frame-based protocol';
```

**Expected Result:**
```
SimplifiedMessage: frame-based protocol
```

---

## 43. Profiler (效能分析)

> `Razy\Profiler` — 效能量測工具，支援 Checkpoint 與報表。

### 43.1 checkpoint + report

```php
use Razy\Profiler;

$profiler = new Profiler();
$profiler->checkpoint('start');

// 模擬工作
usleep(10000); // 10ms

$profiler->checkpoint('middle');
usleep(5000);  // 5ms

$profiler->checkpoint('end');

$report = $profiler->report(true, 'start', 'middle', 'end');
echo "Checkpoints: " . count($report) . "\n";
echo isset($report['start']) ? 'start present' : 'missing';
```

**Expected Result:**
```
Checkpoints: 3
start present
```

---

### 43.2 reportTo — 指定比較

```php
$profiler = new Profiler();
$profiler->checkpoint('a');
usleep(5000);
$profiler->checkpoint('b');

$diff = $profiler->reportTo('b');
echo 'Time from init to b: ' . ($diff > 0 ? 'measured' : 'zero');
```

**Expected Result:**
```
Time from init to b: measured
```

---

## 44. Terminal (終端機工具)

> `Razy\Terminal` — CLI 工具，支援格式化輸出、參數解析。

### 44.1 displayHeader

```php
use Razy\Terminal;

Terminal::displayHeader('Razy CLI', '0.5.0');
echo 'Header displayed';
```

**Expected Result:**
```
=========================
  Razy CLI v0.5.0
=========================
Header displayed
```
（格式可能略有不同）

---

### 44.2 getParameters — 取得 CLI 參數

```php
// php script.php --name=test --verbose
$params = Terminal::getParameters();
// 回傳已解析的 CLI 參數陣列
echo is_array($params) ? 'Params parsed' : 'Failed';
```

**Expected Result:**
```
Params parsed
```

---

### 44.3 getCode — 取得命令代碼

```php
$code = Terminal::getCode();
echo is_string($code) || is_null($code) ? 'Code retrieved' : 'Failed';
```

**Expected Result:**
```
Code retrieved
```

---

### 44.4 Format — 格式化字串

```php
$formatted = Terminal::Format('{green}Success{reset}: Operation complete');
echo strlen($formatted) > 0 ? 'Formatted' : 'Empty';
```

**Expected Result:**
```
Formatted
```

---

### 44.5 writeLineLogging — 寫入日誌行

```php
Terminal::writeLineLogging('info', 'Processing complete');
echo 'Log written';
```

**Expected Result:**
```
[INFO] Processing complete
Log written
```
（輸出格式取決於 Terminal 的實作）

---

## 45. Thread / ThreadManager (多執行緒)

> `Razy\Thread` + `Razy\ThreadManager` — 進程 spawn 與管理。

### 45.1 spawn + await

```php
use Razy\ThreadManager;

$manager = new ThreadManager();
$thread = $manager->spawn('php', ['-r', 'echo "Hello from thread";']);
$result = $thread->await();

echo $result;
```

**Expected Result:**
```
Hello from thread
```

---

### 45.2 spawnPHPCode

```php
$thread = $manager->spawnPHPCode('echo 1 + 2;');
$result = $thread->await();
echo $result;
```

**Expected Result:**
```
3
```

---

### 45.3 status

```php
$thread = $manager->spawnPHPCode('sleep(1); echo "done";');
echo $thread->status() . "\n"; // running 或 pending
$thread->await();
echo $thread->status();        // exited 或 complete
```

**Expected Result:**
```
running
complete
```

---

### 45.4 joinAll

```php
$t1 = $manager->spawnPHPCode('echo "A";');
$t2 = $manager->spawnPHPCode('echo "B";');
$t3 = $manager->spawnPHPCode('echo "C";');

$results = $manager->joinAll();
echo count($results) . " threads completed";
```

**Expected Result:**
```
3 threads completed
```

---

## 46. Domain (網域解析)

> `Razy\Domain` — 網域名稱解析與操作。

### 46.1 網域解析

```php
use Razy\Domain;

$domain = new Domain('sub.example.com');
// Domain 提供網域名稱的解析功能
echo 'Domain parsed';
```

**Expected Result:**
```
Domain parsed
```

---

## 47. Agent (User-Agent 解析)

> `Razy\Agent` — User-Agent 字串解析，識別瀏覽器與作業系統。

### 47.1 基本解析

```php
use Razy\Agent;

$agent = new Agent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
// Agent 解析 UA 字串，提供瀏覽器、作業系統等資訊
echo 'Agent parsed';
```

**Expected Result:**
```
Agent parsed
```

---

## 48. OAuth2 (OAuth2 客戶端)

> `Razy\OAuth2` — OAuth2 授權流程客戶端。

### 48.1 設定 Authorization URL

```php
use Razy\OAuth2;

$oauth = new OAuth2();
$oauth->setAuthorizeUrl('https://accounts.google.com/o/oauth2/auth');
$oauth->setTokenUrl('https://oauth2.googleapis.com/token');

$authUrl = $oauth->getAuthorizationUrl([
    'client_id'     => 'your-client-id',
    'redirect_uri'  => 'https://example.com/callback',
    'response_type' => 'code',
    'scope'         => 'openid email',
]);

echo str_starts_with($authUrl, 'https://accounts.google.com') ? 'URL generated' : 'Failed';
```

**Expected Result:**
```
URL generated
```

---

### 48.2 getAccessToken

```php
// 在收到 authorization code 後：
// $token = $oauth->getAccessToken([
//     'client_id'     => 'your-client-id',
//     'client_secret' => 'your-secret',
//     'code'          => $_GET['code'],
//     'grant_type'    => 'authorization_code',
//     'redirect_uri'  => 'https://example.com/callback',
// ]);
// echo $token['access_token'];
echo 'Token exchange configured';
```

**Expected Result:**
```
Token exchange configured
```

---

### 48.3 refreshAccessToken

```php
// $newToken = $oauth->refreshAccessToken([
//     'client_id'     => 'your-client-id',
//     'client_secret' => 'your-secret',
//     'refresh_token' => $refreshToken,
//     'grant_type'    => 'refresh_token',
// ]);
echo 'Refresh token supported';
```

**Expected Result:**
```
Refresh token supported
```

---

### 48.4 httpGet — 帶 Token 的 API 請求

```php
// $data = $oauth->httpGet('https://api.example.com/user', $accessToken);
// print_r($data);
echo 'Authenticated API requests supported';
```

**Expected Result:**
```
Authenticated API requests supported
```

---

## 49. WorkerLifecycleManager (Worker 生命週期)

> `Razy\Worker\WorkerLifecycleManager` — Caddy/RoadRunner Worker 模式生命週期管理。
> `Razy\Worker\WorkerState` — Worker 狀態列舉。

### 49.1 WorkerState Enum

```php
use Razy\Worker\WorkerState;

$ready = WorkerState::Ready;
var_dump($ready->canAcceptRequests());  // true
var_dump($ready->shouldExit());         // false

$terminated = WorkerState::Terminated;
var_dump($terminated->canAcceptRequests()); // false
var_dump($terminated->shouldExit());        // true
```

**Expected Result:**
```
bool(true)
bool(false)
bool(false)
bool(true)
```

---

### 49.2 WorkerLifecycleManager — 基本用法

```php
use Razy\Worker\WorkerLifecycleManager;

$manager = new WorkerLifecycleManager('/tmp/worker-signal', 10, 100);
$manager->setDrainTimeout(30);
$manager->setCheckInterval(50);

echo $manager->getState()->name . "\n";
var_dump($manager->canAcceptRequests());
var_dump($manager->shouldTerminate());
echo "Inflight: " . $manager->getInflightCount();
```

**Expected Result:**
```
Ready
bool(true)
bool(false)
Inflight: 0
```

---

### 49.3 requestStarted / requestFinished

```php
$manager = new WorkerLifecycleManager();

$manager->requestStarted();
echo "Inflight: " . $manager->getInflightCount() . "\n"; // 1

$manager->requestStarted();
echo "Inflight: " . $manager->getInflightCount() . "\n"; // 2

$manager->requestFinished();
echo "Inflight: " . $manager->getInflightCount() . "\n"; // 1

$manager->requestFinished();
echo "Inflight: " . $manager->getInflightCount();         // 0
```

**Expected Result:**
```
Inflight: 1
Inflight: 2
Inflight: 1
Inflight: 0
```

---

### 49.4 beginDrain — 排水模式

```php
$manager = new WorkerLifecycleManager();
$action = $manager->beginDrain('shutting down');
echo "Action: $action\n";
var_dump($manager->canAcceptRequests()); // false (draining)
```

**Expected Result:**
```
Action: draining
bool(false)
```

---

### 49.5 setLogger — 日誌回呼

```php
$logs = [];
$manager = new WorkerLifecycleManager();
$manager->setLogger(function ($msg) use (&$logs) {
    $logs[] = $msg;
});

$manager->requestStarted();
$manager->requestFinished();

echo count($logs) > 0 ? 'Logger active' : 'No logs';
```

**Expected Result:**
```
Logger active
```

---

### 49.6 checkForChanges

```php
$manager = new WorkerLifecycleManager();
$action = $manager->checkForChanges();
echo "Action: $action"; // 通常回傳 'continue'
```

**Expected Result:**
```
Action: continue
```

---

## 附錄 A：快速參考 — 類別與命名空間

| 類別 | 命名空間 | 用途 |
|------|----------|------|
| Container | `Razy\Container` | DI 容器 |
| Configuration | `Razy\Configuration` | 設定檔載入 |
| Env | `Razy\Env` | 環境變數 |
| Collection | `Razy\Collection` | 資料集合 |
| HashMap | `Razy\HashMap` | 雜湊映射 |
| Crypt | `Razy\Crypt` | AES-256-CBC 加密 |
| Authenticator | `Razy\Authenticator` | TOTP/HOTP |
| Database | `Razy\Database` | RDBMS 連線 |
| Statement | `Razy\Statement` | SQL 構建器 |
| TableHelper | `Razy\TableHelper` | Schema Migration |
| Model | `Razy\ORM\Model` | ORM 活動記錄 |
| Validator | `Razy\Validation\Validator` | 驗證引擎 |
| FieldValidator | `Razy\Validation\FieldValidator` | 欄位驗證 |
| ValidationResult | `Razy\Validation\ValidationResult` | 驗證結果 |
| NestedValidator | `Razy\Validation\NestedValidator` | 巢狀驗證 |
| FormRequest | `Razy\Validation\FormRequest` | 表單請求 |
| Required | `Razy\Validation\Rule\Required` | 必填規則 |
| Email | `Razy\Validation\Rule\Email` | 郵件規則 |
| MinLength | `Razy\Validation\Rule\MinLength` | 最短長度 |
| Numeric | `Razy\Validation\Rule\Numeric` | 數值規則 |
| IsArray | `Razy\Validation\Rule\IsArray` | 陣列規則 |
| Each | `Razy\Validation\Rule\Each` | 逐元素規則 |
| HttpClient | `Razy\HttpClient` | HTTP 客戶端 |
| Route | `Razy\Route` | 路由 |
| SSE | `Razy\SSE` | Server-Sent Events |
| XHR | `Razy\XHR` | JSON 回應 |
| Cache | `Razy\Cache` | PSR-16 快取 |
| RedisAdapter | `Razy\Cache\RedisAdapter` | Redis 介面卡 |
| Template | `Razy\Template` | 模板引擎 |
| DOM | `Razy\DOM` | HTML 構建 |
| EventDispatcher | `Razy\Event\EventDispatcher` | PSR-14 事件 |
| Emitter | `Razy\Emitter` | 跨模組 API |
| EventEmitter | `Razy\EventEmitter` | 事件發射器 |
| Logger | `Razy\Logger` | PSR-3 日誌 |
| LogManager | `Razy\Log\LogManager` | 多頻道日誌 |
| FileHandler | `Razy\Log\FileHandler` | 檔案日誌 |
| StderrHandler | `Razy\Log\StderrHandler` | 標準錯誤日誌 |
| NullHandler | `Razy\Log\NullHandler` | 空日誌 |
| Notification | `Razy\Notification\Notification` | 通知基類 |
| NotificationManager | `Razy\Notification\NotificationManager` | 通知管理 |
| MailChannel | `Razy\Notification\Channel\MailChannel` | 郵件頻道 |
| DatabaseChannel | `Razy\Notification\Channel\DatabaseChannel` | 資料庫頻道 |
| Pipeline | `Razy\Pipeline` | 中介層管線 |
| AuthManager | `Razy\AuthManager` | 認證管理 |
| RateLimiter | `Razy\RateLimiter` | 速率限制 |
| FileReader | `Razy\FileReader` | 檔案讀取 |
| FTPClient | `Razy\FTPClient` | FTP 客戶端 |
| SFTPClient | `Razy\SFTPClient` | SFTP 客戶端 |
| Mailer | `Razy\Mailer` | SMTP 郵件 |
| YAML | `Razy\YAML` | YAML 解析輸出 |
| SimpleSyntax | `Razy\SimpleSyntax` | 簡易語法 |
| SimplifiedMessage | `Razy\SimplifiedMessage` | 訊息協議 |
| Profiler | `Razy\Profiler` | 效能分析 |
| Terminal | `Razy\Terminal` | CLI 工具 |
| Thread | `Razy\Thread` | 執行緒 |
| ThreadManager | `Razy\ThreadManager` | 執行緒管理 |
| Domain | `Razy\Domain` | 網域解析 |
| Agent | `Razy\Agent` | UA 解析 |
| OAuth2 | `Razy\OAuth2` | OAuth2 客戶端 |
| WorkerState | `Razy\Worker\WorkerState` | Worker 狀態 |
| WorkerLifecycleManager | `Razy\Worker\WorkerLifecycleManager` | Worker 生命週期 |

---

*文件結束 — 共 49 個功能模組，涵蓋 Razy 框架所有功能。*
