# Razy Framework 深度分析報告

**分析日期:** 2026-07-31 | **分析對象:** Razy v1.0.2-beta–1.0.3-beta (HEAD `7d9b6ba`)
**方法:** 實測執行 (PHPUnit 全數跑過一遍)、逐檔代碼審查、git 取證 (forensics)、CI/文檔/benchmark 原始數據交叉比對。四條獨立審計線 (代碼質量、安全、測試與 benchmark、容器化與隔離) 全部基於可驗證的 file:line 證據。

---

## 0. 執行摘要與總評分

| 維度 | 評分 | 一句話結論 |
|------|------|-----------|
| 架構獨創性 | ★★★★☆ | 「多 Distributor + 版本化模組 + 零執行期依賴單 phar」在 PHP 世界確實獨一無二 |
| 測試工程 | ★★★★☆ | 實測 4,845 tests / 8,672 assertions 全過、CI 矩陣 + 50% 覆蓋率閘門，屬同規模專案的頂尖水準 |
| 代碼整潔度 | ★★★☆☆☆ | 標記債 (TODO/FIXME) 幾乎為零、PSR-4 100% 合規；但 God class、程序式 CLI 層、文檔散佈拉低分數 |
| 安全性 | ★★☆☆☆ | 密碼學原語做得好，但**框架級三大缺口** (模板不轉義、zip-slip、bridge 無認證) 會由每個下游客戶端繼承 |
| Benchmark 可信度 | ★★☆☆☆ | 腳手架認真 (資源對等、OPcache 逐字節相同、原始數據入庫)，但端點工作量與連接池不對等 + 4/6 Laravel 數據無從驗證，「5× faster」被明顯放大 |
| 隔離成熟度 | ★★☆☆☆ | 「命名級 + 進程級孤島」；OS 級與 K8s 級全是設計文檔，且 v1.0.2-beta **撤回過 4/5 的跨廠商隔離修復** |
| 生態系 | ★☆☆☆☆ | 零依賴是護城河也是孤島：沒有第三方生態可用 |

**通盤判斷:** Razy 是一個「架構敘事超前於執行期現實」的框架。它的模組化分發願景、測試紀律、worker 生命週期設計是真材實料；但安全邊界、跨租戶隔離、benchmark 宣稱三者都存在「文檔/變更日誌說的比代碼做的多」的系統性落差。內部工具或單團隊多客戶維運可用；對外 multi-tenant SaaS 目前**不建議**，直到 Phase 1-3 落地且模板轉義補上。

---

## 1. 框架定位與核心能力

### 1.1 它是什麼
一個 PHP 8.2+ 的**多站點 / 多 Distributor 模組化框架**。核心命題:一份 codebase 養 N 個客戶專案，每個 Distributor (站點) 載入自己那份「版本鎖定的模組組合」，共享同一套框架與共用模組，最終打包成單一 `Razy.phar` 部署。起源於真實的自由接案多客戶維護痛點 (README 13 個階段敘事)，v1.0-beta 於 2026-02 開源。

### 1.2 能力清單 (全部經代碼驗證存在)
- **模組系統:** 14 個生命週期鉤子 (`__onInit → __onLoad → __onRequire → __onReady → __onRouted/__onScriptReady → __onEntry`)、依賴解析、跨模組 API (`$this->api('vendor/provider')->method()`)、事件系統、bridge 跨站調用
- **模板引擎:** 區塊式 (`{@if}/{@each}/{@WRAPPER}/{@TEMPLATE}`)，9 個內建修飾符 (upper/lower/trim/join/nl2br/capitalize/alphabet/gettype/addslashes) — **無 escape 修飾符** (§7.1)
- **數據庫:** MySQL/PostgreSQL/SQLite 多驅動、fluent builder、「Simple Syntax」(一行語法生成 JOIN + JSON_CONTAINS SQL)、ORM、migration、事務 savepoint
- **官方內建件 (零外部依賴):** SSE、XHR/CORS、Mailer (SMTP)、DOM builder、Crypt (AES-256-CBC+HMAC)、Collection、HashMap、YAML、OAuth2、Cache (PSR-16: File/Redis/Null)、Authenticator (TOTP/HOTP 2FA)、FTP/SFTP client、WebSocket server/client、Session (多驅動)、CSRF、Queue、RateLimit、Validation、Logger
- **CLI:** `src/system/terminal/` 下 27 個命令 (build/compose/pack/publish/install/bridge/pkg/serve/runapp…)
- **併發:** `ThreadManager` — 真 OS 進程隔離 (`proc_open` + 管道)、並發上限 4、環境變數加固 (屏蔽 `LD_PRELOAD`/`PHPRC`/`PHP_INI_SCAN_DIR` 等)
- **Standalone Package 系統:** 模組打包成 `.phar` CLI 應用，exec/serve 兩模式、`on_depend` 三種等待策略 (complete/healthcheck/load)、Package API/事件跨包通信
- **Worker 模式:** FrankenPHP 持久 worker + boot-once dispatch (每請求 ~0.05ms 框架開銷) + `WorkerLifecycleManager` 四策略零停機熱更新 (A 優雅排空重啟 / B 雙版本容器 / C 單模組熱替換 / C+ 匿名類 rebind)，tokenizer 自動分類變更類型
- **razy-hive (Go):** 本地開發流程啟動器 — 注意 `init/install/run/doctor/clean` 命令全是 TODO 佔位，`pkg/process/supervisor.go` 是空包 (僅 2 行)

