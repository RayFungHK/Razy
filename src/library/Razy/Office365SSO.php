<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Exception\OAuthException;

/**
 * Microsoft Office 365 / Azure AD (Entra ID) SSO Authentication Client.
 *
 * Extends the generic OAuth2 handler with Microsoft-specific features including
 * Microsoft Entra ID endpoint configuration, Microsoft Graph API integration
 * for user profile retrieval, ID token parsing, and session management.
 *
 * @class Office365SSO
 */
class Office365SSO extends OAuth2
{
    /** @var string Azure AD tenant identifier ('common', 'organizations', 'consumers', or a specific tenant GUID) */
    private string $tenantId;

    /** @var string Space-separated Microsoft Graph API permission scopes */
    private string $graphScope = 'User.Read openid profile email';

    /** @var string Authentication prompt behavior ('login', 'select_account', 'consent', 'none') */
    private string $prompt = 'none';

    /** @var string|null Pre-filled email address hint for the login page */
    private ?string $loginHint = null;

    /** @var string|null Domain hint to skip tenant/realm discovery */
    private ?string $domainHint = null;

    /** @var array Cached user profile data from Microsoft Graph */
    private array $userInfo = [];

    /** @var string|null Raw ID token string from the token response */
    private ?string $idToken = null;

    /**
     * Office365SSO constructor.
     *
     * @param string $clientId Application (client) ID from Azure Portal
     * @param string $clientSecret Client secret from Azure Portal
     * @param string $redirectUri Callback URL (must match Azure registration)
     * @param string $tenantId Tenant ID, 'common', 'organizations', or 'consumers'
     */
    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $tenantId = 'common',
    ) {
        parent::__construct($clientId, $clientSecret, $redirectUri);

        $this->tenantId = \trim($tenantId);

        // Set Microsoft Entra ID endpoints
        $this->setAuthorizeUrl("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize");
        $this->setTokenUrl("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token");
        $this->setScope($this->graphScope);
    }

    /**
     * Check if session is expired.
     *
     * @param array $session Session data from createSession()
     *
     * @return bool
     */
    public static function isSessionExpired(array $session): bool
    {
        return \time() >= ($session['expires_at'] ?? 0);
    }

    /**
     * Set Microsoft Graph API scopes.
     *
     * @param string $scope Space-separated scope string (e.g., 'User.Read Mail.Read Calendars.Read')
     *
     * @return $this
     */
    public function setGraphScope(string $scope): self
    {
        $this->graphScope = \trim($scope);
        $this->setScope($this->graphScope);
        return $this;
    }

    /**
     * Control authentication prompts.
     *
     * @param string $prompt 'login' (force re-auth), 'select_account', 'consent', 'none'
     *
     * @return $this
     */
    public function setPrompt(string $prompt): self
    {
        $this->prompt = \trim($prompt);
        if ($this->prompt !== 'none') {
            $this->addParam('prompt', $this->prompt);
        }
        return $this;
    }

    /**
     * Pre-fill email address on login page.
     *
     * @param string $email User email address
     *
     * @return $this
     */
    public function setLoginHint(string $email): self
    {
        $this->loginHint = \trim($email);
        if ($this->loginHint) {
            $this->addParam('login_hint', $this->loginHint);
        }
        return $this;
    }

    /**
     * Skip tenant selection for known domains.
     *
     * @param string $domain Domain name (e.g., 'company.com')
     *
     * @return $this
     */
    public function setDomainHint(string $domain): self
    {
        $this->domainHint = \trim($domain);
        if ($this->domainHint) {
            $this->addParam('domain_hint', $this->domainHint);
        }
        return $this;
    }

    /**
     * Get full user profile from Microsoft Graph.
     *
     * @param string $accessToken Access token from OAuth flow
     *
     * @return array User information
     *
     * @throws OAuthException
     */
    public function getUserInfo(string $accessToken): array
    {
        // Query Microsoft Graph /me endpoint with selective fields to minimize response size
        $url = 'https://graph.microsoft.com/v1.0/me';
        $url .= '?$select=id,userPrincipalName,displayName,givenName,surname,jobTitle,mail,mobilePhone,officeLocation';

        $this->userInfo = $this->httpGet($url, $accessToken);
        return $this->userInfo;
    }

    /**
     * Get user email from cached info.
     *
     * @return string|null
     */
    public function getUserEmail(): ?string
    {
        return $this->userInfo['mail'] ?? $this->userInfo['userPrincipalName'] ?? null;
    }

    /**
     * Get display name from cached info.
     *
     * @return string|null
     */
    public function getUserDisplayName(): ?string
    {
        return $this->userInfo['displayName'] ?? null;
    }

    /**
     * Get first name from cached info.
     *
     * @return string|null
     */
    public function getUserGivenName(): ?string
    {
        return $this->userInfo['givenName'] ?? null;
    }

    /**
     * Get last name from cached info.
     *
     * @return string|null
     */
    public function getUserSurname(): ?string
    {
        return $this->userInfo['surname'] ?? null;
    }

    /**
     * Get job title from cached info.
     *
     * @return string|null
     */
    public function getUserJobTitle(): ?string
    {
        return $this->userInfo['jobTitle'] ?? null;
    }

    /**
     * Get user profile photo as binary data.
     *
     * @param string $accessToken Access token
     * @param string $size Photo size (48x48, 64x64, 96x96, 120x120, 240x240, 360x360, 432x432, 504x504, 648x648)
     *
     * @return string|null Photo data (binary) or null if not available
     */
    public function getUserPhoto(string $accessToken, string $size = '648x648'): ?string
    {
        try {
            // Request the photo binary data at the specified resolution via Graph API
            $url = "https://graph.microsoft.com/v1.0/me/photos/{$size}/\$value";

            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
            ]);
            // Enable binary transfer for raw image data
            \curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            // Return photo data only on success; null if user has no photo
            if ($httpCode === 200) {
                return $response;
            }

            return null;
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Extract ID token claims from token response.
     *
     * @param array $tokenData Token data from getAccessToken()
     *
     * @return array|null ID token claims or null if not present
     */
    public function parseIdToken(array $tokenData): ?array
    {
        if (!isset($tokenData['id_token'])) {
            return null;
        }

        $this->idToken = $tokenData['id_token'];

        try {
            return self::parseJWT($tokenData['id_token']);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Validate ID token claims.
     *
     * @param array $claims ID token claims from parseIdToken()
     *
     * @return bool
     */
    public function validateIdToken(array $claims): bool
    {
        // Verify the 'aud' (audience) claim matches this application's client ID
        if (($claims['aud'] ?? null) !== $this->clientId) {
            return false;
        }

        // Reject tokens that have passed their 'exp' (expiration) timestamp
        if (self::isJWTExpired($claims)) {
            return false;
        }

        // Validate the 'iss' (issuer) claim originates from Microsoft's identity platform
        $issuer = $claims['iss'] ?? null;
        if (!\str_contains($issuer, 'microsoftonline.com') && !\str_contains($issuer, 'login.microsoftonline.com')) {
            return false;
        }

        return true;
    }

    /**
     * Create session data structure from token and user info.
     *
     * @param array $tokenData Token data from getAccessToken()
     * @param array $userInfo User info from getUserInfo()
     *
     * @return array Session data
     */
    public function createSession(array $tokenData, array $userInfo): array
    {
        // Calculate absolute expiry time from relative expires_in (default ~1 hour)
        $expiresAt = \time() + ($tokenData['expires_in'] ?? 3599);

        // Extract claims from ID token for additional identity info (tenant, object ID)
        $idTokenClaims = $this->parseIdToken($tokenData);

        return [
            'user_id' => $userInfo['id'] ?? $idTokenClaims['oid'] ?? null,
            'email' => $userInfo['userPrincipalName'] ?? $userInfo['mail'] ?? null,
            'display_name' => $userInfo['displayName'] ?? null,
            'given_name' => $userInfo['givenName'] ?? null,
            'surname' => $userInfo['surname'] ?? null,
            'job_title' => $userInfo['jobTitle'] ?? null,
            'access_token' => $tokenData['access_token'] ?? null,
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'id_token' => $tokenData['id_token'] ?? null,
            'expires_at' => $expiresAt,
            'tenant_id' => $idTokenClaims['tid'] ?? $this->tenantId,
            'created_at' => \time(),
        ];
    }

    /**
     * Generate Microsoft sign-out URL.
     *
     * @param string|null $idToken ID token (optional, for single sign-out)
     * @param string|null $postLogoutRedirect Post-logout redirect URI
     *
     * @return string Sign-out URL
     */
    public function getSignOutUrl(?string $idToken = null, ?string $postLogoutRedirect = null): string
    {
        // Microsoft Entra ID v2.0 logout endpoint
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/logout";

        $params = [];

        // Include ID token hint for single sign-out across sessions
        if ($idToken) {
            $params['id_token_hint'] = $idToken;
        }

        // Redirect user to this URI after sign-out completes
        if ($postLogoutRedirect) {
            $params['post_logout_redirect_uri'] = $postLogoutRedirect;
        }

        if (!empty($params)) {
            $url .= '?' . \http_build_query($params);
        }

        return $url;
    }

    /**
     * Make authenticated HTTP POST request to Microsoft Graph.
     *
     * @param string $url API endpoint URL
     * @param array $data POST data (will be JSON-encoded)
     * @param string $accessToken Access token
     *
     * @return array Response data
     *
     * @throws OAuthException
     */
    public function httpPost(string $url, array $data, string $accessToken): array
    {
        // Send authenticated JSON POST to Microsoft Graph API
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);

        // Check for transport-level errors
        if ($error) {
            throw new OAuthException('HTTP POST request failed: ' . $error);
        }

        // Check for HTTP error status codes (4xx/5xx)
        if ($httpCode >= 400) {
            throw new OAuthException('HTTP POST request failed with code ' . $httpCode . ': ' . $response);
        }

        // Decode and validate the JSON response body
        $decoded = \json_decode($response, true);
        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new OAuthException('Failed to decode JSON response: ' . \json_last_error_msg());
        }

        return $decoded ?? [];
    }

    /**
     * Get tenant ID.
     *
     * @return string
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * Get Microsoft Graph scope.
     *
     * @return string
     */
    public function getGraphScope(): string
    {
        return $this->graphScope;
    }
}
