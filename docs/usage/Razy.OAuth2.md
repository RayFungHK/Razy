# Razy\OAuth2

Generic OAuth 2.0 authentication client for implementing OAuth flows. Base class for provider-specific implementations like Office365SSO.

**File**: `src/library/Razy/OAuth2.php`

## Purpose

- Implements OAuth 2.0 authorization code flow
- Handles token exchange and refresh
- CSRF protection with state tokens
- JWT parsing and validation
- HTTP request helpers for OAuth APIs

## Key Concepts

### OAuth 2.0 Flow

1. **Authorization**: Generate authorization URL with state token
2. **Callback**: Exchange authorization code for access token
3. **API Access**: Use access token to call protected APIs
4. **Refresh**: Use refresh token to get new access token

### State Token (CSRF Protection)

State tokens prevent Cross-Site Request Forgery attacks:

```php
// Before redirect (in login handler)
$oauth->setState();
$_SESSION['oauth_state'] = $oauth->getState();
header('Location: ' . $oauth->getAuthorizationUrl());

// After redirect (in callback handler)
if (!OAuth2::validateState($_GET['state'], $_SESSION['oauth_state'])) {
    die('Invalid state token');
}
```

### JWT (JSON Web Token)

OAuth 2.0 often returns tokens as JWTs. Parse them to extract claims:

```php
$claims = OAuth2::parseJWT($idToken);
$userId = $claims['sub'];
$email = $claims['email'];
$expiry = $claims['exp'];

if (OAuth2::isJWTExpired($claims)) {
    // Token expired
}
```

## Constructor

```php
public function __construct(
    string $clientId,
    string $clientSecret,
    string $redirectUri,
    string $authorizationUrl,
    string $tokenUrl
)
```

**Parameters**:
- `$clientId`: OAuth client ID from provider
- `$clientSecret`: OAuth client secret from provider
- `$redirectUri`: Your callback URL (must match registration)
- `$authorizationUrl`: Provider's authorization endpoint
- `$tokenUrl`: Provider's token endpoint

**Example**:
```php
$oauth = new OAuth2(
    'abc123-def456',
    'my-secret-value',
    'https://mysite.com/callback',
    'https://provider.com/oauth2/authorize',
    'https://provider.com/oauth2/token'
);
```

## Public API

### Authorization Flow

#### `getAuthorizationUrl(): string`

Generate the authorization URL for user login redirect.

```php
$url = $oauth->getAuthorizationUrl();
header('Location: ' . $url);
```

Returns URL like:
```
https://provider.com/oauth2/authorize?
  response_type=code&
  client_id=abc123&
  redirect_uri=https://mysite.com/callback&
  state=random-state-token&
  scope=openid profile email
```

#### `getAccessToken(string $code): array`

Exchange authorization code for access token.

```php
// In callback handler (/callback route)
$tokenData = $oauth->getAccessToken($_GET['code']);
// Store $tokenData['access_token'] in session
```

**Returns**:
```php
[
    'access_token' => 'eyJ0eXAi...',
    'refresh_token' => 'M.R3_BAY...',
    'expires_in' => 3600,
    'token_type' => 'Bearer',
    'id_token' => 'eyJ0eXAi...',  // if OpenID Connect
]
```

#### `refreshAccessToken(string $refreshToken): array`

Get new access token using refresh token.

```php
if (time() > $_SESSION['token_expires_at']) {
    $tokenData = $oauth->refreshAccessToken($_SESSION['refresh_token']);
    $_SESSION['access_token'] = $tokenData['access_token'];
    $_SESSION['token_expires_at'] = time() + $tokenData['expires_in'];
}
```

**Returns**: Same structure as `getAccessToken()`

### Configuration

#### `setScope(string $scope): self`

Set OAuth scopes (space-separated).

```php
$oauth->setScope('openid profile email');
```

#### `useState(bool $useState): self`

Enable/disable CSRF state token (enabled by default).

```php
$oauth->useState(true);  // Recommended
```

#### `setState(string $state = null): self`

Set custom state token, or generate random one.

```php
// Auto-generate
$oauth->setState();
$state = $oauth->getState();

// Custom state
$oauth->setState('my-custom-state');
```

#### `getState(): string`

Get the current state token.

```php
$state = $oauth->getState();
$_SESSION['oauth_state'] = $state;
```

### HTTP Helpers

#### `httpGet(string $url, string $accessToken): array`

Make authenticated GET request.

```php
$response = $oauth->httpGet(
    'https://api.provider.com/v1/user',
    $accessToken
);

$userId = $response['id'];
$email = $response['email'];
```

#### `httpPost(string $url, array $data, string $accessToken = null): array`

Make authenticated POST request.

```php
$response = $oauth->httpPost(
    'https://api.provider.com/v1/messages',
    ['text' => 'Hello!'],
    $accessToken
);
```

### JWT Utilities

#### `static parseJWT(string $jwt): array`

Parse JWT and extract claims without verification.

```php
$claims = OAuth2::parseJWT($idToken);

echo $claims['sub'];  // User ID
echo $claims['email'];  // Email
echo $claims['name'];  // Display name
echo $claims['exp'];  // Expiration timestamp
```

**Warning**: Does not verify signature. Use only with tokens from trusted HTTPS endpoints.

#### `static isJWTExpired(array $claims): bool`

Check if JWT is expired based on `exp` claim.

```php
$claims = OAuth2::parseJWT($token);

if (OAuth2::isJWTExpired($claims)) {
    echo "Token expired at " . date('Y-m-d H:i:s', $claims['exp']);
}
```