### 1.3 最關鍵的架構事實: 零執行期依賴
`composer.lock` 解析結果: **production packages = 0**，62 個全是 dev (PHPUnit 10.5.63、PHPStan 2.1.40、php-cs-fixer 3.94.2、Symfony 7.4.x 工具鏈，全部為當前版本、無已知 CVE)。框架自帶 Mailer/WebSocket/OAuth2/YAML/Cache 等全部實作。

---

## 2. 對標現實框架

### 2.1 定位矩陣
| 對標對象 | 與 Razy 的關係 |
|---|---|
| **Laravel 12 (+Octane)** | 官方自選的 benchmark 假想敵。哲學相反: Laravel = 單應用全功能框架 (生態系巨大)，Razy = 多項目模組平台 (零生態)。Razy 的 Distributor 對 Laravel 是「十件化 (tenancy) 套件補裝」等級的對位，Razy 則是一等公民。Readme 的對照表本身寫得公允 (承認 Laravel 適合獨立應用/CPU 密集場景) |
| **Hyperf / Swoole 原生** | 真正的「高性能 PHP」競品。Hyperf 有协程 AOP、服务網格生態、成熟的 K8s 文檔。Razy 走 FrankenPHP 路線刻意避開协程 (README 承認 CPU-bound 輸給 Swoole，並把 Swoole/RoadRunner runtime 列為未來優化項) |
| **Symfony** | 組件化哲學部分相似，但 Symfony 沒有任何「多站點共享模組倉 + 每站點依賴樹隔離」的概念 |
| **Drupal Multisite / WP Multisite** | 多站點層面的 closest analog，但那是 CMS 級、粗粒度；Razy 在「模組版本鎖定 + 共享模組引用 (改一處全站受益)」的粒度上確實沒有 PHP 世界的現成對應物 |
| **Composer + Monorepo** | 務實替代品。多数團隊用 monorepo + path repository 達到「多專案共享代碼」；Razy 的差異化是 per-dist 依賴隔離、phar 單檔部署、以及模組級生命週期契約 |

### 2.2 獨創性判定
**「版本化模組作為分發單元 + 每 Distributor 獨立依賴樹 + 單 phar」的組合是 Razy 真正的護城河**，無直接競品。但注意: 這個設計同時鎖死了生態系 — 第三方套件只能經 Razy 自建的 package manager (非真 Composer) 安裝，無法使用 Packagist 全量生態 (自建 manager 只讀 Packagist metadata 自行解壓，§7.3 zip-slip 即出於此)。

---

## 3. 優勢 (經證據支持)

1. **多租戶架構是真需求** — per-dist autoload 目錄隔離 (`autoload/{distCode}/`)、`sites.inc.php` 域名→distributor 映射、`config_mapping` 同站多配置，全部經代碼驗證。
2. **測試紀律驚人** — 實測跑全suite: **4,845 tests / 8,672 assertions / 0 failures / 87 skipped (Windows) / 40 秒**。測試質量樣本好: 內存 SQLite、迴環 socket、temp 目錄隔離+tearDown 清理、無外部網絡依賴。
3. **CI 是真門禁** — PHP 8.2/8.3/8.4 × Ubuntu + Windows 矩陣、pcov、**50% 行覆蓋率硬閘**、php-cs-fixer (cs2pr) 與 PHPStan 各自為獨立 job。
4. **密碼學原語正確** — encrypt-then-MAC (HMAC 覆蓋密文、先驗後解)、隨機 IV、`hash_equals` 用在全部比較點 (CSRF/TOTP/HOTP/OAuth2 state/Crypt)、CSPRNG 產生全部 token/session ID/備用碼、TOTP/HOTP 符合 RFC 4226/6238、所有 `unserialize` 帶 `allowed_classes=false`。
5. **Worker 工程紮實** — boot-once + 四策略熱更新 + 每請求狀態復位 (`http_response_code(200)`、`header_remove`、ob 排空、`Error::reset`、`session_write_close`、`Module::resetForWorker` 清事件索引與 scoped 單例)，`WORKER_MAX_REQUESTS` 回收 + 每 500 請求生態週期回收。
6. **供應鏈面接近零** — 零執行期依賴 = 沒有第三方 CVE 面；phar 由本倉構建。
7. **Benchmark 基礎設施存在且部分誠實** — 資源上限逐 container 相同 (2 CPU/4G)、兩邊 OPcache/JIT ini 逐字節一致、Laravel 側正確 `config/route/view:cache`、MySQL 共享且種子數據一致、Razy 側原始 k6 數據入庫、報告承認 DB Write 與 Heavy CPU 落敗並解釋原因 (MySQL I/O 瓶頸、Swoole 协程隔離)。
8. **代碼標記債幾乎為零** — 628 個檔案中 TODO/FIXME/HACK/XXX 總計 6 處，且 2 個 TODO 是代碼生成器樣板、4 個是正式 `@deprecated` docblock。
9. **PSR-4 完全合規** — 全部 270 個庫檔案 namespace/檔名 0 違規 (全檢非抽樣)。

