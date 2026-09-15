# 09 — Social Login（razymod/oauth）

> 代碼實查 2026-09。決策記錄：`architecture/OAUTH-SOCIALITE-HTTP.md`（Q1–Q5 全數簽核）。
> 協議核心在框架（`Razy\Security\OAuth\*`，S2/S3），本模組只管**路由與每 dist 配置**（S5）。

## 1. 邊界（先讀這個）

簽核 Q1 把界線釘死：

- 框架與本模組**都不建 users 表、都不寫 session**。回呼完成後只發一個事件
  `social.user_resolved`，身份落不落地、怎麼落地，是**你的 app** 的 listener 的事
  （通常接 `Razy\Auth\SessionGuard`，見 `manual/07-security-guide.md` §6）。
- 模組**沒有 migration**（`package.php` 刻意無 `migration` 鍵）。看到這個模組想要
  儲存，就是越界訊號。

## 2. 安裝與配置

1. 模組放 `modules/oauth/`，對目標 dist 啟用。
2. 每個 provider 的憑證放 **env**，config 只放**變數名稱**（Q5，與
   `RAZY_BRIDGE_SECRET` 同紀律）：

```php
// config/<dist>/oauth.php
return [
    'post_login_redirect' => 'https://shop.example/account',
    'providers' => [
        'github' => [
            'enabled'           => true,
            'client_id_env'     => 'OAUTH_GITHUB_ID',
            'client_secret_env' => 'OAUTH_GITHUB_SECRET',
            'redirect_uri'      => 'https://shop.example/razymod/oauth/callback?provider=github',
            // 'scopes' => 'read:user user:email',   // provider 預設已對
        ],
        'google' => [
            'enabled'           => true,
            'client_id_env'     => 'OAUTH_GOOGLE_ID',
            'client_secret_env' => 'OAUTH_GOOGLE_SECRET',
            'redirect_uri'      => 'https://shop.example/razymod/oauth/callback?provider=google',
            // 'hosted_domain'  => 'corp.example',   // Workspace 限域（參數+CLAIM 雙驗）
            // 'prompt_consent' => true,             // 重取 refresh_token 用
        ],
        'microsoft' => [
            'enabled'           => true,
            'client_id_env'     => 'OAUTH_MS_ID',
            'client_secret_env' => 'OAUTH_MS_SECRET',
            'redirect_uri'      => 'https://shop.example/razymod/oauth/callback?provider=microsoft',
            // 'tenant' => 'contoso.onmicrosoft.com', // 預設 'common'
        ],
    ],
];
```

3. **必要 env**：`RAZY_OAUTH_STATE_SECRET`（state 簽名密鑰）。沒有預設值——缺了就
   每次 login 直接 fail-loud，這是特性不是 bug（Q2：拒絕靜默弱化）。
4. 把各 provider 後台登记的 redirect_uri **逐字**填進 config——核心做精確比對，
   永遠不从請求推导（§7 step 5）。

## 3. 流程（誰做什麼）

```
瀏覽器 → /<alias>/authorize?provider=github
  模組：flow->begin() → 產生 code_verifier（服务器端 Cache 保管）
        + 簽名單次 state（HMAC，綁 provider/redirect_uri/時限）
  → 302 到 GitHub（注意：不是 Controller::goto，那個是 301）

用戶在 provider 同意 → provider 302 回 /<alias>/callback?code=…&state=…
  模組：flow->exchange()
        1. state 驗證（簽名/时效/provider/redirect 綁定）——在**任何網路請求之前**
        2. provider 錯誤參數 → OAuthException（RFC 6749 §5.2，驗證後才映射）
        3. nonce 贖回＝單次消耗（重放必死）；code_verifier 由服務端取出
        4. POST token 端點（PKCE + 精確 redirect_uri；GitHub 的 urlencoded
           預設回覆原生支援）
  → provider->fetchUser()（GitHub: id；Google: **sub 不是 email**；Entra: Graph object id）
  → id_token（若有）做結構驗證：aud/exp/iss(/hd)——**簽章不驗**，見 §5
  → $this->trigger('social.user_resolved')->resolve($payload)
  → 302 到 post_login_redirect
```

## 4. `social.user_resolved` 事件契約

```php
// 你的 app 模組，__onInit 裡：
$agent->listen('social.user_resolved', function (array $payload) {
    // $payload = [
    //   'provider'        => 'github',
    //   'user'            => ['id'=>..., 'name'=>..., 'email'=>..., 'avatar'=>..., 'raw'=>[...]],
    //   'tokens'          => ['access_token'=>..., 'refresh_token'=>..., 'expires_at'=>int],
    //   'id_token_claims' => ?array,   // 結構已驗、簽章未驗（G11）
    // ];
    // 你在這裡建立/查找自己的身份列，決定怎麼持久化（SessionGuard 只存識別碼）。
});
```

注意：payload 含 tokens，監聽者等於拿到該用戶的 provider 權限面——監聽註冊本身就是
信任決策。不需要 tokens 的 listener 請忽略該鍵。

## 5. 安全紀律（核心代碼強制，非習俗）

- **PKCE S256 恆開**：`plain` 不產生也不接受；refresh 不带 PKCE（RFC 8707）。
- **state 簽名單次**（Q2 方案 C）：session cookie 在本框架的 web 路由不可依賴，
  所以 state 自保＋Cache 單次贖回。verifier 從不進瀏覽器。
- **302 不是 301**：授權跳轉被快取會造成跨帳號事故。
- **id_token 誠實標籤**（G11）：`OAuth2::verifyIdTokenClaims` 驗結構
  （aud 精確、絕對 exp、iss 正規式、可選 nonce/hd），**永不驗簽章**
  （JWK 拉取/輪換/驗證整組在 Do-NOT-build 清單）。文件與錯誤訊息只能講
  "claims checked"，說 "signature verified" 是瀆職。
- **身份聲明**：Google 用 `sub`（`legacy_sub` 仍認得）、Entra 用 Graph object id；
  email 一律只是聯絡資料。
- **secrets**：只住 env。config 裡出現憑證值就是 Q5 違規。

## 6. 除錯

- 「Provider "x" is not enabled」→ config 沒 enable 或 provider 名拼錯（名單：
  github / google / microsoft，未知名字装配阶段就炸）。
- 「State signature mismatch」→ `RAZY_OAUTH_STATE_SECRET` 換過、或 state 是別處來的。
- 「already used」→ 同一個回呼重放（用戶重新整理回呼頁就會）；重新點 login 即可。
- 「Token endpoint error "bad_verification_code"」等 provider 錯誤 → 原文映射進
  OAuthException，不吞。
- `php Razy.phar` 層面：本模組不走 CLI（無 API 指令之外的 surface）；公讀指令
  `api('razymod/oauth')->providers()` 回啟用的 provider 與登入 URL（無任何密鑰）。
