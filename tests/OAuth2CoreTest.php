<?php

declare(strict_types=1);

namespace Razy\Tests;

use CoversNothing;
use DateInterval;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Cache\CacheInterface;
use Razy\Exception\OAuthException;
use Razy\Http\HttpResponse;
use Razy\Http\HttpTransportException;
use Razy\Security\OAuth\OAuth2;
use Razy\Security\OAuth\OAuthConfig;
use Razy\Security\OAuth\ProviderInterface;
use Razy\Security\OAuth\ProviderRegistry;
use Razy\Security\OAuth\StateSigner;
use Razy\Security\OAuth\TokenResponse;

/**
 * Zero-network fake of the injectable HTTP seam (ClientInterface exists
 * precisely so these tests never touch a socket).
 */
#[CoversNothing]
final class RecordingOAuthClient implements \Razy\Http\ClientInterface
{
    /** @var list<HttpResponse|HttpTransportException> */
    public array $queue = [];

    /** @var list<array{method: string, url: string, options: array<string,mixed>}> */
    public array $requests = [];

    public function queue(HttpResponse|HttpTransportException $response): void
    {
        $this->queue[] = $response;
    }

    public function get(string $url, array $query = []): HttpResponse
    {
        return $this->send('GET', $url, ['query' => $query]);
    }

    public function post(string $url, array $data = []): HttpResponse
    {
        return $this->send('POST', $url, ['body' => $data]);
    }

    public function put(string $url, array $data = []): HttpResponse
    {
        return $this->send('PUT', $url, ['body' => $data]);
    }

    public function patch(string $url, array $data = []): HttpResponse
    {
        return $this->send('PATCH', $url, ['body' => $data]);
    }

    public function delete(string $url, array $data = []): HttpResponse
    {
        return $this->send('DELETE', $url, ['body' => $data]);
    }

    public function head(string $url, array $query = []): HttpResponse
    {
        return $this->send('HEAD', $url, ['query' => $query]);
    }

    public function options(string $url): HttpResponse
    {
        return $this->send('OPTIONS', $url);
    }

    public function send(string $method, string $url, array $options = []): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $next = \array_shift($this->queue);

        if ($next instanceof HttpTransportException) {
            throw $next;
        }

        if ($next === null) {
            throw new LogicException('RecordingOAuthClient ran dry - the flow made an unexpected request.');
        }

        return $next;
    }
}

/**
 * PSR-16 shaped in-memory cache (distinct name from PermissionsS5Test's
 * ArrayCache - the suite shares the global namespace).
 */
#[CoversNothing]
final class OAuthStateCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
}

/**
 * Minimal provider double; quirks via authorizeParams like Google would.
 */
#[CoversNothing]
final class StubOAuthProvider implements ProviderInterface
{
    public function __construct(private readonly string $name = 'stub')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function authorizeUrl(): string
    {
        return 'https://provider.example/authorize?style=quirky';
    }

    public function tokenUrl(): string
    {
        return 'https://provider.example/token';
    }

    public function authorizeParams(array $defaults): array
    {
        return $defaults + ['access_type' => 'offline'];
    }

    public function fetchUser(\Razy\Http\ClientInterface $client, TokenResponse $token): array
    {
        return ['id' => 'u-1', 'name' => 'Stub', 'email' => null, 'avatar' => null, 'raw' => []];
    }
}

/**
 * S2 core tests (OAuth dossier): PKCE with the RFC 7636 Appendix B vector,
 * signed single-use state (Q2 option C), §5.2 error mapping, ordering
 * discipline (state verified BEFORE any network). No socket is opened.
 */
#[CoversClass(StateSigner::class)]
#[CoversClass(OAuth2::class)]
#[CoversClass(TokenResponse::class)]
#[CoversClass(ProviderRegistry::class)]
#[CoversClass(OAuthConfig::class)]
class OAuth2CoreTest extends TestCase
{
    private const RFC7636_VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private const RFC7636_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private const REDIRECT = 'https://shop.example/razymod/oauth/callback/github';

    /**
     * The single custody record's verifier (written by begin/issue).
     */
    private static function storedVerifier(OAuthStateCache $cache): string
    {
        $record = \json_decode((string) \reset($cache->store), true);
        self::assertIsArray($record, 'exactly one custody record is expected');

        return (string) $record['verifier'];
    }

    // ── PKCE (RFC 7636) ──────────────────────────────────────────────

    public function testCodeChallengeMatchesTheRfcAppendixBVector(): void
    {
        self::assertSame(self::RFC7636_CHALLENGE, StateSigner::codeChallenge(self::RFC7636_VERIFIER));
    }