## 4. 劣勢與風險

1. **三大框架級安全缺口由所有下游應用繼承** (§7): 模板不轉義、zip-slip、bridge 無認證。
2. **隔離敘事 > 現實** (§8): Phase 1-5 零代碼；且 v1.0.2-beta 撤回過 4/5 跨廠商修復 (git 取證，§8.1)。Readme/changelog 目前**誇大**隔離保證。
3. **Benchmark 「5× Laravel」被放大** (§6): 端點工作量不對等 + 持久連接不對等 + 4/6 Laravel 頭部數據無提交佐證。
4. **文檔/宣稱數字過時或漂移** — README 「4,564 tests / 102 test classes」(實際 4,845 / 121)；版本字符串四處不一致 (VERSION=1.0.2-beta, composer.json=1.0.3-beta, README badge=1.0.2-beta, benchmark 用「v0.5 phar」)。
5. **官方 Docker 鏡像 ≠ benchmark 鏡像** — 發行的鏡像 CMD 是 `php -S` (內建開發服務器) 跑在 root、無 healthcheck、無 `open_basedir`/`disable_functions`；FrankenPHP 只出現在 benchmark 鏡像。用戶照 README `docker run` 拿到的性能與 benchmark 敘事無關。
6. **God class 與雙軌代碼風格** (§5): `ORM/Model.php` ~1,300 行/56 public methods、`pkg.inc.php` 1,264 行;CLI 層 30/30 程序式 `.inc.php` 含 17 個全域函數 (`env()`、`autoload()` — 命名污染風險最高者)。
7. **PHPStan 配置自我放水** — level 5 但 16 條寬鬆 `ignoreErrors` (含全放行的 `Call to an undefined method`)，且**整段排除 `PackageManager` — 正是 zip-slip 所在處**。
8. **靜態 worker 內存邊界** — worker 模式下所有 Distributor 共享一個 PHP class table 與 OPcache；框架復位清單未覆蓋的模組級靜態狀態會跨請求存活 (架構文檔自己承認此點)。
9. **生態系為零** + 自建非真 Composer 的包管理器 = 長期維護負擔與相容性風險。
10. **文件散佈** — `memory/` ≈ `docs/` 鏡像 (132 個共享路徑中 124 個逐字節相同)、空的 `Razy.wiki2/`、根目錄 13 個大 .md (~340KB，含 LLM 工作日誌)、根目錄 0 字節的 `Container.php`/`Distributor.php` 遮蔽核心類名。

---

## 5. Dirty Code 與代碼風格一致性

