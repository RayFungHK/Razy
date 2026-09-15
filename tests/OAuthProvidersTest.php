<?php

declare(strict_types=1);

namespace Razy\Tests;

use CoversNothing;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\OAuthException;
use Razy\Http\HttpResponse;
use Razy\Http\HttpTransportException;
use Razy\Security\OAuth\OAuth2;
use Razy\Security\OAuth\Provider\GithubProvider;
use Razy\Security\OAuth\Provider\GoogleProvider;
use Razy\Security\OAuth\Provider\MicrosoftProvider;
use Razy\Security\OAuth\ProviderRegistry;
use Razy\Security\OAuth\StateSigner;
use Razy\Security\OAuth\TokenResponse;

/**
 * Scripted ClientInterface double for provider fixtures (distinct name from
 * OAuth2CoreTest's double - the suite shares one global namespace).
 */
#[CoversNothing]
final class ProvidersFixtureClient implements \Razy\Http\ClientInterface
{
    /** @var list<array{method: string, url: string, options: array<string,mixed>}> */
    public array $requests = [];

    /** @var list<array{response: HttpResponse|HttpTransportException, match: string}> */
    private array $script = [];

    public function on(string $urlContains, HttpResponse|HttpTransportException $response): void
    {
        $this->script[] = ['response' => $response, 'match' => $urlContains];
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

        foreach ($this->script as $i => $step) {
            if (\str_contains($url, $step['match'])) {
                unset($this->script[$i]);
                $response = $step['response'];

                if ($response instanceof HttpTransportException) {
                    throw $response;
                }

                return $response;
            }
        }

        throw new LogicException('ProvidersFixtureClient has no scripted answer for ' . $url);
    }
}

/**
 * S3 provider-pack tests (dossier): GitHub, Google (sub NOT identity),
 * Microsoft Entra, id_token claim binding, and the legacy SSO pin.
 * Zero sockets - every answer is a fixture.
 */
#[CoversClass(GithubProvider::class)]
#[CoversClass(GoogleProvider::class)]
#[CoversClass(MicrosoftProvider::class)]
#[CoversClass(OAuth2::class)]
class OAuthProvidersTest extends TestCase
{
    // ── id_token claim binding (G11: checked structure, honest about signatures) ──

    private const ISS = '#^https://accounts\.google\.com(/)?$#';

    private static function jwt(array $claims, array $header = ['alg' => 'RS256', 'typ' => 'JWT']): string
    {
        return StateSigner::b64url((string) \json_encode($header))
            . '.' . StateSigner::b64url((string) \json_encode($claims))
            . '.' . StateSigner::b64url('not-a-real-signature');
    }

    // ── GitHub ───────────────────────────────────────────────────────

    public function testGithubFetchUserFallsBackToTheEmailsEndpoint(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('/user/emails', new HttpResponse(200, \json_encode([
            ['email' => 'noisy@example.com', 'verified' => false, 'primary' => false],
            ['email' => 'me@example.com', 'verified' => true, 'primary' => true],
        ]), ['content-type' => 'application/json']));
        // profile WITHOUT the optional email field — the documented common case
        $client->on('api.github.com/user', new HttpResponse(200, \json_encode([
            'id' => 42, 'login' => 'octo', 'name' => null, 'avatar_url' => 'https://avatars.githubusercontent.com/u/42',
        ]), ['content-type' => 'application/json']));

        $user = (new GithubProvider())->fetchUser($client, $this->token());

        self::assertSame('42', $user['id'], 'numeric id normalized to string');
        self::assertSame('me@example.com', $user['email'], 'verified+primary wins over noise');
        self::assertSame('octo', $user['name'], 'login is the fallback display name');
        self::assertStringContainsString('Bearer at-fixture', \json_encode($client->requests[0]['options']['headers']));
        self::assertSame('Razy-OAuth', $client->requests[0]['options']['headers']['User-Agent'], 'GitHub requires a UA');
    }