    public function testGeneratedVerifierIsWithinTheRfcBounds(): void
    {
        $verifier = StateSigner::generateVerifier();

        self::assertSame(43, \strlen($verifier), '32 random bytes base64url = 43 unreserved chars');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $verifier);
    }

    // ── signed state custody (Q2 option C) ───────────────────────────

    public function testStateRoundTrip(): void
    {
        $signer = $this->signer();
        ['state' => $state] = $signer->issue('github', self::REDIRECT);

        $verified = $signer->verify($state, 'github', self::REDIRECT);

        self::assertArrayHasKey('nonce', $verified);
    }

    public function testTamperedStateIsRejected(): void
    {
        $signer = $this->signer();
        ['state' => $state] = $signer->issue('github', self::REDIRECT);

        [$seg, $sig] = \explode('.', $state);
        $payload = \json_decode(StateSigner::b64urlDecode($seg), true);
        $payload['p'] = 'attacker-provider';
        $forged = StateSigner::b64url((string) \json_encode($payload)) . '.' . $sig;

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('signature mismatch');

        $signer->verify($forged, 'github', self::REDIRECT);
    }

    public function testStateFromAnotherSecretIsRejected(): void
    {
        ['state' => $state] = $this->signer()->issue('github', self::REDIRECT);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('signature mismatch');

        (new StateSigner('other-secret', new OAuthStateCache()))->verify($state, 'github', self::REDIRECT);
    }

    public function testStateIsBoundToProviderAndRedirectUri(): void
    {
        $signer = $this->signer();
        ['state' => $state] = $signer->issue('github', self::REDIRECT);

        try {
            $signer->verify($state, 'google', self::REDIRECT);
            self::fail('provider swap must be rejected');
        } catch (OAuthException $e) {
            self::assertStringContainsString('different provider', $e->getMessage());
        }

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('different redirect_uri');

        $signer->verify($state, 'github', 'https://evil.example/callback');
    }

    public function testMalformedStateIsRejected(): void
    {
        $signer = $this->signer();
        $rejected = 0;

        foreach (['', 'nodothere', 'a.', '.b'] as $garbage) {
            try {
                $signer->verify($garbage, 'github', self::REDIRECT);
                self::fail('malformed state must be rejected: ' . $garbage);
            } catch (OAuthException) {
                $rejected++;
            }
        }

        self::assertSame(4, $rejected);
    }

    public function testInvalidBase64urlInStateIsRejected(): void
    {
        $signer = $this->signer();

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('base64url');

        $signer->verify('abc.!!!notbase64!!!', 'github', self::REDIRECT);
    }

    public function testNonceIsSingleUse(): void
    {
        $signer = $this->signer();
        ['state' => $state, 'verifier' => $verifier] = $signer->issue('github', self::REDIRECT);

        ['nonce' => $nonce] = $signer->verify($state, 'github', self::REDIRECT);
        self::assertSame($verifier, $signer->redeem($nonce));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('already used');

        $signer->redeem($nonce); // replay dies on single-use
    }

    public function testStatelessConfigurationFailsLoud(): void
    {
        $signer = new StateSigner('test-secret'); // no cache: no custody, per Q2 the weaker mode is unavailable

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('requires a cache');

        $signer->issue('github', self::REDIRECT);
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('signing secret');

        new StateSigner('', new OAuthStateCache());
    }

    // ── the flow itself (injected client, zero network) ──────────────

    public function testBeginSendsS256ChallengeAndSignedStateOnly(): void
    {
        $cache = new OAuthStateCache();
        $flow = new OAuth2(new RecordingOAuthClient(), $this->signer($cache));

        ['url' => $url, 'state' => $state] = $flow->begin(new StubOAuthProvider(), new OAuthConfig('cid', 'secret', self::REDIRECT, 'read:user'));

        $query = [];
        \parse_str((string) \parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('S256', $query['code_challenge_method'], 'S256 is the only method this codebase offers');
        self::assertSame($state, $query['state']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('offline', $query['access_type'], 'provider quirks survive');
        self::assertStringStartsWith('https://provider.example/authorize?style=quirky&', $url, 'existing query string appended, not clobbered');

        $verifier = self::storedVerifier($cache);
        self::assertSame(StateSigner::codeChallenge($verifier), $query['code_challenge']);
        self::assertStringNotContainsString('plain', (string) \parse_url($url, PHP_URL_QUERY));
    }

    public function testBeginRequiresRegisteredRedirectUri(): void
    {
        $flow = new OAuth2(new RecordingOAuthClient(), $this->signer());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('redirect_uri is required');

        $flow->begin(new StubOAuthProvider(), new OAuthConfig('cid', 'secret', ''));
    }

    public function testExchangeParsesGithubStyleUrlencodedTokenBody(): void
    {
        $cache = new OAuthStateCache();
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer($cache));
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        ['state' => $state] = $flow->begin($provider, $config);
        $stored = self::storedVerifier($cache); // before exchange: redemption deletes the custody record
        $client->queue(new HttpResponse(200, 'access_token=gh_opaque&expires_in=3600&refresh_token=r-1&scope=read:user', [
            'content-type' => 'application/x-www-form-urlencoded',
        ])); // GitHub's DEFAULT shape (§5 G8) — urlencoded, not json

        $token = $flow->exchange($provider, $config, ['code' => 'the-code', 'state' => $state]);

        self::assertSame('gh_opaque', $token->accessToken);
        self::assertSame('r-1', $token->refreshToken);
        self::assertSame(3600, $token->expiresIn);
        self::assertGreaterThan(\time(), $token->expiresAt, 'relative expires_in became an absolute deadline');
        self::assertFalse($token->hasExpired());

        self::assertCount(1, $client->requests);
        $request = $client->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://provider.example/token', $request['url']);

        $body = [];
        \parse_str((string) $request['options']['raw_body'], $body);
        self::assertSame('the-code', $body['code']);
        self::assertSame(self::REDIRECT, $body['redirect_uri'], 'exact-match: the REGISTERED uri, never derived from the request');
        self::assertSame('cid', $body['client_id'], 'GitHub-style body client auth (not Basic)');
        self::assertSame('secret', $body['client_secret']);

        self::assertSame($stored, $body['code_verifier'], 'the server-side verifier rode along, never through the browser');

        self::assertSame('application/x-www-form-urlencoded', $request['options']['headers']['Content-Type']);
        self::assertSame('application/json', $request['options']['headers']['Accept']);
    }

    public function testStateIsVerifiedBeforeAnyNetworkCall(): void
    {
        $flow = new OAuth2($client = new RecordingOAuthClient(), $this->signer());
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        try {
            $flow->exchange($provider, $config, ['code' => 'x']); // no state at all
            self::fail('stateless callback must be refused');
        } catch (OAuthException $e) {
            self::assertStringContainsString('no state', $e->getMessage());
        }

        self::assertCount(0, $client->requests);
    }

    public function testTamperedStateNeverReachesTheTokenEndpoint(): void
    {
        $flow = new OAuth2($client = new RecordingOAuthClient(), $this->signer());

        try {
            $flow->exchange(new StubOAuthProvider(), new OAuthConfig('cid', 'secret', self::REDIRECT), [
                'code' => 'x',
                'state' => 'tampered.payload.signature',
            ]);
            self::fail('unverified state must abort');
        } catch (OAuthException) {
            // expected
        }

        self::assertCount(0, $client->requests, '§7 step 4: reject BEFORE any network call');
    }

    public function testProviderErrorIsMappedAfterStateVerification(): void
    {
        $cache = new OAuthStateCache();
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer($cache));
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        ['state' => $state] = $flow->begin($provider, $config);

        try {
            $flow->exchange($provider, $config, ['error' => 'access_denied', 'error_description' => 'User denied', 'state' => $state]);
            self::fail('provider error must throw');
        } catch (OAuthException $e) {
            self::assertStringContainsString('"access_denied"', $e->getMessage());
            self::assertStringContainsString('User denied', $e->getMessage());
        }

        self::assertCount(0, $client->requests, 'a denial costs no network either');
    }

    public function testUnverifiedProviderErrorIsStillRejected(): void
    {
        // attacker's error page without a valid state is not the provider's error
        $flow = new OAuth2(new RecordingOAuthClient(), $this->signer());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('signature mismatch');

        $flow->exchange(new StubOAuthProvider(), new OAuthConfig('cid', 'secret', self::REDIRECT), [
            'error' => 'access_denied',
            'state' => 'forged.sig',
        ]);
    }

    public function testMissingCodeIsRejected(): void
    {
        $flow = new OAuth2(new RecordingOAuthClient(), $this->signer());
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        ['state' => $state] = $flow->begin($provider, $config);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('no code');

        $flow->exchange($provider, $config, ['state' => $state]);
    }

    public function testTokenEndpointErrorIsMappedRfcStyle(): void
    {
        $cache = new OAuthStateCache();
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer($cache));
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        ['state' => $state] = $flow->begin($provider, $config);
        $client->queue(new HttpResponse(400, \json_encode(['error' => 'invalid_grant', 'error_description' => 'code already used']), [
            'content-type' => 'application/json',
        ]));

        try {
            $flow->exchange($provider, $config, ['code' => 'x', 'state' => $state]);
            self::fail('token error must throw');
        } catch (OAuthException $e) {
            self::assertStringContainsString('"invalid_grant"', $e->getMessage());
            self::assertStringContainsString('code already used', $e->getMessage());
            self::assertSame(400, $e->getCode(), 'the HTTP status rides as the exception code');
        }
    }

    public function testBasicAuthModeMovesTheSecretOutOfTheBody(): void
    {
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer());
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'p@ss w0rd', self::REDIRECT, '', true);

        ['state' => $state] = $flow->begin($provider, $config);
        $client->queue(new HttpResponse(200, \json_encode(['access_token' => 'tok']), ['content-type' => 'application/json']));

        $flow->exchange($provider, $config, ['code' => 'x', 'state' => $state]);

        $request = $client->requests[0];
        self::assertSame('Basic ' . \base64_encode('cid:p@ss w0rd'), $request['options']['headers']['Authorization']);
        self::assertStringNotContainsString('client_secret', (string) $request['options']['raw_body']);
        self::assertStringNotContainsString('client_id', (string) $request['options']['raw_body']);
    }

    public function testTokenResponseWithoutAccessTokenFailsLoud(): void
    {
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer());
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        ['state' => $state] = $flow->begin($provider, $config);
        // valid JSON with a valid content-type — it must fail for the RIGHT reason (no access_token)
        $client->queue(new HttpResponse(200, \json_encode(['token_type' => 'bearer']), ['content-type' => 'application/json']));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('no access_token');

        $flow->exchange($provider, $config, ['code' => 'x', 'state' => $state]);
    }

    public function testTransportFailureCarriesTheNamedCause(): void
    {
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer());
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        ['state' => $state] = $flow->begin($provider, $config);
        $client->queue(new HttpTransportException('Could not resolve host: provider.example'));

        try {
            $flow->exchange($provider, $config, ['code' => 'x', 'state' => $state]);
            self::fail('transport failure must throw');
        } catch (OAuthException $e) {
            self::assertStringContainsString('Could not resolve host', $e->getMessage(), 'the real cause survives (fail-loud law)');
            self::assertInstanceOf(HttpTransportException::class, $e->getPrevious());
        }
    }

    public function testRefreshRefusesAnEmptyToken(): void
    {
        $flow = new OAuth2(new RecordingOAuthClient(), $this->signer());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('without a refresh token');

        $flow->refresh(new StubOAuthProvider(), new OAuthConfig('cid', 'secret', self::REDIRECT), '');
    }

    public function testRefreshCarriesNoPkce(): void
    {
        $client = new RecordingOAuthClient();
        $flow = new OAuth2($client, $this->signer());
        $provider = new StubOAuthProvider();
        $config = new OAuthConfig('cid', 'secret', self::REDIRECT);

        $client->queue(new HttpResponse(200, \json_encode(['access_token' => 'fresh']), ['content-type' => 'application/json']));
        $token = $flow->refresh($provider, $config, 'r-1');

        self::assertSame('fresh', $token->accessToken);
        $body = [];
        \parse_str((string) $client->requests[0]['options']['raw_body'], $body);
        self::assertSame('refresh_token', $body['grant_type']);
        self::assertArrayNotHasKey('code_verifier', $body, 'RFC 8707: refresh grants carry no PKCE');
    }

    // ── registry + legacy surface pins ───────────────────────────────

    public function testRegistryRoundTripAndUnknownProvider(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new StubOAuthProvider('github'));

        self::assertTrue($registry->has('github'));
        self::assertSame(['github'], $registry->names());
        self::assertSame('github', $registry->get('github')->name());

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Unknown OAuth provider');

        $registry->get('nope');
    }

    public function testLegacyClassRunsThroughTheSharedDoor(): void
    {
        // Q3 kept the name; the heart is the hardened client now — pinned so a
        // future edit cannot quietly re-grow raw cURL here.
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/OAuth2.php');

        self::assertStringNotContainsString('curl_', $src);
        self::assertStringContainsString('HttpTransportException', $src);
    }

    private function signer(?OAuthStateCache $cache = null): StateSigner
    {
        return new StateSigner('test-secret', $cache ?? new OAuthStateCache());
    }
}
