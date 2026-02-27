# Razy\Office365SSO

Microsoft Office 365 / Azure AD SSO authentication client. Extends OAuth2 with Microsoft-specific features and Microsoft Graph API integration.

**File**: `src/library/Razy/Office365SSO.php`

## Purpose

- Authenticate users with Office 365 / Microsoft accounts
- Integrate with Microsoft Entra ID (Azure AD)
- Access Microsoft Graph API (user profile, emails, calendar, files)
- Multi-tenant support (any organization, personal accounts)
- Session management with automatic token refresh

## Key Concepts

### Microsoft Entra ID (Azure AD)

Microsoft's OAuth 2.0 / OpenID Connect provider:
- **Tenants**: Isolated directory instances (organizations)
- **Multi-tenant**: Support multiple organizations in one app
- **ID Tokens**: JWT tokens with user identity claims

### Microsoft Graph API

Unified API for Microsoft 365 services:
- User profiles, emails, calendar, contacts
- OneDrive files and folders
- Teams, SharePoint, OneNote
- Organization data

### Tenant Types

- `common`: Any organization + personal accounts
- `organizations`: Only organizations (work/school accounts)
- `consumers`: Only personal Microsoft accounts
- `{tenant-id}`: Specific organization only

## Constructor

```php
public function __construct(
    string $clientId,
    string $clientSecret,
    string $redirectUri,
    string $tenantId = 'common'
)
```

**Parameters**:
- `$clientId`: Application (client) ID from Azure Portal
- `$clientSecret`: Client secret from Azure Portal
- `$redirectUri`: Your callback URL (must match Azure registration)
- `$tenantId`: Tenant ID, or 'common'/'organizations'/'consumers'

**Example**:
```php
// Multi-tenant (any organization + personal)
$sso = new Office365SSO(
    'abc123-def456-ghi789',
    'my-secret-value',
    'https://mysite.com/auth/callback',
    'common'
);

// Single tenant (your organization only)
$sso = new Office365SSO(
    'abc123-def456-ghi789',
    'my-secret-value',
    'https://mysite.com/auth/callback',
    'xyz789-abc123-def456'  // Your tenant ID
);
```

## Public API

### Configuration

#### `setGraphScope(string $scope): self`

Set Microsoft Graph API scopes (space-separated).

```php
// Basic profile (default)
$sso->setGraphScope('User.Read');

// Read emails
$sso->setGraphScope('User.Read Mail.Read');

// Access calendar
$sso->setGraphScope('User.Read Calendars.Read');

// OneDrive files + offline access
$sso->setGraphScope('User.Read Files.Read.All offline_access');
```

**Common scopes**:
- `User.Read`: Basic profile
- `Mail.Read`: Read emails
- `Calendars.Read`: Read calendar events
- `Files.Read.All`: Read OneDrive files
- `offline_access`: Refresh tokens

#### `setPrompt(string $prompt): self`

Control authentication prompts.

```php
// Always show account picker
$sso->setPrompt('select_account');

// Force re-authentication
$sso->setPrompt('login');

// No prompt (default)
$sso->setPrompt('none');

// Consent screen
$sso->setPrompt('consent');
```

#### `setLoginHint(string $hint): self`

Pre-fill email address on login page.

```php
$sso->setLoginHint('user@company.com');
```

#### `setDomainHint(string $domain): self`

Skip tenant selection for known domains.

```php
$sso->setDomainHint('company.com');
```

### User Information

#### `getUserInfo(string $accessToken): array`

Get full user profile from Microsoft Graph.

```php
$userInfo = $sso->getUserInfo($accessToken);

echo $userInfo['id'];  // User ID
echo $userInfo['userPrincipalName'];  // Email
echo $userInfo['displayName'];  // Full name
echo $userInfo['givenName'];  // First name
echo $userInfo['surname'];  // Last name
echo $userInfo['jobTitle'];  // Job title
```

**Returns**:
```php
[
    'id' => 'abc123...',
    'userPrincipalName' => 'user@company.com',
    'displayName' => 'John Doe',
    'givenName' => 'John',
    'surname' => 'Doe',
    'jobTitle' => 'Software Engineer',
    'mail' => 'john.doe@company.com',
    'mobilePhone' => '+1-555-0100',
    'officeLocation' => 'Building 2, Floor 3',
]
```

#### `getUserEmail(): ?string`

Get user email from cached info.

```php
$email = $sso->getUserEmail();
```

#### `getUserDisplayName(): ?string`

Get display name from cached info.

```php
$name = $sso->getUserDisplayName();
```

#### `getUserGivenName(): ?string`

Get first name from cached info.

```php
$firstName = $sso->getUserGivenName();
```

#### `getUserSurname(): ?string`

Get last name from cached info.

```php
$lastName = $sso->getUserSurname();
```

#### `getUserJobTitle(): ?string`

Get job title from cached info.

```php
$title = $sso->getUserJobTitle();
```

#### `getUserPhoto(string $accessToken, string $size = '648x648'): ?string`

Get user profile photo as binary data.