    public function testGithubProfileFailureFailsLoud(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('api.github.com/user', new HttpResponse(401, \json_encode(['message' => 'Bad credentials']), ['content-type' => 'application/json']));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('no profile (HTTP 401)');

        (new GithubProvider())->fetchUser($client, $this->token());
    }

    public function testGithubTransportFailureCarriesTheCause(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('api.github.com/user', new HttpTransportException('Connection timed out'));

        try {
            (new GithubProvider())->fetchUser($client, $this->token());
            self::fail('transport failure must throw');
        } catch (OAuthException $e) {
            self::assertStringContainsString('Connection timed out', $e->getMessage());
        }
    }

    public function testGithubEndpointsMatchTheDossier(): void
    {
        $p = new GithubProvider();

        self::assertSame('github', $p->name());
        self::assertSame('https://github.com/login/oauth/authorize', $p->authorizeUrl());
        self::assertSame('https://github.com/login/oauth/access_token', $p->tokenUrl());
    }

    // ── Google ───────────────────────────────────────────────────────

    public function testGoogleAuthorizeParamsCarryOfflineAndHd(): void
    {
        $p = new GoogleProvider('openid profile email', 'corp.example', true);
        $params = $p->authorizeParams(['client_id' => 'cid', 'state' => 's']);

        self::assertSame('offline', $params['access_type']);
        self::assertSame('consent', $params['prompt']);
        self::assertSame('corp.example', $params['hd']);
        self::assertSame('cid', $params['client_id'], 'defaults ride through');
    }

    public function testGoogleIdentityIsSubNotEmail(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('userinfo', new HttpResponse(200, \json_encode([
            'sub' => '10874932840923', 'name' => 'Ada', 'email' => 'ada@corp.example', 'picture' => 'https://example.test/p.png',
        ]), ['content-type' => 'application/json']));

        $user = (new GoogleProvider())->fetchUser($client, $this->token());

        self::assertSame('10874932840923', $user['id'], 'the dossier pinned it: sub is the identity');
        self::assertSame('ada@corp.example', $user['email'], 'email rides along as CONTACT data only');
    }

    public function testGoogleLegacySubStillIdentifies(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('userinfo', new HttpResponse(200, \json_encode([
            'legacy_sub' => 'old-style-sub', 'given_name' => 'Ada', 'given_name_en' => 'Ada',
        ]), ['content-type' => 'application/json']));

        $user = (new GoogleProvider())->fetchUser($client, $this->token());

        self::assertSame('old-style-sub', $user['id']);
    }

    public function testGoogleWithoutAnySubFailsLoud(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('userinfo', new HttpResponse(200, \json_encode(['email' => 'nobody@example.com']), ['content-type' => 'application/json']));

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('no identity');

        (new GoogleProvider())->fetchUser($client, $this->token());
    }

    // ── Microsoft ────────────────────────────────────────────────────

    public function testMicrosoftTenantAwareEndpointsAndHints(): void
    {
        $p = new MicrosoftProvider('contoso.onmicrosoft.com', 'User.Read', 'select_account', 'ada@corp.example', '');

        self::assertSame('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize', $p->authorizeUrl());
        $params = $p->authorizeParams(['response_type' => 'code']);
        self::assertSame('select_account', $params['prompt']);
        self::assertSame('ada@corp.example', $params['login_hint']);
        self::assertArrayNotHasKey('domain_hint', $params, 'empty hints never ship');
        self::assertStringContainsString('/logout', $p->signOutUrl('', 'IDTOK', 'https://shop.example/bye'));
    }

    public function testMicrosoftGraphProfilePrefersMailThenUserPrincipalName(): void
    {
        $client = new ProvidersFixtureClient();
        $client->on('graph.microsoft.com/v1.0/me', new HttpResponse(200, \json_encode([
            'id' => 'obj-77', 'displayName' => 'Ada L', 'mail' => null, 'userPrincipalName' => 'Ada@corp.example',
        ]), ['content-type' => 'application/json']));

        $user = (new MicrosoftProvider('common'))->fetchUser($client, $this->token());

        self::assertSame('obj-77', $user['id'], 'Graph object id, not the UPN');
        self::assertSame('Ada@corp.example', $user['email'], 'UPN as contact fallback');
        self::assertSame('$select', \array_key_first($client->requests[0]['options']['query']), 'field selection survived (minimized response)');
    }