| 檢查項 | 結果 | 判定 |
|---|---|---|
| TODO/FIXME/HACK/XXX/未實現標記 | 628 檔 6 處，皆為生成器樣板或正式 `@deprecated` | ✅ 極乾淨 |
| 註釋掉的死代碼 (≥3 連行) | 547 檔僅 ~3 處真實死代碼，且均為 demo 模組中有標註的範例 | ✅ |
| 零字節 / 幽靈檔案 | 根目錄 `Container.php`/`Distributor.php` (0B，遮蔽核心類名)、`invalid` ( stray log) — 全部已被 gitignore | ⚠️ 本地垃圾，未入庫 |
| PSR-4 | 270/270 = 100% | ✅ |
| 結構風格分裂 | `src/library/Razy` PSR-4 類 vs `src/system` **100% 程序式** .inc.php，含 17 個全域函數 (`env`、`autoload`、`xcopy`、`isProcessRunning`…) | ⚠️ 主要風格債 |
| 命名慣例 | 庫內 camelCase 一致；`Controller.php` 28 個 camelCase API + 15 個 `__on*` 生命週期鉤子並存 (屬設計選擇) | ✅/⚠️ |
| 錯誤處理一致性 | `throw new` ×380 vs `return false` ×213 (~64/36)；最差 `PackageRunner.php` 30 處 return-false | ⚠️ 混用 |
| God class | `Model.php` 1,295 行、`pkg.inc.php` 1,264、`Module.php` 1,057、`Distributor.php` 777 — 前 10 大中 4 個職責 >3 | ⚠️ |
| 魔法值/洩漏 | 硬碼密碼/密鑰 **0**；`/var/www` 僅 1 處 docblock;`localhost` 40 處皆為驅動預設 | ✅ |
| Git 衛生 | `.gitignore` 採「全忽略+白名單」;被提交的可疑工件僅 `Razy.phar` (設計決策，反覆重建入歷史);`composer.lock` 未入庫 (庫類專案可接受);commit 為 conventional 風格 (有 `Made-with: Cursor` 標註，AI 輔助有誠實披露) | ✅/⚠️ |
| 風格工具 | CI 真實執行 php-cs-fixer + PHPStan;但本地存在舊版 `.php-cs-fixer.php` 會**遮蔽**官方 `.dist.php` (php-cs-fixer 優先讀 `.php`，兩者 `php_unit_test_annotation` 規則互相矛盾) | ⚠️ 陷阱 |
| 文檔散佈 | docs(145)/documentation(64, 唯一入庫)/memory(134, 與 docs 93% 逐字節相同)/Razy.wiki(63)/Razy.wiki2(空)/13 個根 .md | ❌ 重災區 |

**結論:** 「髒」不在代碼本體 (標記債、死代碼、風格一致性都明顯優於多數中型開源專案，與近期系統性重構/LLM_LOGBOOK 輔助相符)，而在**倉庫衛生與文檔治理**。最高優先修復: 刪除根目錄幽靈檔案、收斂單一文檔樹、刪舊 fixer 配置、拆分 Model.php/pkg.inc.php。

---

## 6. 測試與 BENCHMARK 現況 + 推薦標準

### 6.1 測試現況 (實測驗證)
- **實跑結果 (Windows, PHP 8.3.1):** `OK, but there were issues! — Tests: 4845, Assertions: 8672, Warnings: 2, Skipped: 87` (40s, exit 1 源於 2 個警告 + `failOnWarning=true`)。2 個警告實為負路徑測試故意觸發的 `trigger_error(E_USER_WARNING)` 泄漏到 PHPUnit 計數器 — 測試衛生小瑕。
- **靜態對賬:** 4,485 個測試方法 (3,742 `test*` + 743 `#[Test]`) + data provider 展開 ≈ 4,564 宣稱可達，但**倉庫內無任何提交的 phpunit 執行日誌佐證**宣稱;README 的 4,564/102 類別皆過時 (實際 4,845/121)。
- 跳過解釋成立: 1 (Windows /proc) + 53 (Redis) + 32 (SSH2) = 87，與 `.docker/docker-compose.test.yml` 標頭文件一致。
- 缺口: 集成測試僅 1 檔 (`tests/Integration/CollectionPipelineIntegrationTest`);無mutation測試 (infection)、無 SAST、無端到端 worker 模式測試、CI 未裝 redis/ssh2 擴展 (CI 上必然 ~85 跳過)。
- 約 15 處 `assertTrue(true)` 「不拋異常即成功」型斷言 (集中於 no-op handler，可接受但弱)。