### Validation

#### `static validateState(string $received, string $expected): bool`

Validate state token (CSRF protection).

```php
// In callback handler
if (!OAuth2::validateState($_GET['state'], $_SESSION['oauth_state'])) {
    http_response_code(400);
    die('Invalid state token - possible CSRF attack');
}
```

## Usage Patterns

### Complete OAuth Flow

```php
// Login route
public function login(): void {
    session_start();
    
    $oauth = new OAuth2(
        getenv('CLIENT_ID'),
        getenv('CLIENT_SECRET'),
        'https://mysite.com/callback',
        'https://provider.com/oauth2/authorize',
        'https://provider.com/oauth2/token'
    );
    
    $oauth->setScope('openid profile email');
    $oauth->setState();
    $_SESSION['oauth_state'] = $oauth->getState();
    
    header('Location: ' . $oauth->getAuthorizationUrl());
    exit;
}

// Callback route
public function callback(): void {
    session_start();
    
    // Validate state
    if (!OAuth2::validateState($_GET['state'], $_SESSION['oauth_state'])) {
        die('Invalid state');
    }
    
    $oauth = new OAuth2(
        getenv('CLIENT_ID'),
        getenv('CLIENT_SECRET'),
        'https://mysite.com/callback',
        'https://provider.com/oauth2/authorize',
        'https://provider.com/oauth2/token'
    );
    
    // Exchange code for token
    $tokenData = $oauth->getAccessToken($_GET['code']);
    
    // Get user info
    $userInfo = $oauth->httpGet(
        'https://api.provider.com/v1/user',
        $tokenData['access_token']
    );
    
    // Store in session
    $_SESSION['user'] = [
        'id' => $userInfo['id'],
        'email' => $userInfo['email'],
        'access_token' => $tokenData['access_token'],
        'refresh_token' => $tokenData['refresh_token'],
        'expires_at' => time() + $tokenData['expires_in'],
    ];
    
    header('Location: /profile');
    exit;
}
```

### Token Refresh

```php
public function ensureValidToken(): void {
    session_start();
    
    if (time() >= $_SESSION['user']['expires_at']) {
        $oauth = new OAuth2(
            getenv('CLIENT_ID'),
            getenv('CLIENT_SECRET'),
            'https://mysite.com/callback',
            'https://provider.com/oauth2/authorize',
            'https://provider.com/oauth2/token'
        );
        
        $tokenData = $oauth->refreshAccessToken($_SESSION['user']['refresh_token']);
        
        $_SESSION['user']['access_token'] = $tokenData['access_token'];
        $_SESSION['user']['expires_at'] = time() + $tokenData['expires_in'];
        
        if (isset($tokenData['refresh_token'])) {
            $_SESSION['user']['refresh_token'] = $tokenData['refresh_token'];
        }
    }
}
```

### API Calls with Error Handling

```php
public function getUserData(): ?array {
    $this->ensureValidToken();
    
    $oauth = new OAuth2(/*...*/);
    
    try {
        return $oauth->httpGet(
            'https://api.provider.com/v1/user',
            $_SESSION['user']['access_token']
        );
    } catch (Exception $e) {
        error_log("API call failed: " . $e->getMessage());
        return null;
    }
}
```

## Security Considerations

1. **Always use HTTPS** for OAuth flows
2. **Validate state tokens** to prevent CSRF
3. **Store secrets securely** (environment variables, not code)
4. **Check token expiration** before API calls
5. **Use minimum scopes** needed
6. **Clear sessions** on logout

## Extending OAuth2

Create provider-specific classes:

```php
class MyProviderOAuth extends OAuth2 {
    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ) {
        parent::__construct(
            $clientId,
            $clientSecret,
            $redirectUri,
            'https://myprovider.com/oauth2/authorize',
            'https://myprovider.com/oauth2/token'
        );
        
        $this->setScope('openid profile email');
    }
    
    public function getUserInfo(string $accessToken): array {
        return $this->httpGet(
            'https://api.myprovider.com/v1/user',
            $accessToken
        );
    }
}
```

## Related Classes

- **Office365SSO**: Microsoft Office 365 implementation
- **XHR**: Alternative HTTP client
- **Configuration**: Store OAuth credentials

## Examples from Production

### Generic OAuth Provider

```php
// In module controller
private OAuth2 $oauth;

public function __onInit(Agent $agent): bool {
    $this->oauth = new OAuth2(
        $this->getConfig('oauth_client_id'),
        $this->getConfig('oauth_client_secret'),
        $this->getConfig('oauth_redirect_uri'),
        'https://provider.com/oauth2/authorize',
        'https://provider.com/oauth2/token'
    );
    
    $agent->addRoute('login', [$this, 'login']);
    $agent->addRoute('callback', [$this, 'callback']);
    $agent->addRoute('profile', [$this, 'profile']);
    
    return true;
}
```

## Worker Mode Considerations

Always recreate OAuth2 instances in `__onInit`:

```php
public function __onInit(Agent $agent): bool {
    // Recreate on each request in worker mode
    $this->oauth = new OAuth2(
        getenv('CLIENT_ID'),
        getenv('CLIENT_SECRET'),
        getenv('REDIRECT_URI'),
        getenv('AUTH_URL'),
        getenv('TOKEN_URL')
    );
    
    return true;
}
```

## See Also

- Full guide: [../guides/OFFICE365-SSO.md](../guides/OFFICE365-SSO.md)
- Quick reference: [../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md](../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md)
- OAuth 2.0 spec: https://oauth.net/2/
- OpenID Connect: https://openid.net/connect/