```php
$photoData = $sso->getUserPhoto($accessToken);

if ($photoData) {
    header('Content-Type: image/jpeg');
    echo $photoData;
}
```

**Available sizes**:
- `48x48`, `64x64`, `96x96`, `120x120`, `240x240`, `360x360`, `432x432`, `504x504`, `648x648`

### ID Token

#### `parseIdToken(array $tokenData): ?array`

Extract ID token claims from token response.

```php
$tokenData = $sso->getAccessToken($code);
$claims = $sso->parseIdToken($tokenData);

echo $claims['oid'];  // User ID
echo $claims['preferred_username'];  // Email
echo $claims['name'];  // Display name
echo $claims['tid'];  // Tenant ID
```

#### `validateIdToken(array $claims): bool`

Validate ID token claims.

```php
$claims = $sso->parseIdToken($tokenData);

if (!$sso->validateIdToken($claims)) {
    die('Invalid ID token');
}
```

Checks:
- Audience (`aud`) matches client ID
- Token not expired (`exp`)
- Issuer (`iss`) is Microsoft

### Session Management

#### `createSession(array $tokenData, array $userInfo): array`

Create session data structure.

```php
$tokenData = $sso->getAccessToken($code);
$userInfo = $sso->getUserInfo($tokenData['access_token']);
$session = $sso->createSession($tokenData, $userInfo);

$_SESSION['user'] = $session;
```

**Returns**:
```php
[
    'user_id' => 'abc123...',
    'email' => 'user@company.com',
    'display_name' => 'John Doe',
    'given_name' => 'John',
    'surname' => 'Doe',
    'job_title' => 'Software Engineer',
    'access_token' => 'eyJ0eXAi...',
    'refresh_token' => 'M.R3_BAY...',
    'expires_at' => 1640000000,
    'tenant_id' => 'xyz789...',
]
```

#### `static isSessionExpired(array $session): bool`

Check if session is expired.

```php
if (Office365SSO::isSessionExpired($_SESSION['user'])) {
    // Refresh token
    $tokenData = $sso->refreshAccessToken($_SESSION['user']['refresh_token']);
    $userInfo = $sso->getUserInfo($tokenData['access_token']);
    $_SESSION['user'] = $sso->createSession($tokenData, $userInfo);
}
```

#### `getSignOutUrl(?string $idToken = null, ?string $postLogoutRedirect = null): string`

Generate Microsoft sign-out URL.

```php
$url = $sso->getSignOutUrl(
    $_SESSION['user']['id_token'] ?? null,
    'https://mysite.com/goodbye'
);

header('Location: ' . $url);
```

## Usage Patterns

### Complete Authentication Flow

```php
use Razy\Office365SSO;

return new class($module) extends Controller {
    private Office365SSO $sso;
    
    public function __onInit(Agent $agent): bool {
        $this->sso = new Office365SSO(
            getenv('AZURE_CLIENT_ID'),
            getenv('AZURE_CLIENT_SECRET'),
            'https://mysite.com/auth/callback',
            'common'
        );
        
        $this->sso->setGraphScope('User.Read Mail.Read offline_access');
        
        $agent->addRoute('login', [$this, 'login']);
        $agent->addRoute('callback', [$this, 'callback']);
        $agent->addRoute('profile', [$this, 'profile']);
        $agent->addRoute('logout', [$this, 'logout']);
        
        return true;
    }
    
    public function login(): void {
        session_start();
        
        $this->sso->setPrompt('select_account');
        $this->sso->setState();
        $_SESSION['oauth_state'] = $this->sso->getState();
        
        header('Location: ' . $this->sso->getAuthorizationUrl());
        exit;
    }
    
    public function callback(): void {
        session_start();
        
        if (!OAuth2::validateState($_GET['state'], $_SESSION['oauth_state'])) {
            http_response_code(400);
            die('Invalid state token');
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
        
        if (Office365SSO::isSessionExpired($_SESSION['user'])) {
            $this->refreshToken();
        }
        
        $tpl = $this->prepareTemplate('view/profile');
        $tpl->assign('user', $_SESSION['user']);
        echo $tpl->render();
    }
    
    public function logout(): void {
        session_start();
        
        $url = $this->sso->getSignOutUrl(
            $_SESSION['user']['id_token'] ?? null,
            'https://mysite.com/'
        );
        
        session_destroy();
        header('Location: ' . $url);
        exit;
    }
    
    private function refreshToken(): void {
        $tokenData = $this->sso->refreshAccessToken($_SESSION['user']['refresh_token']);
        $userInfo = $this->sso->getUserInfo($tokenData['access_token']);
        $_SESSION['user'] = $this->sso->createSession($tokenData, $userInfo);
    }
};
```

### Microsoft Graph API Access

#### Get Emails

```php
public function getEmails(): array {
    session_start();
    
    if (Office365SSO::isSessionExpired($_SESSION['user'])) {
        $this->refreshToken();
    }
    
    $url = 'https://graph.microsoft.com/v1.0/me/messages';
    $url .= '?$select=subject,from,receivedDateTime';
    $url .= '&$top=10';
    $url .= '&$orderby=receivedDateTime DESC';
    
    return $this->sso->httpGet($url, $_SESSION['user']['access_token']);
}
```