### 6.2 現有 benchmark 的公平性裁決
**「部分公平、實質向 Razy 傾斜」。** 可信的基礎: 資源上限逐字節對等、OPcache/JIT ini 兩邊一致、Laravel 正確啟用三級快取、共享種子 MySQL、k6 載入曲線一致。實質缺陷 (按影響排序):
1. **端點工作量不對等 (HIGH)** — benchmark 容器實際跑的 Razy controller 用 `str_repeat` 裸拼 HTML 字串 (已親自驗證 `benchmark/razy/standalone/controller/app.php`)，**完全沒經過自家模板引擎**;Laravel 側是真 Blade render。「模板渲染 5.5×」實測的是字串拼接 vs 編譯模板。4 個勝利場景中 2 個因此被放大。
2. **DB 連接策略不對等 (HIGH)** — Razy 側 `PDO::ATTR_PERSISTENT => true` (已驗證)，Laravel Octane 側未配置持久連接;README 宣稱「兩側皆持久」為**不實**。全部 DB 場景受惠。
3. **4/6 Laravel 頭部數字無從驗證 (HIGH)** — scenario 1-4 的 Laravel 原始輸出 (1,254/1,137/952/842 RPS) 在倉庫中**不存在任何提交工件**;僅 scenario 5-6 的數據經我逐個核對屬實。
4. **Runtime 差異摺進「框架」對比 (HIGH)** — 實為 Razy+FrankenPHP 棧 vs Laravel+Swoole 棧，**無 PHP-FPM 基線**分離框架開銷與服務器 runtime 效應;且以「架構優勢」語氣呈現而非 caveat。
5. 工具鏈不可重現 — `grafana/k6:latest` 未釘版本、腳本運行時從 jslib.k6.io 拉 textSummary、frankenphp/php 鏡像浮動 tag、Laravel 12.53.0 無法由提交配方重建。
6. 其他 — 「37× 提升」的基線是一個故意損壞的 worker 迴圈 (每請求重建整個對象圖);README 載入曲線描述對 scenario 4-6 錯誤;`generate-report.py` 與真實 k6 summary schema 不相容 (讀 `metrics.http_reqs.values.rate`，實際是 `metrics.http_reqs.rate`)，明顯未使用;無服務器端資源數據提交 → 內存宣稱不可查核。

### 6.3 推薦的標準化測試 / Benchmark 協議

**測試金字塔補全 (優先級降序):**
1. 集成層: web 模式 + worker 模式端到端 (compose 起 FrankenPHP + Caddy 跑 k6 冒煙)、PackageManager/RepoInstaller 往返測試 (本地 LocalTransport)、bridge 認證契約測試
2. 安全掃描入 CI: `composer audit`、Semgrep/taint PHPStan (taint-analysis rules)、模板引擎 XSS golden test (給定 `{$x="<script>"}` 斷言轉義行為 — 先定義語義再測)
3. CI 補裝 redis/ssh2/sockets 擴展 → 消滅 85 個跳過;修掉 2 個警告使 `failOnWarning` 綠
4. 對 `Container`(DI)、`CommandRegistry`、`Crypt`、`Authenticator` 跑 infection 突變測試
5. 提交 phpunit 執行日誌工件，README 數字由 CI 自動生成 (消滅漂移)

**Benchmark 協議 (10 條):**
1. **釘死工具鏈:** k6/frankenphp/laravel/swoole 全部 digest 或精確版本 + 提交 lockfile;結果 JSON 內嵌環境指紋
2. **四棧基線:** Razy-FPM、Razy-FrankenPHP-worker、Laravel-FPM、Laravel-Octane-Swoole + 裸 runtime 控制器 (空 handler)，把「框架 vs 服務器」拆開
3. **端點對稱律:** 兩邊必須各自走自家模板引擎 + 自家 ORM;禁止 benchmark 端點抄捷徑
4. **連接策略對稱律:** 兩邊同樣持久或同樣不持久
5. 每場景 ≥5 runs、≥5 分鐘穩態、報告中位數 + 變異係數 (>5% 標紅)、p50/p95/p99/p99.9 全報
6. 提交全部原始 k6 JSON + docker stats + 主機規格 (CPU 型號/核數/內存/內核)
7. **24h soak + 冷啟動 + 崩潰恢復:** 持久 worker 必測洩漏曲線 (每 10k 請求內存增量) 與 `gc_collect_cycles` 每 500 請求生態成本
8. VU 飽和曲線 (10→800 掃描) 找拐點，而非單一曲線
9. **CI 性能閘門:** static-route RPS 回歸 >10% 即紅
10. 標題措辞誠實: 寫「stack-vs-stack」比較，勝負並陳 (現有報告已做到一半)
11. 至少補 Symfony 與裸 Swoole 兩個第二對照組

---

## 7. 安全性深度分析