    public function testIdTokenClaimsHappyPath(): void
    {
        $claims = ['aud' => 'cid', 'exp' => \time() + 300, 'iss' => 'https://accounts.google.com', 'sub' => 'u-9'];

        $decoded = OAuth2::verifyIdTokenClaims(self::jwt($claims), 'cid', self::ISS);

        self::assertSame('u-9', $decoded['sub']);
    }

    public function testIdTokenAudienceMayBeAnArray(): void
    {
        $claims = ['aud' => ['other-aud', 'cid'], 'exp' => \time() + 300, 'iss' => 'https://accounts.google.com'];

        self::assertSame('cid', OAuth2::verifyIdTokenClaims(self::jwt($claims), 'cid', self::ISS)['aud'][1] ?? 'cid');
    }

    public function testIdTokenAudienceMismatchIsRejected(): void
    {
        $jwt = self::jwt(['aud' => 'someone-elses-client', 'exp' => \time() + 300, 'iss' => 'https://accounts.google.com']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('audience');

        OAuth2::verifyIdTokenClaims($jwt, 'cid', self::ISS);
    }

    public function testIdTokenExpiryIsAbsoluteAndEnforced(): void
    {
        $jwt = self::jwt(['aud' => 'cid', 'exp' => \time() - 1, 'iss' => 'https://accounts.google.com']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('expired');

        OAuth2::verifyIdTokenClaims($jwt, 'cid', self::ISS);
    }

    public function testIdTokenIssuerMustMatchTheProviderPattern(): void
    {
        $jwt = self::jwt(['aud' => 'cid', 'exp' => \time() + 300, 'iss' => 'https://evil-identity.example']);

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('issuer');

        OAuth2::verifyIdTokenClaims($jwt, 'cid', self::ISS);
    }

    public function testIdTokenNonceAndHdBinds(): void
    {
        $claims = ['aud' => 'cid', 'exp' => \time() + 300, 'iss' => 'https://accounts.google.com', 'nonce' => 'n-1', 'hd' => 'corp.example'];
        $jwt = self::jwt($claims);

        // matching binds pass
        OAuth2::verifyIdTokenClaims($jwt, 'cid', self::ISS, 'n-1', 'corp.example');

        try {
            OAuth2::verifyIdTokenClaims($jwt, 'cid', self::ISS, 'n-EVIL');
            self::fail('nonce mismatch must throw');
        } catch (OAuthException $e) {
            self::assertStringContainsString('nonce', $e->getMessage());
        }

        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('Workspace');

        OAuth2::verifyIdTokenClaims($jwt, 'cid', self::ISS, 'n-1', 'attacker.example');
    }

    public function testMalformedIdTokenIsRejected(): void
    {
        $this->expectException(OAuthException::class);
        $this->expectExceptionMessage('three segments');

        OAuth2::verifyIdTokenClaims('two.segments', 'cid', self::ISS);
    }

    // ── registry shape + legacy pin ──────────────────────────────────

    public function testRegistryShipsThePack(): void
    {
        $registry = new ProviderRegistry();
        $registry->register(new GithubProvider());
        $registry->register(new GoogleProvider());
        $registry->register(new MicrosoftProvider());

        self::assertSame(['github', 'google', 'microsoft'], $registry->names());
    }

    public function testLegacySsoRunsThroughTheSharedDoor(): void
    {
        // G1's last two non-client, non-SSE cURL sites died here (S3)
        $sso = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Office365SSO.php');

        self::assertStringNotContainsString('curl_', $sso);
        self::assertStringContainsString('HttpTransportException', $sso);
    }

    private function token(string $access = 'at-fixture'): TokenResponse
    {
        return TokenResponse::fromArray(['access_token' => $access, 'token_type' => 'bearer']);
    }
}