#### Get Calendar Events

```php
public function getCalendarEvents(): array {
    $this->ensureValidToken();
    
    $url = 'https://graph.microsoft.com/v1.0/me/events';
    $url .= '?$select=subject,start,end,location';
    $url .= '&$top=20';
    $url .= '&$orderby=start/dateTime';
    
    return $this->sso->httpGet($url, $_SESSION['user']['access_token']);
}
```

#### Get OneDrive Files

```php
public function getFiles(): array {
    $this->ensureValidToken();
    
    $url = 'https://graph.microsoft.com/v1.0/me/drive/root/children';
    
    return $this->sso->httpGet($url, $_SESSION['user']['access_token']);
}
```

### Database Integration

```php
private function saveOrUpdateUser(array $session): int {
    $stmt = $this->prepare('users');
    $stmt->where('microsoft_id', $session['user_id']);
    
    $existing = $this->query($stmt)->fetch();
    
    $userData = [
        'microsoft_id' => $session['user_id'],
        'email' => $session['email'],
        'display_name' => $session['display_name'],
        'given_name' => $session['given_name'],
        'surname' => $session['surname'],
        'job_title' => $session['job_title'],
        'tenant_id' => $session['tenant_id'],
        'last_login' => date('Y-m-d H:i:s'),
    ];
    
    if ($existing) {
        $this->execute($stmt, $userData, 'update');
        return $existing['id'];
    } else {
        $userData['created_at'] = date('Y-m-d H:i:s');
        $this->execute($stmt, $userData, 'insert');
        return $this->getLastInsertId();
    }
}
```

## Microsoft Graph API Examples

### User Profile with Photo

```php
public function getFullProfile(): array {
    session_start();
    $this->ensureValidToken();
    
    $token = $_SESSION['user']['access_token'];
    
    // Get profile
    $profile = $this->sso->httpGet(
        'https://graph.microsoft.com/v1.0/me',
        $token
    );
    
    // Get photo
    $photo = $this->sso->getUserPhoto($token);
    
    return [
        'profile' => $profile,
        'photo' => $photo ? base64_encode($photo) : null,
    ];
}
```

### Send Email

```php
public function sendEmail(string $to, string $subject, string $body): void {
    $this->ensureValidToken();
    
    $url = 'https://graph.microsoft.com/v1.0/me/sendMail';
    
    $data = [
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
    ];
    
    $this->sso->httpPost($url, $data, $_SESSION['user']['access_token']);
}
```

### Create Calendar Event

```php
public function createEvent(string $subject, string $start, string $end): void {
    $this->ensureValidToken();
    
    $url = 'https://graph.microsoft.com/v1.0/me/events';
    
    $data = [
        'subject' => $subject,
        'start' => [
            'dateTime' => $start,
            'timeZone' => 'UTC',
        ],
        'end' => [
            'dateTime' => $end,
            'timeZone' => 'UTC',
        ],
    ];
    
    $this->sso->httpPost($url, $data, $_SESSION['user']['access_token']);
}
```

## Security Considerations

1. **Validate ID tokens** before trusting claims
2. **Check token expiration** and refresh proactively
3. **Use HTTPS only** for all OAuth flows
4. **Store secrets securely** (environment variables)
5. **Request minimum scopes** needed
6. **Validate state tokens** (CSRF protection)
7. **Clear sessions on logout** and redirect to Microsoft sign-out

## Azure AD Setup

1. Go to [Azure Portal](https://portal.azure.com)
2. **App registrations** → **New registration**
3. Name your app
4. Account types: **Multitenant and personal accounts** (for 'common')
5. Redirect URI: Your callback URL
6. **Register** → Copy **Application (client) ID**
7. **Certificates & secrets** → New secret → Copy **Value**
8. **API permissions** → **Add permission** → Microsoft Graph → Delegated:
   - `User.Read`, `openid`, `profile`, `email`
   - Add `offline_access` for refresh tokens

## Worker Mode Considerations

Always recreate SSO instance in `__onInit`:

```php
public function __onInit(Agent $agent): bool {
    // Recreate on each request in worker mode
    $this->sso = new Office365SSO(
        getenv('AZURE_CLIENT_ID'),
        getenv('AZURE_CLIENT_SECRET'),
        getenv('AZURE_REDIRECT_URI'),
        getenv('AZURE_TENANT_ID') ?: 'common'
    );
    
    // Always start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return true;
}
```

## Related Classes

- **OAuth2**: Base OAuth 2.0 client
- **XHR**: Alternative HTTP client
- **Configuration**: Store Azure credentials

## See Also

- Full guide: [../guides/OFFICE365-SSO.md](../guides/OFFICE365-SSO.md)
- Quick reference: [../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md](../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md)
- Azure Portal: https://portal.azure.com
- Microsoft Graph: https://developer.microsoft.com/graph
- Microsoft Identity: https://docs.microsoft.com/azure/active-directory/develop/
