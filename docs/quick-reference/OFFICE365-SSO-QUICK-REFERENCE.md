# Office 365 SSO Quick Reference

Quick guide for implementing Microsoft Office 365 Single Sign-On. See full documentation: [OFFICE365-SSO.md](OFFICE365-SSO.md)

## Setup (5 Minutes)

### 1. Azure AD Registration

1. Go to [Azure Portal](https://portal.azure.com) → **App registrations** → **New registration**
2. Name: `My App`
3. Account types: **Multitenant and personal accounts**
4. Redirect URI: `https://yoursite.com/auth/callback`
5. **Register** → Copy **Application (client) ID** and **Directory (tenant) ID**
6. **Certificates & secrets** → **New client secret** → Copy **Value**
7. **API permissions** → **Add permission** → Microsoft Graph → Delegated:
   - `User.Read`, `openid`, `profile`, `email`

### 2. Controller Code

```php
use Razy\Office365SSO;

return new class($module) extends Controller {
    private Office365SSO $sso;
    
    public function __onInit(Agent $agent): bool {
        $this->sso = new Office365SSO(
            'YOUR_CLIENT_ID',
            'YOUR_CLIENT_SECRET',
            'https://yoursite.com/auth/callback',
            'YOUR_TENANT_ID' // or 'common'
        );
        
        $agent->addRoute('login', [$this, 'login']);
        $agent->addRoute('callback', [$this, 'callback']);
        $agent->addRoute('profile', [$this, 'profile']);
        
        return true;
    }
    
    public function login(): void {
        session_start();
        $this->sso->setState();
        $_SESSION['oauth_state'] = $this->sso->getState();
        header('Location: ' . $this->sso->getAuthorizationUrl());
        exit;
    }
    
    public function callback(): void {
        session_start();
        
        if (!OAuth2::validateState($_GET['state'], $_SESSION['oauth_state'])) {
            die('Invalid state');
        }
        
        $tokenData = $this->sso->getAccessToken($_GET['code']);
        $userInfo = $this->sso->getUserInfo($tokenData['access_token']);
        $_SESSION['user'] = $this->sso->createSession($tokenData, $userInfo);
        
        header('Location: /profile');
        exit;
    }
    
    public function profile(): void {
        session_start();
        if (!isset($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
        echo "Welcome, " . $_SESSION['user']['display_name'];
    }
};
```

## Common Configuration

### Scopes

```php
// Read user profile (default)
$sso->setGraphScope('User.Read');

// Read emails
$sso->setGraphScope('User.Read Mail.Read');

// Access calendar
$sso->setGraphScope('User.Read Calendars.Read');

// OneDrive files
$sso->setGraphScope('User.Read Files.Read.All');

// Offline access (refresh tokens)
$sso->setGraphScope('User.Read offline_access');
```

### Tenant Types

```php
// Single tenant (your organization only)
tenantId: 'your-tenant-id'

// Multi-tenant (any organization + personal)
tenantId: 'common'

// Only organizations
tenantId: 'organizations'

// Only personal Microsoft accounts
tenantId: 'consumers'
```

### Login Options

```php
// Always show account picker
$sso->setPrompt('select_account');

// Force re-authentication
$sso->setPrompt('login');

// Pre-fill email
$sso->setLoginHint('user@company.com');

// Skip tenant selection
$sso->setDomainHint('company.com');
```

## API Methods

### Authentication

| Method | Returns | Description |
|--------|---------|-------------|
| `getAuthorizationUrl()` | `string` | Login URL |
| `getAccessToken($code)` | `array` | Exchange code for token |
| `refreshAccessToken($token)` | `array` | Refresh expired token |

### User Info

| Method | Returns | Description |
|--------|---------|-------------|
| `getUserInfo($token)` | `array` | Full profile |
| `getUserEmail()` | `?string` | Email address |
| `getUserDisplayName()` | `?string` | Display name |
| `getUserGivenName()` | `?string` | First name |
| `getUserSurname()` | `?string` | Last name |
| `getUserJobTitle()` | `?string` | Job title |
| `getUserPhoto($token)` | `?string` | Profile photo binary |

### Session

| Method | Returns | Description |
|--------|---------|-------------|
| `createSession($tokens, $user)` | `array` | Create session data |
| `isSessionExpired($session)` | `bool` | Check expiration |
| `getSignOutUrl($idToken)` | `string` | Sign-out URL |

### Validation

| Method | Returns | Description |
|--------|---------|-------------|
| `validateState($a, $b)` | `bool` | CSRF check |
| `validateIdToken($claims)` | `bool` | Validate ID token |
| `parseJWT($jwt)` | `array` | Decode JWT |

## Session Structure

```php
$_SESSION['user'] = [
    'user_id' => 'abc123...',
    'email' => 'user@company.com',
    'display_name' => 'John Doe',
    'given_name' => 'John',
    'surname' => 'Doe',
    'job_title' => 'Engineer',
    'access_token' => 'eyJ0eXAi...',
    'refresh_token' => 'M.R3_BAY...',
    'expires_at' => 1640000000,
    'tenant_id' => 'xyz789...',
];
```

## Graph API Examples

### Get Emails

```php
$url = 'https://graph.microsoft.com/v1.0/me/messages?$top=10';
$emails = $sso->httpGet($url, $user['access_token']);
```

### Get Calendar

```php
$url = 'https://graph.microsoft.com/v1.0/me/events';
$events = $sso->httpGet($url, $user['access_token']);
```

### Get Files

```php
$url = 'https://graph.microsoft.com/v1.0/me/drive/root/children';
$files = $sso->httpGet($url, $user['access_token']);
```

## Token Refresh

```php
if (Office365SSO::isSessionExpired($_SESSION['user'])) {
    $tokenData = $sso->refreshAccessToken($_SESSION['user']['refresh_token']);
    $userInfo = $sso->getUserInfo($tokenData['access_token']);
    $_SESSION['user'] = $sso->createSession($tokenData, $userInfo);
}
```

## Security Checklist

- [x] ✅ Validate state token (CSRF protection)
- [x] ✅ Use HTTPS only
- [x] ✅ Store secrets in environment variables
- [x] ✅ Check token expiration
- [x] ✅ Validate ID token claims
- [x] ✅ Request minimum scopes needed
- [x] ✅ Clear session on logout

## Common Errors

| Error | Solution |
|-------|----------|
| **AADSTS50011** (Reply URL mismatch) | Add exact redirect URI in Azure Portal |
| **AADSTS70011** (Invalid scope) | Use spaces in scope: `User.Read Mail.Read` |
| **AADSTS700016** (App not found) | Verify client ID |
| **AADSTS7000215** (Invalid secret) | Generate new secret in Azure Portal |
| **Invalid state token** | Start session before generating state |
| **No refresh token** | Add `offline_access` scope |

## Environment Variables

```bash
# .env or system environment
AZURE_CLIENT_ID=abc123-def456-ghi789
AZURE_CLIENT_SECRET=your-secret-value-here
AZURE_TENANT_ID=xyz789-abc123-def456
AZURE_REDIRECT_URI=https://yoursite.com/auth/callback
```

```php
// Load from environment
$sso = new Office365SSO(
    getenv('AZURE_CLIENT_ID'),
    getenv('AZURE_CLIENT_SECRET'),
    getenv('AZURE_REDIRECT_URI'),
    getenv('AZURE_TENANT_ID')
);
```

## Database Integration

```php
// Save user on first login
private function saveUser(array $session): void {
    $stmt = $this->prepare('users');
    $stmt->where('microsoft_id', $session['user_id']);
    
    if (!$this->query($stmt)->fetch()) {
        $this->execute($stmt, [
            'microsoft_id' => $session['user_id'],
            'email' => $session['email'],
            'name' => $session['display_name'],
            'created_at' => date('Y-m-d H:i:s'),
        ], 'insert');
    }
}
```

## Worker Mode

```php
public function __onInit(Agent $agent): bool {
    // Always recreate SSO instance in worker mode
    $this->sso = new Office365SSO(
        getenv('AZURE_CLIENT_ID'),
        getenv('AZURE_CLIENT_SECRET'),
        getenv('AZURE_REDIRECT_URI'),
        getenv('AZURE_TENANT_ID')
    );
    
    // Always start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return true;
}
```

## Full Flow Diagram

```
1. User → /login
   ↓
2. Generate state token, save to session
   ↓
3. Redirect to Microsoft login page
   ↓
4. User authenticates with Microsoft
   ↓
5. Microsoft → /callback?code=...&state=...
   ↓
6. Validate state token
   ↓
7. Exchange code for access token
   ↓
8. Get user info from Microsoft Graph
   ↓
9. Create session, save user data
   ↓
10. Redirect to /profile
```

## Production Checklist

- [ ] Register app in Azure Portal
- [ ] Configure redirect URIs
- [ ] Add required API permissions
- [ ] Store secrets securely (env vars)
- [ ] Enable HTTPS
- [ ] Implement token refresh
- [ ] Add error handling
- [ ] Test multi-tenant scenarios
- [ ] Implement proper sign-out
- [ ] Monitor token expiration
- [ ] Add logging for auth events

## See Also

- Full guide: [OFFICE365-SSO.md](OFFICE365-SSO.md)
- Azure Portal: https://portal.azure.com
- Microsoft Graph: https://developer.microsoft.com/graph
- OAuth 2.0 spec: https://oauth.net/2/