### 7.1 高風險 (框架級，下游全繼承)
| # | 發現 | 證據 | 說明與修復 |
|---|------|------|-----------|
| S1 | **模板引擎無任何自動轉義** | `Template/Entity.php:383-400` `{$var}` 原樣 `return $value`;全 `Template/` 目錄無 htmlspecialchars;9 個內建修飾符**無 escape** | 用戶數據經 `{$user.comment}` 渲染即存儲型 XSS，且開發者連內建 escape 工具都沒有 (Blade/Twig 預設全轉義)。修復: `{$var}` 預設轉義 + `{$var|raw}` 顯式關閉 + 內建 `escape` 修飾符 |
| S2 | **Zip-slip 雙發** | `PackageManager.php:282` 與 `RepoInstaller.php:710` 直接 `extractTo()` 不驗證條目名;更糟: 解壓目錄本身由包名拼出 (`PackageManager.php:278`，包名含 `../` 可控目標目錄)、`mkdir(..., 0o777)` (`:285`);RepoInstaller 允許明文 HTTP (`:649-650`) | 惡意 registry 應答 / MITM / 惡意 GitHub repo → 任意路徑寫入 = 代碼執行。修復: 逐條目拒絕絕對路徑與 `..`、realpath 包含性檢查、HTTPS-only、包名白名單正則、0700 |
| S3 | **Bridge 跨站調用零認證、預設全開** | 全 `src/` 無任何 hash_hmac 用於 bridge (HMAC bridge 是 README 對 Phase 3 v1.2.0 的**未來設計**，非現狀);`Controller.php:187-190` `__onBridgeCall` 預設 `return true`;來源 distributor 是無認證可偽造的字符串;CLI `bridge` 命令 (`bridge.inc.php`) 調 `executeInternalCommand` 甚至**跳過 `__onAPICall` 權限閘** (`CommandRegistry.php:144-153`) | 同主機上任何能執行 `php Razy.phar bridge '{"dist":...}'` 的進程可調用任意模組內部 API;web 側任何模組可冒充其他 distributor。修復: 預設拒絕 + 共享密鑰 HMAC + nonce/時間戳防重放 + CLI 側本機 token |

### 7.2 中風險
- **SQL 非真預編譯綁定:** `Database.php:306-327` 先組出最終 SQL 字串再 `prepare()+execute()`(無參數數組)，值以 `PDO::quote()` 內聯 — 安全性依賴每條引用路徑正確。`Statement::getSearchTextSyntax()` (`Statement.php:122`) 對含單引號的搜索文本 (`O'Brien`) 落入未轉義 'expr' 路徑 → **注入地雷** (`WhereSyntax.php:810-824` 把原值裸包單引號)。`Table.php:301-303,775-778` 對反引號標識符誤用 addslashes (應用 `quoteIdentifier`)。ORM 側乾淨 (全走 `:param` 綁定);標識符有嚴格正則驗證。
- **`eval(base64_decode(...))`:** `ThreadManager.php:141-156` — 為繞過 Windows shell 引號問題的設計選擇且有文檔，但屬於安全掃描器紅旗模式;若任何模組把用戶輸入餵進 `spawnPHPCode()` 即遠程代碼執行。更安全的 `spawnPHPFile()` (0600 隨機檔名、原子 rename、TOCTOU 防護) 已存在，應設為唯一入口。
- **Crypt 原始密鑰:** 無 KDF (短密碼會被 OpenSSL 補齊至 32B → 弱密鑰);AES 與 HMAC 共用同一把鑰鍵 (無密鑰分離)。原語本身正確 (encrypt-then-MAC、隨機 IV、`hash_equals`)。
- **DI 黑名單是拒絕清單設計:** 14 個類 (`Module.php:1139-1154`) + parent-traversal 封鎖，今天有效 (父容器只註冊了這 3 個被擋的) — 但未來任何新註冊 (`Database`/`Session`/`RepoInstaller`…) 若忘記加黑名單即對模組代碼可解析;`Module::getContainer()` 在無子容器時回退到**未屏蔽的原始容器** (`Module.php:894`)。修復: 反轉為白名單 + 修回退。
- **Docker 硬化缺失:** 發行鏡像 root 運行、浮動 tag、無 HEALTHCHECK、無 `open_basedir`/`disable_functions`、CMD 為內建開發服務器 (`.docker/Dockerfile:58`)。
- **RestartSignal 信號檔無認證:** 能寫 data 目錄者即可重啟/終止 worker (本地 DoS)。
- **FTP/SFTP 遠端路徑無包含性檢查:** 應用把用戶輸入直通的場景可任意讀寫遠端檔 (用戶庫函數語義，中等)。

### 7.3 紮實的部分
TOTP/HOTP 完全符合 RFC 4226/6238 (counter pack、動態截斷、雙驗證路徑 `hash_equals`、≥16B CSPRNG 密鑰、uniform random_int 備用碼);CSRF 32B random_bytes + hash_equals + 同步器令牌模式;Session ID 160-bit CSPRNG、httpOnly+SameSite=Lax 預設、`regenerate()` 可用、全驅動 `allowed_classes=false` unserialize;CLI 命令構造全程 escapeshellarg + int cast PID;HttpClient 協議黑名單 + SSL 驗證預設開;`proc_open` 數組形式免 shell;零執行期依賴 = 供應鏈 CVE 面≈0;dev 62 包全當前版本。

