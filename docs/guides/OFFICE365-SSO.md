# Office 365 SSO Integration

Razy includes built-in support for **Office 365 / Microsoft Entra ID (Azure AD)** Single Sign-On using OAuth 2.0 and OpenID Connect.

## Features

✅ **OAuth 2.0 / OpenID Connect** - Standards-compliant authentication  
✅ **Microsoft Graph API** - Access user profile, email, calendar, files  
✅ **Multi-tenant Support** - Work/School and Personal Microsoft accounts  
✅ **Token Management** - Automatic refresh with refresh tokens  
✅ **Session Handling** - Secure session creation and validation  
✅ **Profile Photos** - Retrieve user profile pictures  
✅ **Zero Dependencies** - No external libraries required  

## Quick Start

### 1. Azure AD Setup

**Register Application:**
1. Go to [Azure Portal](https://portal.azure.com) → Azure Active Directory → App registrations
2. Click **New registration**
3. Enter application name: `My Razy App`
4. Select account types: **Accounts in any organizational directory and personal Microsoft accounts**
5. Add Redirect URI: `https://yoursite.com/auth/callback`
6. Click **Register**

**Get Credentials:**
1. Copy **Application (client) ID** → This is your `$clientId`
2. Copy **Directory (tenant) ID** → This is your `$tenantId`
3. Go to **Certificates & secrets** → New client secret
4. Copy secret **Value** → This is your `$clientSecret`

**Configure Permissions:**
1. Go to **API permissions** → Add a permission → Microsoft Graph
2. Add **Delegated permissions**:
   - `User.Read` (Read user profile)
   - `openid`, `profile`, `email` (Sign in and read basic profile)
3. Optional: Add more scopes like `Mail.Read`, `Calendars.Read`, `Files.Read.All`

### 2. Controller Implementation

```php
<?php
// In your module's controller.inc.php

use Razy\Office365SSO;

return new class($module) extends Controller {
    private Office365SSO $sso;
    
    public function __onInit(Agent $agent): bool {
        // Initialize Office 365 SSO
        $this->sso = new Office365SSO(
            clientId: 'your-client-id-here',
            clientSecret: 'your-client-secret-here',
            redirectUri: 'https://yoursite.com/auth/callback',
            tenantId: 'your-tenant-id-here' // or 'common' for multi-tenant
        );
        
        // Routes
        $agent->addRoute('login', [$this, 'login']);
        $agent->addRoute('callback', [$this, 'callback']);
        $agent->addRoute('profile', [$this, 'profile']);
        $agent->addRoute('logout', [$this, 'logout']);
        
        return true;
    }
    
    public function login(): void {
        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Generate state token for CSRF protection
        $this->sso->setState();
        $_SESSION['oauth_state'] = $this->sso->getState();
        
        // Optional: Add login hint or domain
        // $this->sso->setLoginHint('user@company.com');
        // $this->sso->setDomainHint('company.com');
        
        // Redirect to Microsoft login
        header('Location: ' . $this->sso->getAuthorizationUrl());
        exit;
    }
    
    public function callback(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Validate state (CSRF protection)
        $state = $_GET['state'] ?? '';
        $expectedState = $_SESSION['oauth_state'] ?? '';
        
        if (!Office365SSO::validateState($state, $expectedState)) {
            die('Invalid state token');
        }
        
        // Check for errors
        if (isset($_GET['error'])) {
            die('Authentication error: ' . $_GET['error_description']);
        }
        
        // Exchange code for tokens
        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            die('No authorization code received');
        }
        
        try {
            // Get access token
            $tokenData = $this->sso->getAccessToken($code);
            
            // Get user info from Microsoft Graph
            $userInfo = $this->sso->getUserInfo($tokenData['access_token']);
            
            // Create session
            $session = $this->sso->createSession($tokenData, $userInfo);
            $_SESSION['user'] = $session;
            
            // Optional: Save to database
            // $this->saveUserToDatabase($session);
            
            // Redirect to app
            header('Location: /profile');
            exit;
            
        } catch (\\Exception $e) {
            die('Login failed: ' . $e->getMessage());
        }
    }
    
    public function profile(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
        
        $user = $_SESSION['user'];
        
        // Check if token expired
        if (Office365SSO::isSessionExpired($user)) {
            // Refresh token
            try {
                $tokenData = $this->sso->refreshAccessToken($user['refresh_token']);
                $userInfo = $this->sso->getUserInfo($tokenData['access_token']);
                $_SESSION['user'] = $this->sso->createSession($tokenData, $userInfo);
                $user = $_SESSION['user'];
            } catch (\\Exception $e) {
                // Refresh failed, require re-login
                unset($_SESSION['user']);
                header('Location: /login');
                exit;
            }
        }
        
        // Render profile
        $tpl = $this->prepareTemplate('profile');
        $tpl->assign('user', $user);
        echo $tpl->render();
    }
    
    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $idToken = $_SESSION['user']['id_token_claims']['raw'] ?? null;
        
        // Clear session
        session_destroy();
        
        // Redirect to Microsoft sign-out
        header('Location: ' . $this->sso->getSignOutUrl($idToken));
        exit;
    }
};
```

### 3. Template Example

```html
<!-- profile.tpl -->
<div class="profile">
    <h1>Welcome, {$user.display_name}!</h1>
    
    <div class="profile-info">
        <p><strong>Email:</strong> {$user.email}</p>
        <p><strong>Job Title:</strong> {$user.job_title}</p>
        <p><strong>Office:</strong> {$user.office_location}</p>
        <p><strong>User ID:</strong> {$user.user_id}</p>
    </div>
    
    <a href="/logout">Sign Out</a>
</div>
```

## Advanced Configuration

### Custom Scopes

```php
// Read user's email
$sso->setGraphScope('User.Read Mail.Read');

// Access calendar
$sso->setGraphScope('User.Read Calendars.Read');

// Access OneDrive files
$sso->setGraphScope('User.Read Files.Read.All');

// Multiple scopes
$sso->setGraphScope('User.Read Mail.Read Calendars.Read Files.Read.All');
```

### User Photo

```php
public function getPhoto(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        http_response_code(401);
        exit;
    }
    
    try {
        $photo = $this->sso->getUserPhoto($user['access_token']);
        
        if ($photo) {
            header('Content-Type: image/jpeg');
            echo $photo;
        } else {
            // Return default avatar
            header('Content-Type: image/svg+xml');
            echo '<svg>...</svg>';
        }
    } catch (\\Exception $e) {
        http_response_code(404);
    }
}
```

### Tenant-Specific Authentication

```php
// Single tenant (only your organization)
$sso = new Office365SSO(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    tenantId: 'your-tenant-id' // Specific tenant
);

// Multi-tenant (any organization + personal accounts)
$sso = new Office365SSO(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    tenantId: 'common'
);

// Only organizational accounts
$sso = new Office365SSO(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    tenantId: 'organizations'
);

// Only personal Microsoft accounts
$sso = new Office365SSO(
    clientId: $clientId,
    clientSecret: $clientSecret,
    redirectUri: $redirectUri,
    tenantId: 'consumers'
);
```

### Prompt Behavior

```php
// Always show account picker
$sso->setPrompt('select_account');

// Force re-authentication
$sso->setPrompt('login');

// Request consent again
$sso->setPrompt('consent');

// Silent authentication (no UI)
$sso->setPrompt('none');
```

### Domain Hint

```php
// Skip tenant selection for corporate users
$sso->setDomainHint('contoso.com');

// Pre-fill email domain
$sso->setLoginHint('user@contoso.com');
```

## API Reference

### Office365SSO Class

#### Constructor

```php
new Office365SSO(
    string $clientId,        // Azure AD Application ID
    string $clientSecret,    // Azure AD Client secret
    string $redirectUri,     // Callback URL
    string $tenantId = 'common'  // Tenant ID or 'common'
)
```

#### Methods

**Authentication:**
- `getAuthorizationUrl(): string` - Get login URL
- `getAccessToken(string $code): array` - Exchange code for token
- `refreshAccessToken(string $refreshToken): array` - Refresh token

**Configuration:**
- `setGraphScope(string $scope): self` - Set Microsoft Graph scopes
- `setPrompt(string $prompt): self` - Set prompt behavior
- `setDomainHint(string $domain): self` - Add domain hint
- `setLoginHint(string $email): self` - Pre-fill email
- `setState(?string $state): self` - Set state token
- `getState(): ?string` - Get state token

**User Data:**
- `getUserInfo(string $accessToken): array` - Get user profile
- `getUserPhoto(string $accessToken): ?string` - Get profile photo
- `getUserEmail(): ?string` - Get email
- `getUserDisplayName(): ?string` - Get display name
- `getUserGivenName(): ?string` - Get first name
- `getUserSurname(): ?string` - Get last name
- `getUserJobTitle(): ?string` - Get job title
- `getUserOfficeLocation(): ?string` - Get office
- `getUserMobilePhone(): ?string` - Get mobile
- `getUserBusinessPhones(): array` - Get business phones
- `getUserId(): ?string` - Get unique ID

**Token Management:**
- `parseIdToken(string $idToken): array` - Parse ID token
- `validateIdToken(array $claims): bool` - Validate ID token
- `createSession(array $tokenData, ?array $userInfo): array` - Create session
- `isSessionExpired(array $session): bool` - Check expiration

**Sign Out:**
- `getSignOutUrl(?string $idToken): string` - Get sign-out URL

**Static Methods:**
- `OAuth2::validateState(string $received, string $expected): bool` - CSRF check
- `OAuth2::parseJWT(string $jwt): array` - Parse JWT
- `OAuth2::isJWTExpired(array $claims): bool` - Check JWT expiry

## Session Structure

```php
$_SESSION['user'] = [
    'user_id' => 'abc123...',           // Microsoft Object ID
    'email' => 'user@company.com',
    'display_name' => 'John Doe',
    'given_name' => 'John',
    'surname' => 'Doe',
    'job_title' => 'Software Engineer',
    'office_location' => 'Building 1',
    'access_token' => 'eyJ0eXAi...',    // For API calls
    'refresh_token' => 'M.R3_BAY...',   // For token refresh
    'expires_at' => 1640000000,         // Unix timestamp
    'id_token_claims' => [...],         // Parsed ID token
    'tenant_id' => 'xyz789...',         // Azure AD tenant
    'login_time' => 1639900000,
];
```

## Security Best Practices

### 1. Always Validate State Token

```php
// Generate and store
$sso->setState();
$_SESSION['oauth_state'] = $sso->getState();

// Validate on callback
if (!OAuth2::validateState($_GET['state'], $_SESSION['oauth_state'])) {
    die('CSRF attack detected');
}
unset($_SESSION['oauth_state']);
```

### 2. Store Secrets Securely

```php
// ❌ DON'T hardcode secrets
$secret = 'abc123def456';

// ✅ DO use environment variables
$secret = getenv('AZURE_CLIENT_SECRET');

// ✅ DO use configuration files (outside web root)
$config = include(SYSTEM_ROOT . '/config/azure.php');
$secret = $config['client_secret'];
```

### 3. Validate ID Token

```php
$idToken = $tokenData['id_token'];
$claims = $sso->parseIdToken($idToken);

if (!$sso->validateIdToken($claims)) {
    die('Invalid ID token');
}
```

### 4. Check Token Expiration

```php
if (Office365SSO::isSessionExpired($_SESSION['user'])) {
    // Refresh or re-authenticate
}
```

### 5. Use HTTPS Only

```php
// Enforce HTTPS
if (!is_ssl()) {
    die('HTTPS required for OAuth');
}
```

### 6. Limit Scopes

```php
// ✅ Request only what you need
$sso->setGraphScope('User.Read');

// ❌ Don't request excessive permissions
$sso->setGraphScope('User.Read Mail.ReadWrite Files.ReadWrite.All Directory.ReadWrite.All');
```

## Microsoft Graph API Examples

### Get User's Emails

```php
public function getEmails(): array {
    $user = $_SESSION['user'];
    $url = 'https://graph.microsoft.com/v1.0/me/messages?$top=10';
    
    return $this->sso->httpGet($url, $user['access_token']);
}
```

### Get Calendar Events

```php
public function getCalendar(): array {
    $user = $_SESSION['user'];
    $url = 'https://graph.microsoft.com/v1.0/me/events?$top=20';
    
    return $this->sso->httpGet($url, $user['access_token']);
}
```

### Get OneDrive Files

```php
public function getFiles(): array {
    $user = $_SESSION['user'];
    $url = 'https://graph.microsoft.com/v1.0/me/drive/root/children';
    
    return $this->sso->httpGet($url, $user['access_token']);
}
```

### Send Email

```php
public function sendEmail(string $to, string $subject, string $body): void {
    $user = $_SESSION['user'];
    $url = 'https://graph.microsoft.com/v1.0/me/sendMail';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $user['access_token'],
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'Text',
                'content' => $body,
            ],
            'toRecipients' => [
                ['emailAddress' => ['address' => $to]],
            ],
        ],
    ]));
    
    curl_exec($ch);
    curl_close($ch);
}
```

## Worker Mode Considerations

When using Office 365 SSO with Caddy/FrankenPHP worker mode:

```php
public function __onInit(Agent $agent): bool {
    // Reset SSO instance for each request
    $this->sso = new Office365SSO(
        clientId: getenv('AZURE_CLIENT_ID'),
        clientSecret: getenv('AZURE_CLIENT_SECRET'),
        redirectUri: SITE_URL_ROOT . '/auth/callback',
        tenantId: getenv('AZURE_TENANT_ID')
    );
    
    // Always start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return true;
}
```

## Troubleshooting

### Error: "AADSTS50011: The reply URL specified in the request does not match"

**Solution:** Add exact redirect URI in Azure Portal → App registrations → Authentication

### Error: "AADSTS70011: The provided value for the input parameter 'scope' is not valid"

**Solution:** Check scope format, use spaces: `User.Read Mail.Read` not commas

### Error: "AADSTS700016: Application not found"

**Solution:** Verify client ID is correct and app is registered

### Error: "AADSTS7000215: Invalid client secret"

**Solution:** Generate new client secret, copy immediately (shown once!)

### Error: "Invalid state token"

**Solution:** Ensure session is started before generating state token

### Token Refresh Fails

**Solution:** Check if refresh token was included in initial token response:
```php
$sso->setGraphScope('User.Read offline_access'); // Add offline_access
```

## Database Integration

### Save User to Database

```php
private function saveUserToDatabase(array $session): void {
    $stmt = $this->prepare('users');
    $stmt->where('microsoft_id', $session['user_id']);
    $existing = $this->query($stmt)->fetch();
    
    if ($existing) {
        // Update existing user
        $stmt = $this->prepare('users');
        $stmt->where('microsoft_id', $session['user_id']);
        $this->execute($stmt, [
            'email' => $session['email'],
            'display_name' => $session['display_name'],
            'last_login' => date('Y-m-d H:i:s'),
        ], 'update');
    } else {
        // Create new user
        $stmt = $this->prepare('users');
        $this->execute($stmt, [
            'microsoft_id' => $session['user_id'],
            'email' => $session['email'],
            'display_name' => $session['display_name'],
            'created_at' => date('Y-m-d H:i:s'),
            'last_login' => date('Y-m-d H:i:s'),
        ], 'insert');
    }
}
```

## See Also

- [Microsoft Identity Platform](https://docs.microsoft.com/en-us/azure/active-directory/develop/)
- [Microsoft Graph API](https://docs.microsoft.com/en-us/graph/overview)
- [OAuth 2.0 Specification](https://oauth.net/2/)
- [OpenID Connect](https://openid.net/connect/)
- [Razy Session Management](usage/Razy.Configuration.md)
- [Worker Mode Best Practices](CADDY-WORKER-MODE.md)
