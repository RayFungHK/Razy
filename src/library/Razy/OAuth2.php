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

use InvalidArgumentException;
use Razy\Exception\OAuthException;

/**
 * OAuth 2.0 Authentication Handler.
 *
 * Provides a generic OAuth 2.0 authorization code flow implementation that can
 * be used with various OAuth providers. Handles authorization URL generation,
 * token exchange, token refresh, and authenticated API requests via cURL.
 *
 * @class OAuth2
 */
class OAuth2
{
    /** @var string OAuth 2.0 client identifier */
    protected string $clientId;

    /** @var string OAuth 2.0 client secret */
    protected string $clientSecret;

    /** @var string Redirect URI for the authorization callback */
    protected string $redirectUri;

    /** @var string Authorization endpoint URL */
    protected string $authorizeUrl;

    /** @var string Token exchange endpoint URL */
    protected string $tokenUrl;

    /** @var string Space-separated list of requested OAuth scopes */
    protected string $scope;

    /** @var string|null CSRF state token for authorization request validation */
    protected ?string $state = null;

    /** @var array Additional query parameters appended to the authorization URL */
    protected array $additionalParams = [];

    /** @var array Token data received from the token endpoint */
    protected array $tokenData = [];

    /**
     * OAuth2 constructor.
     *
     * @param string $clientId OAuth client ID
     * @param string $clientSecret OAuth client secret
     * @param string $redirectUri Callback URL after authorization
     */
    public function __construct(string $clientId, string $clientSecret, string $redirectUri)
    {
        $this->clientId = \trim($clientId);
        $this->clientSecret = \trim($clientSecret);
        $this->redirectUri = \trim($redirectUri);
    }

    /**
     * Validate state token (CSRF protection).
     *
     * @param string $receivedState State from callback
     * @param string $expectedState State from session
     *
     * @return bool
     */
    public static function validateState(string $receivedState, string $expectedState): bool
    {
        // Use timing-safe comparison to prevent side-channel attacks
        return \hash_equals($expectedState, $receivedState);
    }

    /**
     * Parse JWT token without verification (for claims extraction).
     * Note: This does NOT validate the signature. Use verifyJWT() for security.
     *
     * @param string $jwt JWT token
     *
     * @return array Decoded payload
     *
     * @throws OAuthException
     */
    public static function parseJWT(string $jwt): array
    {
        // JWT format: header.payload.signature (three base64url-encoded segments)
        $parts = \explode('.', $jwt);
        if (\count($parts) !== 3) {
            throw new OAuthException('Invalid JWT format');
        }

        // Decode the payload (second segment), converting base64url to standard base64
        $payload = \base64_decode(\strtr($parts[1], '-_', '+/'));
        $data = \json_decode($payload, true);

        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new OAuthException('Failed to decode JWT payload: ' . \json_last_error_msg());
        }

        return $data;
    }

    /**
     * Verify JWT expiration.
     *
     * @param array $claims JWT claims
     *
     * @return bool
     */
    public static function isJWTExpired(array $claims): bool
    {
        if (!isset($claims['exp'])) {
            return true;
        }

        return \time() >= $claims['exp'];
    }

    /**
     * Set authorization endpoint URL.
     *
     * @param string $url Authorization endpoint
     *
     * @return $this
     */
    public function setAuthorizeUrl(string $url): self
    {
        $this->authorizeUrl = \trim($url);
        return $this;
    }

    /**
     * Set token endpoint URL.
     *
     * @param string $url Token endpoint
     *
     * @return $this
     */
    public function setTokenUrl(string $url): self
    {
        $this->tokenUrl = \trim($url);
        return $this;
    }

    /**
     * Set OAuth scope.
     *
     * @param string $scope Space-separated scope string
     *
     * @return $this
     */
    public function setScope(string $scope): self
    {
        $this->scope = \trim($scope);
        return $this;
    }

    /**
     * Set CSRF state token.
     *
     * @param string|null $state State token (auto-generated if null)
     *
     * @return $this
     */
    public function setState(?string $state = null): self
    {
        $this->state = $state ?? \bin2hex(\random_bytes(16));
        return $this;
    }

    /**
     * Get current state token.
     *
     * @return string|null
     */
    public function getState(): ?string
    {
        return $this->state;
    }

    /**
     * Add additional authorization parameters.
     *
     * @param string $key Parameter name
     * @param string $value Parameter value
     *
     * @return $this
     */
    public function addParam(string $key, string $value): self
    {
        $this->additionalParams[$key] = $value;
        return $this;
    }

    /**
     * Generate authorization URL.
     *
     * @return string Authorization URL to redirect user
     *
     * @throws InvalidArgumentException
     */
    public function getAuthorizationUrl(): string
    {
        if (empty($this->authorizeUrl)) {
            throw new InvalidArgumentException('Authorization URL not set. Call setAuthorizeUrl() first.');
        }

        // Auto-generate a CSRF state token if not explicitly set
        if ($this->state === null) {
            $this->setState();
        }

        // Merge base OAuth parameters with any provider-specific additional params
        $params = \array_merge([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $this->state,
        ], $this->additionalParams);

        return $this->authorizeUrl . '?' . \http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @param string $code Authorization code from callback
     *
     * @return array Token data (access_token, refresh_token, expires_in, etc.)
     *
     * @throws InvalidArgumentException
     */
    public function getAccessToken(string $code): array
    {
        if (empty($this->tokenUrl)) {
            throw new InvalidArgumentException('Token URL not set. Call setTokenUrl() first.');
        }

        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $response = $this->httpPost($this->tokenUrl, $params);
        $this->tokenData = $response;

        return $response;
    }

    /**
     * Refresh access token using refresh token.
     *
     * @param string $refreshToken Refresh token
     *
     * @return array Token data
     *
     * @throws InvalidArgumentException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        if (empty($this->tokenUrl)) {
            throw new InvalidArgumentException('Token URL not set. Call setTokenUrl() first.');
        }

        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $response = $this->httpPost($this->tokenUrl, $params);
        $this->tokenData = $response;

        return $response;
    }

    /**
     * Make authenticated HTTP GET request.
     *
     * @param string $url API endpoint URL
     * @param string $accessToken Access token
     *
     * @return array Response data
     *
     * @throws OAuthException
     */
    public function httpGet(string $url, string $accessToken): array
    {
        // Initialize cURL with Bearer token authentication
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);

        // Check for transport-level errors (DNS, timeout, connection refused, etc.)
        if ($error) {
            throw new OAuthException('HTTP GET request failed: ' . $error);
        }

        // Check for HTTP error status codes (4xx/5xx)
        if ($httpCode >= 400) {
            throw new OAuthException('HTTP GET request failed with code ' . $httpCode . ': ' . $response);
        }

        // Decode and validate the JSON response body
        $data = \json_decode($response, true);
        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new OAuthException('Failed to decode JSON response: ' . \json_last_error_msg());
        }

        return $data;
    }

    /**
     * Get stored token data.
     *
     * @return array
     */
    public function getTokenData(): array
    {
        return $this->tokenData;
    }

    /**
     * Make HTTP POST request.
     *
     * @param string $url Endpoint URL
     * @param array $params POST parameters
     *
     * @return array Response data
     *
     * @throws OAuthException
     */
    private function httpPost(string $url, array $params): array
    {
        // Initialize cURL for POST with form-encoded parameters (standard OAuth2 token exchange)
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_POST, true);
        \curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($params));
        \curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
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
        $data = \json_decode($response, true);
        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new OAuthException('Failed to decode JSON response: ' . \json_last_error_msg());
        }

        return $data;
    }
}