**總評:** 原語級安全好於多數 PHP 專案;但 S1-S3 是「每個下游應用繼承的框架級缺口」，其中 S3 直接推翻 README 對 bridge 安全的暗示。端到端/public HTTP 面未發現現成可利用鏈 (最重的鏈需要惡意 registry/MITM 或開發者誤用) — 但這不降低修復優先級。

---

## 8. 隔離 (Isolation) 成熟度

### 8.1 關鍵取證: 已發布的隔離修復被撤回 4/5
v1.0.1-beta changelog 宣稱修復 5 個跨廠商衝突向量 (`acme/logger` vs `beta/logger` 同包名)。git 取證顯示:
- `fad2d8d` (2026-02-27) 確實實現全部 5 項 (以完整 `vendor/package` 鍵控)
- 發布 commit `f0811a4` (「v1.0.2-beta — security audit…」) 把其中 **4 項退回** alias/short-name 鍵控: 配置路徑 (`Module.php:1027`)、資產 URL (`ModuleInfo.php:531`)、閉包前綴 (`ClosureLoader.php:125`)、rewrite 去重鍵 (`CaddyfileCompiler.php:216`、`RewriteRuleCompiler.php:251`)
- 僅存 1 項: API 重複註冊丟異常 (`ModuleRegistry.php:166-181`，親自驗證)
- 退回原因註記為「配合 .htaccess rewrite 規則」— 是相容性取捨，非事故，但**無後續 commit 重新修復**

→ 今天 README/changelog 仍按 v1.0.1 敘事宣稱隔離，**實際代碼只在 API 註冊一點上結構性強制**。

### 8.2 四層成熟度評級
| 層級 | 狀態 | 證據 |
|---|---|---|
| **命名級 (naming)** | ⚠️ 現狀 = 常規約定為主 | 上述 4/5 撤回;數據路徑按 `{domain}-{distCode}` 目錄劃分 = 文件系統命名作用域，不是邊界;模組代碼僅格式正則驗證 |
| **進程級 (process)** | ✅ 部分孤島 | Phase 0 全部核實: 14 類 DI 黑名單 + parent 遍歷封鎖 (`Container.php:135,164-173,185-190`)、worker dispatch 守衛 (`Distributor.php:322-342` 等)、boot-once + 每請求復位清單。`ThreadManager` 給真 OS 進程隔離 (管道 IPC + 環境變數屏蔽)。但: 同 worker 內所有 Distributor 共享 class table/OPcache;`WorkerLifecycleManager` 未接入 `main.php` (僅自身引用)，Strategy B/C 為 stub;信號檔有孤兒 `.claimed.{pid}` 丟信號競態 |
| **OS 級** | ❌ 純文檔 | `open_basedir` 在 src/ 與全部鏡像中 **0 出現** (僅 `Distributor.php:426` 防禦性查 `disable_functions` 決定能否 spawn);硬化租戶鏡像只存在 `architecture/ENTERPRISE-TENANT-ISOLATION.md:910-934` 的代碼片段 |
| **集群級 (K8s)** | ❌ 純文檔 | 全倉 `git ls-files` 無任何 Helm/K8s manifest;Namespace/NetworkPolicy/Deployment YAML 僅存在設計文檔 §10;文檔給 K8s 探針用的 `/_razy/health` 端點**在代碼中不存在** (唯一提及是 `PackageTrait.php:165` docblock) — 照文檔上 K8s 會探針 404 重啟循環 |

### 8.3 路線圖 vs 代碼 (Phase 0-5)
`plugTenant` / `TenantEmitter` / `DataRequest` / `__onTenantCall` / tenant CLI 命令 / K8s 模板: **src/ 中全部 0 命中**。27 個 terminal 命令中無 `tenant`。即 Phase 1-5 (v1.1.0-beta→v2.0.0) 全為設計階段。`razy-hive` (Go) 亦為本地開發佔位 (多數子命令 TODO、supervisor.go 空)，非 K8s 編排器。

**評級: 命名級 + 進程級孤島。設計文檔 (1,860 行，威脅模型與三層隔離策略寫得認真) 與現實之間是整個 Phase 1-5 的距離。**

---

## 9. Docker / Kubernetes 支持

**現存資產:** 多階段 builder→runtime 官方鏡像 (結構正確: `--no-dev`、密鑰不入鏡像、env 驅動配置)、dev compose (PHP+Caddy 反代)、測試 compose (Redis 帶 healthcheck、PECL redis+ssh2)、DevContainer、benchmark 鏡像對 (FrankenPHP worker vs Octane/Swoole，資源上限對等)。

