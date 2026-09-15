# Razy Framework — 類別總覽與 Stage 2 重構對照

> **版本：** v0.5.x（Stage 2 重構完成後）  
> **統計：** 113 個類別 / 介面 / Trait / Enum，橫跨 15 個命名空間群組  
> **最終測試基線：** 1247 tests / 2054 assertions / 34 skipped — ALL PASS ✅  
> **文件日期：** 2026-02-23

---

## 目錄

- [變更圖例](#變更圖例)
- [核心類別 (Razy)](#核心類別-razy)
- [Cache 子系統 (Razy\Cache)](#cache-子系統-razycache)
- [Collection (Razy\Collection)](#collection-razycollection)
- [Config 服務 (Razy\Config)](#config-服務-razyconfig)
- [Contract 介面 (Razy\Contract)](#contract-介面-razycontract)
- [Database 子系統 (Razy\Database)](#database-子系統-razydatabase)
- [Distributor 子系統 (Razy\Distributor)](#distributor-子系統-razydistributor)
- [DOM 建構器 (Razy\DOM)](#dom-建構器-razydom)
- [Error 子系統 (Razy\Error)](#error-子系統-razyerror)
- [Exception 語義例外 (Razy\Exception)](#exception-語義例外-razyexception)
- [Module 子系統 (Razy\Module)](#module-子系統-razymodule)
- [Pipeline (Razy\Pipeline)](#pipeline-razypipeline)
- [Routing (Razy\Routing)](#routing-razyrouting)
- [Template 子系統 (Razy\Template)](#template-子系統-razytemplate)
- [Util 工具類別 (Razy\Util)](#util-工具類別-razyutil)
- [Tool 開發工具 (Razy\Tool)](#tool-開發工具-razytool)
- [Bootstrap (src/system)](#bootstrap-srcsystem)
- [Stage 2 重構變更摘要](#stage-2-重構變更摘要)
- [類別統計](#類別統計)

---

## 變更圖例

| 標記 | 含義 |
|------|------|
| 🆕 | Stage 2 全新加入的類別 |
| ✏️ | Stage 2 有修改（行為或介面變更） |
| — | 未因 Stage 2 改動 |

---

## 核心類別 (Razy)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `Razy\Agent` | class | 模組初始化的公開介面；註冊路由、API 指令、事件監聽、Script | — |
| 2 | `Razy\API` | class | 模組間 API 通信閘道，透過 Emitter 發佈跨模組呼叫 | — |
| 3 | `Razy\Application` | class | 框架頂層入口；管理多站台域名、Distributor 解析、URL 路由、Rewrite Rules | ✏️ **[Phase 2.2]** DI 改造 — Container 注入、Distributor 透過 Container 解析<br>✏️ **[Phase 3.3]** `updateRewriteRules()` 提取至 `RewriteRuleCompiler`<br>✏️ **[Phase 5.3]** 建構子延遲初始化（不做 I/O） |
| 4 | `Razy\Authenticator` | class | TOTP/HOTP 二因子驗證（RFC 6238/4226） | — |
| 5 | `Razy\Cache` | class | 靜態快取門面，委派 PSR-16 Adapter（預設 File-based） | — |
| 6 | `Razy\Collection` | class | 擴展 `ArrayObject`，支援 dot-notation 過濾、plugin 處理、序列化 | — |
| 7 | `Razy\Configuration` | class | 從 PHP/JSON/INI/YAML 設定檔載入、修改、持久化 key-value 設定 | — |
| 8 | `Razy\Container` | class | 🆕 **[Phase 2.1]** 輕量 DI 容器 — 支援 auto-wiring、transient/singleton/instance 綁定、alias | ✏️ **[Phase 6.1]** `get()` 改為拋出 `ContainerNotFoundException`（符合 PSR-11） |
| 9 | `Razy\Controller` | class | 模組生命週期處理器；被各模組子類化，包含 hooks 與路由處理器 | ✏️ **[Phase 2.4]** 新增 `resolve()` 與 `hasService()` 便利方法，透過 Container 存取服務 |
| 10 | `Razy\Crypt` | class | AES-256-CBC 對稱加解密，帶 HMAC-SHA256 完整性驗證 | — |
| 11 | `Razy\Database` | class | 統一資料庫抽象層（MySQL、PostgreSQL、SQLite），帶命名實例管理 | ✏️ **[Phase 4.2]** 錯誤拋出改用 `ConnectionException` / `DatabaseException` |
| 12 | `Razy\Distributor` | class | 站台分發的核心路由與模組管理引擎 | ✏️ **[Phase 3.4]** 大幅瘦身 883→616 行，移除 24 個純委派方法，新增 4 個子物件 getter<br>✏️ **[Phase 5.2]** 改用 `ConfigLoader` 載入設定檔 |
| 13 | `Razy\DOM` | class | 程式化建構 HTML 元素的 fluent API 基類 | — |
| 14 | `Razy\Domain` | class | 將主機名對應到一或多個 Distributor（依 URL path 前綴） | — |
| 15 | `Razy\Emitter` | class | 跨模組 API 通信代理（透過 `__call` 魔術方法） | — |
| 16 | `Razy\Error` | class | 自訂框架例外，含增強錯誤顯示與 debug backtrace | ✏️ **[Phase 1.2]** 移除 `exit()` 呼叫<br>✏️ **[Phase 3.2]** 渲染邏輯提取至 `ErrorRenderer`、設定提取至 `ErrorConfig` |
| 17 | `Razy\EventEmitter` | class | Distributor 內模組間的事件驅動通信機制 | — |
| 18 | `Razy\FileReader` | class | 跨多檔案的循序逐行讀取器，使用 `SplFileObject` | — |
| 19 | `Razy\FTPClient` | class | FTP/FTPS 客戶端，支援遠端檔案傳輸 | ✏️ **[Phase 4.2]** 錯誤改用 `FTPException` |
| 20 | `Razy\HashMap` | class | 有序 Hash Map，支援自訂 key、物件身份、自動生成 ID | — |
| 21 | `Razy\Mailer` | class | SMTP 郵件發送器，支援附件、HTML/純文字、CC/BCC、TLS/SSL、非同步 | — |
| 22 | `Razy\Module` | class | 模組生命週期管理核心；包裝 Controller 與 ModuleInfo 元資料 | ✏️ **[Phase 2.3]** DI 改造 — 透過 Container 解析子物件（`createAgent()`/`createThreadManager()`）<br>✏️ **[Phase 4.2]** 錯誤改用 `ModuleException` / `ModuleLoadException`<br>✏️ **[Phase 5.1]** 移除 7 個 `@deprecated` STATUS_* 常量 |
| 23 | `Razy\ModuleInfo` | class | 模組不可變元資料描述（code、version、prerequisites、assets） | ✏️ **[Phase 4.2]** 錯誤改用 `ModuleConfigException`<br>✏️ **[Phase 5.2]** 改用 `ConfigLoader` 載入設定<br>✏️ **[Phase 5.3]** 延遲初始化（首次存取才載入 config） |
| 24 | `Razy\OAuth2` | class | OAuth 2.0 授權碼流程處理器 | ⚠️ **[2026-09-17 OAuth dossier] superseded** — advertised since v0.5.x unwired; internals now run on the hardened HTTP client (S2 heart-swap, Q3: name kept) and are pinned by `OAuth2CoreTest`. Build on `Razy\Security\OAuth\OAuth2` instead (PKCE S256, signed single-use state, §5.2 mapping) |
| 25 | `Razy\Office365SSO` | class | Microsoft Office 365 / Azure AD SSO 認證客戶端 | ⚠️ **[2026-09-17 OAuth dossier] unwired, untested** — same fate as `OAuth2`; re-expressed on the new core per S3 (Q3 DECIDED) |
| 26 | `Razy\PackageManager` | class | Packagist 相容的套件下載、解壓、管理 | — |
| 27 | `Razy\Pipeline` | class | 串聯式 Action 管線，用於資料處理與驗證 | — |
| 28 | `Razy\PluginManager` | class | Template/Collection/Pipeline/Statement 的集中插件註冊中心 | — |
| 29 | `Razy\PluginTrait` | trait | 共用 trait — 從已註冊目錄載入/快取 plugin closures | — |
| 30 | `Razy\Profiler` | class | 執行時期效能分析器（記憶體、CPU 時間、宣告符號） | — |
| 31 | `Razy\RepoInstaller` | class | 從 GitHub repo 或自訂 ZIP URL 下載安裝模組 | — |
| 32 | `Razy\RepositoryManager` | class | 模組倉庫管理器，搜尋、列表、下載模組 | — |
| 33 | `Razy\Route` | class | 路由項目 — 綁定 closure path 到 controller（可選附帶資料） | — |
| 34 | `Razy\SFTPClient` | class | 透過 SSH 的安全檔案傳輸客戶端 | ✏️ **[Phase 4.2]** 錯誤改用 `SSHException` |
| 35 | `Razy\SimpleSyntax` | class | Razy Simple Syntax 表達式語言解析器 | — |
| 36 | `Razy\SimplifiedMessage` | class | STOMP-like 結構化訊息建構/解析器（IPC 用途） | — |
| 37 | `Razy\SSE` | class | Server-Sent Events 串流處理器 | — |
| 38 | `Razy\Template` | class | 模板引擎管理器 — 解析、渲染、插件、佇列式輸出 | ✏️ **[Phase 4.2]** 錯誤改用 `TemplateException` |
| 39 | `Razy\Terminal` | class | CLI 終端處理器，含 ANSI 色彩、文字格式化、輸入讀取、日誌 | — |
| 40 | `Razy\Thread` | class | 單一執行執行緒（inline 或 process-based），帶生命週期追蹤 | — |
| 41 | `Razy\ThreadManager` | class | 執行緒池管理器 — 非同步任務執行，帶併發限制 | — |
| 42 | `Razy\XHR` | class | JSON API 回應建構器，支援 CORS/CORP 標頭 | — |
| 43 | `Razy\YAML` | class | 原生 YAML 解析/輸出門面（無外部依賴） | — |
| 44 | `Razy\YAMLParser` | class (internal) | 內部 YAML 解析引擎 — mapping、sequence、scalar、anchor、alias | — |

---

## Cache 子系統 (Razy\Cache)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `CacheInterface` | interface | PSR-16 相容的 Simple Cache 介面合約 | ✏️ **[Phase 6.2]** 正式 `extends Psr\SimpleCache\CacheInterface` |
| 2 | `FileAdapter` | class | 檔案式快取 adapter，帶目錄分片與延遲清除 | — |
| 3 | `NullAdapter` | class | 空操作快取 adapter（禁用快取/測試用） | — |
| 4 | `ApcuAdapter` | class | APCu 共享記憶體快取 adapter（需 ext-apcu） | — |
| 5 | `InvalidArgumentException` | class | 無效快取 key 的例外 | — |

---

## Collection (Razy\Collection)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `Processor` | class | 鏈式資料轉換器 — 對 Collection 值子集透過 plugin 處理 | — |

---

## Config 服務 (Razy\Config)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ConfigLoader` | class | 🆕 **[Phase 5.2]** 可 Mock 的設定檔載入器（取代直接 `require` 呼叫） | — |

---

## Contract 介面 (Razy\Contract)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ContainerInterface` | interface | PSR-11 相容的 DI 容器合約 | 🆕 **[Phase 2.1]** 新增介面<br>✏️ **[Phase 6.1]** 正式 `extends Psr\Container\ContainerInterface` |
| 2 | `DatabaseInterface` | interface | 核心資料庫存取合約 | — |
| 3 | `EventDispatcherInterface` | interface | 事件分發器合約 | — |
| 4 | `ModuleInterface` | interface | 模組實例合約（狀態/元資料/API 執行） | — |
| 5 | `TemplateInterface` | interface | 模板引擎合約（載入/賦值） | — |

---

## Database 子系統 (Razy\Database)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `Driver` | abstract class | 資料庫驅動基類（MySQL、PostgreSQL、SQLite） | — |
| 2 | `Driver\MySQL` | class | MySQL 專用驅動 — CONCAT()、AUTO_INCREMENT、ON DUPLICATE KEY | — |
| 3 | `Driver\PostgreSQL` | class | PostgreSQL 專用驅動 — \|\| concat、SERIAL、ON CONFLICT | — |
| 4 | `Driver\SQLite` | class | SQLite 專用驅動 — \|\| concat、AUTOINCREMENT、ON CONFLICT | — |
| 5 | `Column` | class | 資料表欄位定義 — 類型、鍵、參照、SQL 生成 | — |
| 6 | `FetchMode` | enum | 型別安全的 fetch 模式 (Standard, Group, KeyPair) | — |
| 7 | `LazyResultSet` | class | 🆕 **[Phase 3.1]** 延遲載入與結果集處理（從 Statement 拆分出） | — |
| 8 | `Preset` | abstract class | 預設定查詢模式的基類 | — |
| 9 | `Query` | class | 包裝 PDOStatement 結果 — fetch、fetchAll、affected row | — |
| 10 | `Statement` | class | Fluent SQL 語句建構器（SELECT/INSERT/UPDATE/DELETE）帶 Simple Syntax | ✏️ **[Phase 3.1]** 大幅瘦身 — 執行邏輯提取至 `StatementExecutor`，lazy 功能提取至 `LazyResultSet`<br>✏️ **[Phase 4.2]** 錯誤改用 `QueryException` |
| 11 | `StatementExecutor` | class | 🆕 **[Phase 3.1]** SQL 語句執行器 — 執行與建構分離 | — |
| 12 | `StatementPool` | class | PDOStatement 的 LRU 快取池（減少 prepare() 開銷） | — |
| 13 | `Table` | class | 資料表定義 — 欄位、索引、外鍵、ALTER/CREATE SQL | — |
| 14 | `TableJoinSyntax` | class | FROM 子句解析器（TableJoin Simple Syntax，帶 JOIN 類型） | — |
| 15 | `WhereSyntax` | class | WHERE/HAVING 子句解析器（Where Simple Syntax） | — |
| 16 | `Table\ColumnHelper` | class | 欄位級 ALTER TABLE 語句 fluent builder | — |
| 17 | `Table\TableHelper` | class | ALTER TABLE SQL fluent builder（新增/修改/移除欄位/索引/FK） | — |

### Statement Builders (Razy\Database\Statement)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 18 | `StatementType` | enum | 🆕 **[Phase 3.1]** 型別安全 SQL 語句類型（Select, Insert, Update, Delete, Raw, Replace） | — |
| 19 | `SyntaxBuilderInterface` | interface | 🆕 **[Phase 3.1]** 型別專用 SQL 語法建構器合約（Strategy Pattern） | — |
| 20 | `Builder` | class | 🆕 **[Phase 3.1]** Statement builder plugin 的抽象基類 | — |
| 21 | `SelectSyntaxBuilder` | class | 🆕 **[Phase 3.1]** SELECT 語法建構器（columns、FROM/JOIN、WHERE、GROUP BY、HAVING、ORDER BY、LIMIT） | — |
| 22 | `InsertSyntaxBuilder` | class | 🆕 **[Phase 3.1]** INSERT/REPLACE 語法建構器（含驅動專用 upsert） | — |
| 23 | `UpdateSyntaxBuilder` | class | 🆕 **[Phase 3.1]** UPDATE 語法建構器 | — |
| 24 | `DeleteSyntaxBuilder` | class | 🆕 **[Phase 3.1]** DELETE 語法建構器 | — |

---

## Distributor 子系統 (Razy\Distributor)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ModuleRegistry` | class | 追蹤已載入模組、API 註冊、await 列表、模組生命週期佇列 | — |
| 2 | `ModuleScanner` | class | 🆕 **[Stage 1]** 檔案系統掃描模組發現、manifest 快取、autoloading | — |
| 3 | `PrerequisiteResolver` | class | 🆕 **[Stage 1]** 套件前置條件版本約束、衝突、組成管理 | — |
| 4 | `RouteDispatcher` | class | 🆕 **[Stage 1]** 路由註冊（standard、lazy、shadow、CLI script）與 URL 匹配/分發 | ✏️ **[Phase 1.2]** 移除 `exit()` — 重導向改用 `throw new RedirectException()` |

---

## DOM 建構器 (Razy\DOM)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `Input` | class | HTML `<input>` 元素建構器 | — |
| 2 | `Select` | class | HTML `<select>` 元素建構器 | — |

---

## Error 子系統 (Razy\Error)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ErrorConfig` | class | 🆕 **[Phase 3.2]** Error 系統的靜態設定管理（debug mode、cached errors） | — |
| 2 | `ErrorRenderer` | class | 🆕 **[Phase 3.2]** 例外頁面渲染器（HTML 模板或 CLI 文字輸出） | — |

---

## Exception 語義例外 (Razy\Exception)

> 🆕 **[Phase 4.1]** 全部為 Stage 2 新增，建立語義例外層級取代通用 `Error` 類別

| # | 類別 | 用途 | 繼承 |
|---|------|------|------|
| 1 | `CacheException` | 快取驅動失敗、儲存 I/O、序列化錯誤 | `RuntimeException` |
| 2 | `ConfigurationException` | 站台設定載入/解析失敗 | `RuntimeException` |
| 3 | `ContainerException` | 🆕 **[Phase 6.1]** 通用 DI 容器錯誤（PSR-11） | `RuntimeException` → `ContainerExceptionInterface` |
| 4 | `ContainerNotFoundException` | 🆕 **[Phase 6.1]** 容器中找不到條目（PSR-11） | `ContainerException` → `NotFoundExceptionInterface` |
| 5 | `DatabaseException` | 資料庫相關錯誤的基類 | `RuntimeException` |
| 6 | `ConnectionException` | 資料庫連線建立失敗 | `DatabaseException` |
| 7 | `QueryException` | SQL 查詢執行/建構失敗 | `DatabaseException` |
| 8 | `FTPException` | FTP 操作錯誤（連線/認證/傳輸） | `NetworkException` |
| 9 | `HttpException` | HTTP 錯誤回應例外基類（取代 exit()） | `RuntimeException` |
| 10 | `ModuleException` | 模組相關錯誤的基類 | `RuntimeException` |
| 11 | `ModuleConfigException` | 無效模組設定（package.php schema） | `ModuleException` |
| 12 | `ModuleLoadException` | 模組載入失敗（前置條件/controller 驗證） | `ModuleException` |
| 13 | `NetworkException` | 網路操作失敗的基類 | `RuntimeException` |
| 14 | `NotFoundException` | 404 Not Found — URL 無法匹配任何路由 | `HttpException` |
| 15 | `RedirectException` | 觸發 HTTP 重導向（取代 Controller::goto() 中的 exit()） | `HttpException` |
| 16 | `SSHException` | SSH/SFTP 操作錯誤 | `NetworkException` |
| 17 | `TemplateException` | 模板解析、區塊解析、渲染錯誤 | `RuntimeException` |

---

## Module 子系統 (Razy\Module)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ModuleStatus` | enum | 🆕 **[Stage 1]** 模組生命週期狀態（Failed, Disabled, Unloaded, Pending, Initialing, Processing, InQueue, Loaded） | ✏️ **[Phase 5.1]** 移除 Module 類別中對應的 7 個 `STATUS_*` 常量，inspect.inc.php 改用此 enum |
| 2 | `ClosureLoader` | class | 🆕 **[Stage 1]** 從模組 controller 目錄載入、快取、綁定 closure 檔案 | — |
| 3 | `CommandRegistry` | class | 管理 API 與 bridge 命令的註冊與執行 | — |
| 4 | `EventDispatcher` | class | 管理模組事件監聽器的註冊與分發 | — |

---

## Pipeline (Razy\Pipeline)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `Action` | abstract class | 管線中的抽象工作單元；支援樹狀結構、鏈接、廣播 | — |
| 2 | `Relay` | class | 透過 `__call` 廣播方法呼叫至管線中所有 Action | — |

---

## Routing (Razy\Routing)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `RewriteRuleCompiler` | class | 🆕 **[Phase 3.3]** 從多站台設定編譯 Apache .htaccess rewrite rules（從 Application 提取） | — |

---

## Template 子系統 (Razy\Template)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `Block` | class | 已解析模板中的結構區塊；支援巢狀、參數、遞迴、wrapper | ✏️ **[Phase 4.2]** 錯誤改用 `TemplateException` |
| 2 | `CompiledTemplate` | class | 預先 tokenized 的模板段落 — 重複渲染快 3-5 倍 | ✏️ **[Phase 1.3]** 新增 `clearCache()` 供 worker 模式記憶體清理 |
| 3 | `Entity` | class | Block 的執行時期實例 — 帶參數與子 entity 的渲染 | — |
| 4 | `Source` | class | 已載入模板檔案來源 — 管理根 block/entity 與來源級參數 | — |
| 5 | `ParameterBagTrait` | trait | 共用 `assign()` 與 `bind()` 邏輯（Template、Source、Block、Entity 共用） | — |
| 6 | `Plugin\TModifier` | class | 模板修飾器插件基類 (`{$var->modifier}`) | — |
| 7 | `Plugin\TFunction` | class | 標準模板函式插件基類 (`{@name param=value}`) | — |
| 8 | `Plugin\TFunctionCustom` | class | 自訂模板函式插件基類（帶原始語法處理） | — |

---

## Util 工具類別 (Razy\Util)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ArrayUtil` | class | 陣列操作 — construct、refactor、compare、wrap | ✏️ **[Phase 5.4]** 成為全域別名函式的正式替代品 |
| 2 | `DateUtil` | class | 日期計算 — 未來工作日、天數差異、假日排除 | ✏️ **[Phase 5.4]** 同上 |
| 3 | `NetworkUtil` | class | 網路工具 — SSL 偵測、IP 取得、FQDN 驗證 | ✏️ **[Phase 5.4]** 同上 |
| 4 | `PathUtil` | class | 路徑操作 — tidy、append、resolve、fix traversals | ✏️ **[Phase 5.4]** 同上 |
| 5 | `StringUtil` | class | 字串生成/格式化 — GUID、JSON 驗證、檔案大小 | ✏️ **[Phase 5.4]** 同上 |
| 6 | `VersionUtil` | class | 語義版本比較 — range、tilde、caret、邏輯運算子 | ✏️ **[Phase 5.4]** 同上 |

> **Phase 5.4 影響：** `bootstrap.inc.php` 中 24 個全域別名函式（如 `Razy\append()`、`Razy\guid()`）已標記 `@deprecated`。`src/` 中 235 處呼叫全部改為直接呼叫 Util 類別方法。

---

## Tool 開發工具 (Razy\Tool)

| # | 類別 | 類型 | 用途 | Stage 2 變更 |
|---|------|------|------|-------------|
| 1 | `ModuleMetadataExtractor` | class | 不需完整框架啟動即可從檔案系統提取模組元資料 | — |
| 2 | `SkillsGenerator` | class | 生成 skills 文件（root/distribution/module 三級） | — |

---

## Bootstrap (src/system)

| # | 檔案 | 用途 | Stage 2 變更 |
|---|------|------|-------------|
| 1 | `bootstrap.inc.php` | 框架啟動器 — 定義常量、註冊 autoloader、載入 Util 函式 | ✏️ **[Phase 5.4]** 24 個全域命名空間函式標記 `@deprecated` |

---

## Stage 2 重構變更摘要

### 新增類別一覽（27 個）

| Phase | 類別 | 分類 |
|-------|------|------|
| 2.1 | `Container` | 核心 — DI 容器 |
| 2.1 | `ContainerInterface` | 合約介面 |
| 3.1 | `StatementExecutor` | Database — 執行器 |
| 3.1 | `LazyResultSet` | Database — 延遲結果集 |
| 3.1 | `StatementType` | Database — 語句類型 enum |
| 3.1 | `SyntaxBuilderInterface` | Database — 建構器合約 |
| 3.1 | `Builder` | Database — 建構器基類 |
| 3.1 | `SelectSyntaxBuilder` | Database — SELECT 建構器 |
| 3.1 | `InsertSyntaxBuilder` | Database — INSERT 建構器 |
| 3.1 | `UpdateSyntaxBuilder` | Database — UPDATE 建構器 |
| 3.1 | `DeleteSyntaxBuilder` | Database — DELETE 建構器 |
| 3.2 | `ErrorRenderer` | Error — 渲染器 |
| 3.2 | `ErrorConfig` | Error — 設定管理 |
| 3.3 | `RewriteRuleCompiler` | Routing — Rewrite 編譯器 |
| 4.1 | `CacheException` | Exception 語義例外 |
| 4.1 | `ConfigurationException` | Exception 語義例外 |
| 4.1 | `DatabaseException` | Exception 語義例外 |
| 4.1 | `ConnectionException` | Exception 語義例外 |
| 4.1 | `QueryException` | Exception 語義例外 |
| 4.1 | `HttpException` | Exception 語義例外 |
| 4.1 | `ModuleException` | Exception 語義例外 |
| 4.1 | `ModuleConfigException` | Exception 語義例外 |
| 4.1 | `ModuleLoadException` | Exception 語義例外 |
| 4.1 | `NetworkException` | Exception 語義例外 |
| 4.1 | `TemplateException` | Exception 語義例外 |
| 4.1 | `FTPException` | Exception 語義例外 |
| 4.1 | `SSHException` | Exception 語義例外 |
| 5.2 | `ConfigLoader` | Config — 設定載入服務 |
| 6.1 | `ContainerException` | Exception — PSR-11 |
| 6.1 | `ContainerNotFoundException` | Exception — PSR-11 |

### 重大修改類別一覽

| 類別 | 變更摘要 |
|------|---------|
| `Application` | DI 改造、Rewrite Rules 提取、延遲初始化 |
| `Distributor` | 883→616 行瘦身、移除 24 委派方法、ConfigLoader |
| `Statement` | 拆分為 Builder/Executor/LazyResultSet 三類 |
| `Error` | 移除 exit()、拆分為 ErrorRenderer + ErrorConfig |
| `Module` | DI 改造、移除 STATUS_* 常量、語義例外 |
| `ModuleInfo` | ConfigLoader、延遲初始化、語義例外 |
| `Controller` | 新增 `resolve()`/`hasService()` DI 便利方法 |
| `Container` | PSR-11 `ContainerNotFoundException` |
| `ContainerInterface` | 正式 extends PSR-11 |
| `CacheInterface` | 正式 extends PSR-16 |
| `RouteDispatcher` | exit() → RedirectException |
| `bootstrap.inc.php` | 24 全域別名函式 `@deprecated` |

### 架構評分提升

| 維度 | 重構前 | 重構後 | 提升 |
|------|--------|--------|------|
| 架構整體性 | 58 | 80 | +22 |
| 效能 | 65 | 72 | +7 |
| 乾淨程式碼 | 52 | 76 | +24 |
| 模組化 | 62 | 75 | +13 |
| 可維護性 | 60 | 82 | +22 |
| 最佳實務 | 50 | 76 | +26 |
| **加權總分** | **58** | **77** | **+19** |

---

## 類別統計

| 分類 | 數量 |
|------|------|
| Classes | 97 |
| Abstract Classes | 3 (`Driver`、`Preset`、`Action`) |
| Interfaces | 7 (`CacheInterface`、`ContainerInterface`、`DatabaseInterface`、`EventDispatcherInterface`、`ModuleInterface`、`TemplateInterface`、`SyntaxBuilderInterface`) |
| Traits | 2 (`PluginTrait`、`ParameterBagTrait`) |
| Enums | 3 (`FetchMode`、`StatementType`、`ModuleStatus`) |
| Bootstrap scripts | 1 |
| **合計** | **113** |

---

## 附錄 2026-09 — `Razy\Auth\*` 守衛層（發佈面，本附錄為代碼實查）

> 本檔主體是 v0.5 Stage-2 快照；此附錄按「代碼贏過文件」原則補記 v1.x 期間落地/升格的類別。
> 細節與決策：`architecture/PERMISSION-MODULE.md`（Q1/Q2 已拍板）、`manual/07-security-guide.md` §6 子節。

| 類別 | 形態 | 職責 | 狀態 |
|------|------|------|------|
| `Razy\Auth\Gate` | class | 能力引擎：`define`/`policy`/`allows`/`authorize`；未定義能力**預設拒絕**；單槽 `before`/`after`（釘住）＋追加列 `addBefore`/`addAfter`（新增，跨模組合成） | **發佈面（RZ-012 僅准加法）**；核心未接線，接線屬應用 bootstrap |
| `Razy\Auth\GateFactory` | class (static) | 一 distributor 一 Gate 的記憶化註冊表；`flush()`/`forget()` 供 worker 模式 | 新增 · 發佈面 |
| `Razy\Auth\AuthManager` | class | 具名 guard 註冊表＋預設 guard 委派 | **發佈面**；舊 docblock 的 `TokenGuard` 例已改成真實存在的 `CallbackGuard`（ledger P1 收官） |
| `Razy\Auth\SessionGuard` | class (GuardInterface) | **只**把 actor 識別碼存 session（`$_SESSION`；未開 session 走請求級 fallback）、經應用提供的 resolver 水合——框架永不擁有 user row（Q1）；識別碼雜值 fail-closed 為訪客 | 新增 · 發佈面 |
| `Razy\Auth\CallbackGuard` | class (GuardInterface) | 閉包委派 guard（名字易誤會為路由閘，實為認證 guard——ledger P3） | 發佈面 |
| `Razy\Auth\GenericUser` | class (AuthenticatableInterface) | 陣列背書的通用 actor（無密碼者回傳 `''`，ledger P6） | 發佈面 |
| `Razy\Contract\GuardInterface` / `Razy\Contract\AuthenticatableInterface` | interface | 守衛／演員契約（無 login()/logout()——持久化是實作類的選擇） | 發佈面 |
| `Razy\Auth\Hash` | class | 密碼雜湊助手（與 `Razy\Authenticator` TOTP 正交） | 發佈面 |
| `Razy\Auth\AccessDeniedException` | class | `authorize()` 拒絕型異常 | 發佈面 |

刻意未列入＝尚不存在：`TokenGuard`、框架內建 permission 表層（屬 `razymod/permissions` 計畫 S2 起）。

## 附錄 2026-09 — `Razy\Security\OAuth\*` 授權核心（S2 落地，本附錄為代碼實查）

> 決策與流程設計：`architecture/OAUTH-SOCIALITE-HTTP.md`（Q1–Q5 全數依建議簽核 2026-09-17）。
> 零依賴（RZ-015）、零網路測試（注入 `ClientInterface`）：28 測 `OAuth2CoreTest`，含 RFC 7636 Appendix B 官方向量。

| 類別 | 形態 | 職責 | 狀態 |
|------|------|------|------|
| `Razy\Security\OAuth\OAuth2` | class | 授權碼流程核心：`begin()`（PKCE S256 恆開＋簽名 state）、`exchange()`（state 先驗**永不先觸網**、§5.2 錯誤映射、redirect_uri 精確匹配、單次 nonce 贖回）、`refresh()`（RFC 8707 無 PKCE） | 新增 · S2 |
| `Razy\Security\OAuth\StateSigner` | class | 簽名單次 state（Q2 方案 C：HMAC + `hash_equals` + provider/redirect_uri 綁定 + TTL）；PKCE verifier 由 Cache 保管、**從不經瀏覽器**；無 cache 即 fail-loud | 新增 · S2 |
| `Razy\Security\OAuth\TokenResponse` | class (readonly DTO) | token 端點回覆；相對 `expires_in` 落成絕對 `expiresAt` | 新增 · S2 |
| `Razy\Security\OAuth\OAuthConfig` | class (readonly DTO) | 每流程客戶參數（Q5：調用端從 env/配置組裝，核心不讀 env） | 新增 · S2 |
| `Razy\Security\OAuth\ProviderInterface` | interface | 提供者契約（Socialite 四方法形＋`authorizeParams` quirk 鉤）；憑證**不在**提供者內（RZ-006/Q5） | 新增 · S2 |
| `Razy\Security\OAuth\ProviderRegistry` | class | 具名提供者註冊表（非靜態袋，RZ-008） | 新增 · S2 |
| `Razy\OAuth2`（舊名） | class | **superseded**（Q3 留名換心）：內部改走加固 HttpClient，urlencoded token 回覆不再炸（原缺陷釘入 S2 註解）；`parseJWT`/`validateState`/`isJWTExpired` 原樣保留（`Office365SSO` 依賴，待 S3 改寫） | 換心 · S2 |

刻意未列入＝屬 S3/S5：`Provider\GithubProvider`、`Provider\GoogleProvider`（S3）、`razymod/oauth` 模組面（S5）。