**缺口清單 (對照其 Phase 2/4 承諾):**
1. 所有鏡像 root 運行、無 healthcheck、浮動 base tag、無 open_basedir/disable_functions
2. 發行鏡像 CMD = `php -S` 開發服務器 (§7.2 S)
3. 無租戶鏡像 / 無 `docker-compose.tenant.yml` / 無每租戶配置生成器
4. 無 Helm chart、無 K8s manifest、無 PVC/Namespace/NetworkPolicy 資產 (全為文檔片段)
5. 探針端點 `/_razy/health` 未實作;最接近的真實端點 `/.razy/status` 只存在開發服務器腳本 (`src/asset/setup/serve.php:120-138`)，FrankenPHP/Caddy 路徑下不存在
6. 優雅關機委託給 FrankenPHP Go runtime (刻意不註冊 pcntl 信號 — 合理設計但意味無 drain 協調);非工作器路徑有 shutdown 驗證
7. razy-hive 無 K8s 感知 (對 K8s 而言本地 supervisor 本就多餘)

**K8s 就緒最小集 (若真要啟動 Phase 4):** 先實作 `/_razy/health` (連同 `__onPackageHealthcheck`) → 非 root + digest 釘選鏡像 → Dockerfile.tenant (open_basedir+disable_functions+只讀根) → 租戶 compose 模板 → 健康探針/資源 request/limit → NetworkPolicy 拒絕租戶互訪 → 最後才是 Helm。

---

## 10. 分優先級行動清單

**P0 (安全，立即):**
1. 模板默認轉義 + `|raw` + 內建 escape 修飾符 (S1)
2. 兩處解壓加條目名校驗 + realpath 包含 + HTTPS-only + 包名正則 + 0700 (S2)
3. Bridge 預設拒絕 + HMAC 簽名/nonce;CLI bridge 走權限閘 (S3)
4. `getSearchTextSyntax` 改綁定參數;Table.php 標識符改 `quoteIdentifier`
5. PHPStan 恢復 PackageManager 掃描 (它正是 S2 所在處)，收斂 ignoreErrors

**P1 (誠實性/信任):**
6. 重新應用或文檔化聲明那 4 項被撤回的衝突修復 (§8.1)，README 隔離章節改為與代碼現實一致
7. 修 benchmark 對稱性 (端點走真模板、連接池對齊、補 FPM 基線、釘工具鏈) 並重跑;提交 Laravel scenario 1-4 原始數據
8. README 測試/類別/版本數字由 CI 工件自動生成，統一版本字符串

**P2 (工程):**
9. 官方鏡像非 root + healthcheck + 真服務器 (FrankenPHP) CMD
10. 刪根目錄幽靈檔、收斂文檔樹 (docs|documentation 二選一)、刪舊 fixer 配置
11. 拆分 Model.php/Module.php/pkg.inc.php;src/system 全局函數收進命名空間
12. DI 黑名單轉白名單、修 `getContainer()` 回退、實作 `/_razy/health`
13. 24h soak + 冷啟動 + 洩漏曲線入 benchmark 協議;CI 裝齊擴展消滅 87 跳過

---

## 附錄: 證據索引 (核心)
- 測試實跑: `php vendor/bin/phpunit` → `Tests: 4845, Assertions: 8672, Warnings: 2, Skipped: 87`
- 撤回取證: `git log` — `fad2d8d` (fix 5 vectors) vs `f0811a4` (revert 4/5);現行鍵控見 `Module.php:1027`、`ModuleInfo.php:531`、`ClosureLoader.php:125-126`、`CaddyfileCompiler.php:216`
- zip-slip: `PackageManager.php:276-285`、`RepoInstaller.php:691-717`
- 模板轉義: `Template/Entity.php:383-400`;修飾符清單 `src/plugins/Template/` (9 個，無 escape)
- Bridge: `Controller.php:187-190` (預設 true)、`CommandRegistry.php:144-153` (跳權限)、全 src 無 bridge HMAC
- 加密正面: `Crypt.php:56-63,86-98`、`Authenticator.php:232,277`、`CsrfTokenManager.php:104,163`、`Session.php:326`
- Phase 0 實存: `Container.php:135,164-173,185-190`、`Module.php:1139-1154` (14 類)、`Distributor.php:322-342`、`main.php:116-238` (boot-once)
- Phase 1-5 空缺: `plugTenant|TenantEmitter|__onTenantCall|open_basedir` 於 src/ 0 命中;K8s/Helm 全倉 0 檔案;`/_razy/health` 無路由註冊
- benchmark 對稱缺陷: `benchmark/razy/standalone/controller/app.php:43,170` (str_repeat + ATTR_PERSISTENT)、`benchmark/results/` 中 Laravel scenario 1-4 無工件
- 版本漂移: `VERSION`=1.0.2-beta vs `composer.json`=1.0.3-beta vs benchmark 報告「v0.5」
