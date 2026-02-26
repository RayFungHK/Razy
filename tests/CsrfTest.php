<?php

/**
 * This file is part of Razy v0.5.
 *
 * Comprehensive tests for the CSRF Protection system:
 * - CsrfTokenManager (token generation, validation, regeneration, session storage)
 * - CsrfMiddleware (safe methods, state-changing methods, token extraction,
 *   excluded routes, custom handlers, token rotation)
 * - TokenMismatchException (exception metadata)
 * - Integration (session + token manager + middleware lifecycle)
 * - Edge cases (empty tokens, tampered tokens, concurrent sessions)
 *
 * @package Razy
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Contract\MiddlewareInterface;
use Razy\Csrf\CsrfMiddleware;
use Razy\Csrf\CsrfTokenManager;
use Razy\Csrf\TokenMismatchException;
use Razy\Session\Driver\ArrayDriver;
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use RuntimeException;

#[CoversClass(CsrfTokenManager::class)]
#[CoversClass(CsrfMiddleware::class)]
#[CoversClass(TokenMismatchException::class)]
class CsrfTest extends TestCase
{
    private Session $session;

    private CsrfTokenManager $tokenManager;

    protected function setUp(): void
    {
        $this->session = new Session(new ArrayDriver(), new SessionConfig());
        $this->session->start();
        $this->tokenManager = new CsrfTokenManager($this->session);
    }

    protected function tearDown(): void
    {
        // Clean up any $_POST / $_SERVER modifications
        unset($_POST['_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Safe Methods (Pass Through)
    // ══════════════════════════════════════════════════════════════

    public static function safeMethodProvider(): array
    {
        return [
            'GET' => ['GET'],
            'HEAD' => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
            'get (lowercase)' => ['get'],
            'Get (mixed case)' => ['Get'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — State-Changing Methods (Require Token)
    // ══════════════════════════════════════════════════════════════

    public static function unsafeMethodProvider(): array
    {
        return [
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'PATCH' => ['PATCH'],
            'DELETE' => ['DELETE'],
            'post (lowercase)' => ['post'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfTokenManager — Token Generation
    // ══════════════════════════════════════════════════════════════

    public function testTokenReturnsNonEmptyString(): void
    {
        $token = $this->tokenManager->token();

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testTokenIs64Characters(): void
    {
        $token = $this->tokenManager->token();

        $this->assertSame(64, \strlen($token));
    }

    public function testTokenIsHexadecimal(): void
    {
        $token = $this->tokenManager->token();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokenIsPersistentAcrossCalls(): void
    {
        $token1 = $this->tokenManager->token();
        $token2 = $this->tokenManager->token();

        $this->assertSame($token1, $token2);
    }

    public function testTokenIsStoredInSession(): void
    {
        $token = $this->tokenManager->token();

        $this->assertSame($token, $this->session->get(CsrfTokenManager::SESSION_KEY));
    }

    public function testTokenGeneratesNewWhenSessionEmpty(): void
    {
        $this->assertFalse($this->session->has(CsrfTokenManager::SESSION_KEY));

        $token = $this->tokenManager->token();

        $this->assertTrue($this->session->has(CsrfTokenManager::SESSION_KEY));
        $this->assertNotEmpty($token);
    }

    public function testTokenIsUniqueAcrossSessions(): void
    {
        $token1 = $this->tokenManager->token();

        // Create new session + token manager
        $session2 = new Session(new ArrayDriver(), new SessionConfig());
        $session2->start();
        $manager2 = new CsrfTokenManager($session2);
        $token2 = $manager2->token();

        $this->assertNotSame($token1, $token2);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfTokenManager — Validation
    // ══════════════════════════════════════════════════════════════

    public function testValidateReturnsTrueForMatchingToken(): void
    {
        $token = $this->tokenManager->token();

        $this->assertTrue($this->tokenManager->validate($token));
    }

    public function testValidateReturnsFalseForWrongToken(): void
    {
        $this->tokenManager->token();

        $this->assertFalse($this->tokenManager->validate('wrong_token'));
    }

    public function testValidateReturnsFalseForEmptyString(): void
    {
        $this->tokenManager->token();

        $this->assertFalse($this->tokenManager->validate(''));
    }

    public function testValidateReturnsFalseWhenNoTokenStored(): void
    {
        // No token() call — nothing in session
        $this->assertFalse($this->tokenManager->validate('any_token'));
    }

    public function testValidateReturnsFalseForPartialMatch(): void
    {
        $token = $this->tokenManager->token();

        // Submit only the first half
        $this->assertFalse($this->tokenManager->validate(\substr($token, 0, 32)));
    }

    public function testValidateReturnsFalseForTokenWithExtraChars(): void
    {
        $token = $this->tokenManager->token();

        $this->assertFalse($this->tokenManager->validate($token . 'extra'));
    }

    public function testValidateIsCaseSensitive(): void
    {
        $token = $this->tokenManager->token();

        // Hex tokens are lowercase, uppercase should fail
        $this->assertFalse($this->tokenManager->validate(\strtoupper($token)));
    }

    public function testValidateUsesTimingSafeComparison(): void
    {
        // We can't directly test hash_equals timing, but we ensure the
        // method is used by verifying behavior: correct token succeeds,
        // wrong token fails, regardless of how "close" it is
        $token = $this->tokenManager->token();

        // Flip one character
        $tampered = \substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a');

        $this->assertTrue($this->tokenManager->validate($token));
        $this->assertFalse($this->tokenManager->validate($tampered));
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfTokenManager — Regeneration
    // ══════════════════════════════════════════════════════════════

    public function testRegenerateReturnsNewToken(): void
    {
        $original = $this->tokenManager->token();
        $regenerated = $this->tokenManager->regenerate();

        $this->assertNotSame($original, $regenerated);
        $this->assertSame(64, \strlen($regenerated));
    }

    public function testRegenerateInvalidatesOldToken(): void
    {
        $original = $this->tokenManager->token();
        $this->tokenManager->regenerate();

        $this->assertFalse($this->tokenManager->validate($original));
    }

    public function testRegenerateUpdatesSession(): void
    {
        $this->tokenManager->token();
        $newToken = $this->tokenManager->regenerate();

        $this->assertSame($newToken, $this->session->get(CsrfTokenManager::SESSION_KEY));
    }

    public function testRegenerateNewTokenIsValid(): void
    {
        $this->tokenManager->token();
        $newToken = $this->tokenManager->regenerate();

        $this->assertTrue($this->tokenManager->validate($newToken));
    }

    public function testMultipleRegenerations(): void
    {
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $this->tokenManager->regenerate();
        }

        // All tokens should be unique
        $this->assertCount(5, \array_unique($tokens));

        // Only the last token should be valid
        foreach ($tokens as $i => $token) {
            if ($i < 4) {
                $this->assertFalse($this->tokenManager->validate($token));
            } else {
                $this->assertTrue($this->tokenManager->validate($token));
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfTokenManager — hasToken / clearToken
    // ══════════════════════════════════════════════════════════════

    public function testHasTokenReturnsFalseInitially(): void
    {
        $this->assertFalse($this->tokenManager->hasToken());
    }

    public function testHasTokenReturnsTrueAfterGeneration(): void
    {
        $this->tokenManager->token();

        $this->assertTrue($this->tokenManager->hasToken());
    }

    public function testClearTokenRemovesFromSession(): void
    {
        $this->tokenManager->token();
        $this->tokenManager->clearToken();

        $this->assertFalse($this->tokenManager->hasToken());
        $this->assertNull($this->session->get(CsrfTokenManager::SESSION_KEY));
    }

    public function testClearTokenThenValidateFails(): void
    {
        $token = $this->tokenManager->token();
        $this->tokenManager->clearToken();

        $this->assertFalse($this->tokenManager->validate($token));
    }

    public function testTokenRegeneratesAfterClear(): void
    {
        $original = $this->tokenManager->token();
        $this->tokenManager->clearToken();
        $newToken = $this->tokenManager->token();

        $this->assertNotSame($original, $newToken);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfTokenManager — Accessors
    // ══════════════════════════════════════════════════════════════

    public function testGetSession(): void
    {
        $this->assertSame($this->session, $this->tokenManager->getSession());
    }

    public function testSessionKeyConstant(): void
    {
        $this->assertSame('_csrf_token', CsrfTokenManager::SESSION_KEY);
    }

    // ══════════════════════════════════════════════════════════════
    //  TokenMismatchException
    // ══════════════════════════════════════════════════════════════

    public function testExceptionDefaultMessage(): void
    {
        $e = new TokenMismatchException();

        $this->assertSame('CSRF token mismatch.', $e->getMessage());
        $this->assertSame(419, $e->getCode());
    }

    public function testExceptionCustomMessage(): void
    {
        $e = new TokenMismatchException('Custom CSRF error');

        $this->assertSame('Custom CSRF error', $e->getMessage());
        $this->assertSame(419, $e->getCode());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $e = new TokenMismatchException();

        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testExceptionCanBeCaught(): void
    {
        $caught = false;

        try {
            throw new TokenMismatchException();
        } catch (TokenMismatchException $e) {
            $caught = true;
            $this->assertSame(419, $e->getCode());
        }

        $this->assertTrue($caught);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Interface Contract
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewareImplementsInterface(): void
    {
        $mw = new CsrfMiddleware($this->tokenManager);

        $this->assertInstanceOf(MiddlewareInterface::class, $mw);
    }

    public function testMiddlewareGetTokenManager(): void
    {
        $mw = new CsrfMiddleware($this->tokenManager);

        $this->assertSame($this->tokenManager, $mw->getTokenManager());
    }

    #[DataProvider('safeMethodProvider')]
    public function testSafeMethodsPassThrough(string $method): void
    {
        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => $method, 'route' => '/form'],
            fn (array $ctx) => 'handler_result',
        );

        $this->assertSame('handler_result', $result);
    }

    public function testSafeMethodDoesNotRequireToken(): void
    {
        // No token generated — GET should still pass
        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'GET'],
            fn (array $ctx) => 'passed',
        );

        $this->assertSame('passed', $result);
    }

    #[DataProvider('unsafeMethodProvider')]
    public function testUnsafeMethodBlocksWithoutToken(string $method): void
    {
        $this->tokenManager->token(); // Ensure token exists in session
        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => $method, 'route' => '/submit'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'ok';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    #[DataProvider('unsafeMethodProvider')]
    public function testUnsafeMethodAllowsWithValidTokenInContext(string $method): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => $method, 'route' => '/submit', '_token' => $token],
            fn (array $ctx) => 'handler_result',
        );

        $this->assertSame('handler_result', $result);
    }

    public function testPostBlocksWithWrongToken(): void
    {
        $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST', '_token' => 'wrong_token'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'ok';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Token Extraction
    // ══════════════════════════════════════════════════════════════

    public function testExtractsTokenFromPostBody(): void
    {
        $token = $this->tokenManager->token();
        $_POST['_token'] = $token;

        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'POST', 'route' => '/form'],
            fn (array $ctx) => 'form_processed',
        );

        $this->assertSame('form_processed', $result);
    }

    public function testExtractsTokenFromHeader(): void
    {
        $token = $this->tokenManager->token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'POST', 'route' => '/api/action'],
            fn (array $ctx) => 'api_result',
        );

        $this->assertSame('api_result', $result);
    }

    public function testExtractsTokenFromContext(): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'POST', '_token' => $token],
            fn (array $ctx) => 'context_result',
        );

        $this->assertSame('context_result', $result);
    }

    public function testPostBodyTakesPriorityOverHeader(): void
    {
        $token = $this->tokenManager->token();
        $_POST['_token'] = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrong_header_token';

        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'POST'],
            fn (array $ctx) => 'post_wins',
        );

        $this->assertSame('post_wins', $result);
    }

    public function testCustomTokenExtractor(): void
    {
        $token = $this->tokenManager->token();

        $extractor = fn (array $ctx) => $ctx['custom_csrf'] ?? null;

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            tokenExtractor: $extractor,
        );

        $result = $mw->handle(
            ['method' => 'POST', 'custom_csrf' => $token],
            fn (array $ctx) => 'custom_extracted',
        );

        $this->assertSame('custom_extracted', $result);
    }

    public function testCustomTokenExtractorOverridesDefaults(): void
    {
        $token = $this->tokenManager->token();
        $_POST['_token'] = $token;

        // Custom extractor returns null — should fail even though $_POST has the token
        $extractor = fn (array $ctx) => null;

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            tokenExtractor: $extractor,
        );

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Excluded Routes
    // ══════════════════════════════════════════════════════════════

    public function testExcludedRouteBypassesValidation(): void
    {
        $mw = new CsrfMiddleware(
            $this->tokenManager,
            excludedRoutes: ['/api/webhook', '/api/callback'],
        );

        $result = $mw->handle(
            ['method' => 'POST', 'route' => '/api/webhook'],
            fn (array $ctx) => 'webhook_ok',
        );

        $this->assertSame('webhook_ok', $result);
    }

    public function testNonExcludedRouteStillValidated(): void
    {
        $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            excludedRoutes: ['/api/webhook'],
        );

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST', 'route' => '/form/submit'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testExclusionIsExactMatch(): void
    {
        $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            excludedRoutes: ['/api/webhook'],
        );

        // Partial match should NOT be excluded
        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST', 'route' => '/api/webhooks'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testMultipleExcludedRoutes(): void
    {
        $mw = new CsrfMiddleware(
            $this->tokenManager,
            excludedRoutes: ['/hook1', '/hook2', '/hook3'],
        );

        foreach (['/hook1', '/hook2', '/hook3'] as $route) {
            $result = $mw->handle(
                ['method' => 'POST', 'route' => $route],
                fn (array $ctx) => 'bypassed',
            );
            $this->assertSame('bypassed', $result, "Route $route should be excluded");
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Custom Rejection Handler
    // ══════════════════════════════════════════════════════════════

    public function testCustomMismatchHandler(): void
    {
        $this->tokenManager->token();
        $capturedContext = null;

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            onMismatch: function (array $ctx) use (&$capturedContext) {
                $capturedContext = $ctx;

                return ['error' => 'csrf_failed', 'code' => 419];
            },
        );

        $result = $mw->handle(
            ['method' => 'POST', 'route' => '/submit', '_token' => 'bad'],
            fn (array $ctx) => 'should_not_reach',
        );

        $this->assertIsArray($result);
        $this->assertSame('csrf_failed', $result['error']);
        $this->assertNotNull($capturedContext);
        $this->assertSame('/submit', $capturedContext['route']);
    }

    public function testCustomMismatchHandlerReceivesFullContext(): void
    {
        $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            onMismatch: fn (array $ctx) => $ctx,
        );

        $context = ['method' => 'DELETE', 'route' => '/item/5', 'extra' => 'data'];
        $result = $mw->handle($context, fn ($ctx) => 'x');

        $this->assertSame($context, $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Token Rotation
    // ══════════════════════════════════════════════════════════════

    public function testTokenRotationOnSuccess(): void
    {
        $originalToken = $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            rotateOnSuccess: true,
        );

        $mw->handle(
            ['method' => 'POST', '_token' => $originalToken],
            fn (array $ctx) => 'ok',
        );

        // Original token should no longer be valid
        $this->assertFalse($this->tokenManager->validate($originalToken));

        // A new token should be active
        $newToken = $this->tokenManager->token();
        $this->assertNotSame($originalToken, $newToken);
        $this->assertTrue($this->tokenManager->validate($newToken));
    }

    public function testNoRotationByDefault(): void
    {
        $originalToken = $this->tokenManager->token();

        $mw = new CsrfMiddleware($this->tokenManager);

        $mw->handle(
            ['method' => 'POST', '_token' => $originalToken],
            fn (array $ctx) => 'ok',
        );

        // Token should still be valid (no rotation)
        $this->assertTrue($this->tokenManager->validate($originalToken));
    }

    public function testRotationDoesNotOccurOnMismatch(): void
    {
        $token = $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            rotateOnSuccess: true,
        );

        $mw->handle(
            ['method' => 'POST', '_token' => 'wrong'],
            fn (array $ctx) => 'x',
        );

        // Original token should still be valid (rotation only on success)
        $this->assertTrue($this->tokenManager->validate($token));
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Context Passthrough
    // ══════════════════════════════════════════════════════════════

    public function testContextPassedUnmodifiedToHandler(): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $originalContext = [
            'method' => 'POST',
            'route' => '/users',
            'url_query' => '/users?action=create',
            'arguments' => ['action' => 'create'],
            '_token' => $token,
        ];

        $capturedContext = null;
        $mw->handle($originalContext, function (array $ctx) use (&$capturedContext) {
            $capturedContext = $ctx;

            return 'ok';
        });

        $this->assertSame($originalContext, $capturedContext);
    }

    public function testHandlerReturnValuePreserved(): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $values = [
            'string' => 'hello',
            'int' => 42,
            'array' => ['key' => 'val'],
            'null' => null,
            'bool' => false,
        ];

        foreach ($values as $type => $expected) {
            $result = $mw->handle(
                ['method' => 'POST', '_token' => $token],
                fn (array $ctx) => $expected,
            );
            $this->assertSame($expected, $result, "Return type '$type' not preserved");
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  CsrfMiddleware — Default Method Handling
    // ══════════════════════════════════════════════════════════════

    public function testDefaultMethodIsGetWhenMissing(): void
    {
        $mw = new CsrfMiddleware($this->tokenManager);

        // No 'method' key — should default to GET and pass through
        $result = $mw->handle(
            ['route' => '/page'],
            fn (array $ctx) => 'default_get',
        );

        $this->assertSame('default_get', $result);
    }

    public function testWildcardMethodTreatedAsUnsafe(): void
    {
        $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        // '*' is not in the safe methods list
        $handlerCalled = false;
        $mw->handle(
            ['method' => '*', 'route' => '/any'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Session + TokenManager + Middleware
    // ══════════════════════════════════════════════════════════════

    public function testFullFormSubmissionLifecycle(): void
    {
        // 1. Session starts, token generated for form
        $token = $this->tokenManager->token();

        // 2. Form submitted with token
        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'POST', 'route' => '/contact', '_token' => $token],
            fn (array $ctx) => 'form_submitted',
        );

        $this->assertSame('form_submitted', $result);
    }

    public function testTokenPersistsAcrossMultipleSubmissions(): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        // Multiple submissions with the same token (no rotation)
        for ($i = 0; $i < 5; $i++) {
            $result = $mw->handle(
                ['method' => 'POST', '_token' => $token],
                fn (array $ctx) => 'ok',
            );
            $this->assertSame('ok', $result);
        }
    }

    public function testTokenRotationLifecycle(): void
    {
        $mw = new CsrfMiddleware($this->tokenManager, rotateOnSuccess: true);

        // First request
        $token1 = $this->tokenManager->token();
        $mw->handle(
            ['method' => 'POST', '_token' => $token1],
            fn (array $ctx) => 'ok',
        );

        // Second request — must use new token
        $token2 = $this->tokenManager->token();
        $this->assertNotSame($token1, $token2);

        $result = $mw->handle(
            ['method' => 'POST', '_token' => $token2],
            fn (array $ctx) => 'ok2',
        );
        $this->assertSame('ok2', $result);

        // Using old token should fail
        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST', '_token' => $token1],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );
        $this->assertFalse($handlerCalled);
    }

    public function testSessionRegenerationPreservesToken(): void
    {
        $token = $this->tokenManager->token();
        $oldId = $this->session->getId();

        // Regenerate session ID (e.g., after login)
        $this->session->regenerate();

        $this->assertNotSame($oldId, $this->session->getId());

        // Token should still be valid (stored in session data, not tied to ID)
        $this->assertTrue($this->tokenManager->validate($token));
    }

    public function testCsrfAfterSessionDestroy(): void
    {
        $token = $this->tokenManager->token();

        // Destroy session (e.g., logout)
        $this->session->destroy();

        // Restart session
        $this->session->start();

        // Old token should no longer be valid
        $this->assertFalse($this->tokenManager->validate($token));
    }

    public function testMiddlewareWithSessionMiddlewarePipeline(): void
    {
        // Simulate: SessionMiddleware → CsrfMiddleware → Handler
        $session = new Session(new ArrayDriver(), new SessionConfig());
        $csrfManager = new CsrfTokenManager($session);

        // Start session (like SessionMiddleware would)
        $session->start();

        // Generate token (like rendering a form)
        $token = $csrfManager->token();

        // Process request through CSRF middleware
        $csrfMw = new CsrfMiddleware($csrfManager);

        $result = $csrfMw->handle(
            ['method' => 'POST', '_token' => $token],
            fn (array $ctx) => 'pipeline_ok',
        );

        $this->assertSame('pipeline_ok', $result);

        // Save session (like SessionMiddleware would)
        $session->save();
    }

    // ══════════════════════════════════════════════════════════════
    //  Edge Cases
    // ══════════════════════════════════════════════════════════════

    public function testEmptyRouteInContext(): void
    {
        $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST', 'route' => ''],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        // No token provided, should block
        $this->assertFalse($handlerCalled);
    }

    public function testNoRouteInContext(): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        $result = $mw->handle(
            ['method' => 'POST', '_token' => $token],
            fn (array $ctx) => 'ok',
        );

        $this->assertSame('ok', $result);
    }

    public function testTokenFieldConstant(): void
    {
        $this->assertSame('_token', CsrfMiddleware::TOKEN_FIELD);
    }

    public function testTokenHeaderConstant(): void
    {
        $this->assertSame('X-CSRF-TOKEN', CsrfMiddleware::TOKEN_HEADER);
    }

    public function testEmptyContextDefaults(): void
    {
        $mw = new CsrfMiddleware($this->tokenManager);

        // Empty context — method defaults to GET, should pass
        $result = $mw->handle([], fn (array $ctx) => 'default');

        $this->assertSame('default', $result);
    }

    public function testSameTokenAcrossMultipleCsrfManagers(): void
    {
        // Two managers sharing the same session should see the same token
        $manager1 = new CsrfTokenManager($this->session);
        $manager2 = new CsrfTokenManager($this->session);

        $token = $manager1->token();

        $this->assertTrue($manager2->validate($token));
        $this->assertSame($token, $manager2->token());
    }

    public function testClearTokenOnOneManagerAffectsOther(): void
    {
        $manager1 = new CsrfTokenManager($this->session);
        $manager2 = new CsrfTokenManager($this->session);

        $token = $manager1->token();
        $manager1->clearToken();

        $this->assertFalse($manager2->validate($token));
        $this->assertFalse($manager2->hasToken());
    }

    public function testExceptionInHandlerDoesNotAffectToken(): void
    {
        $token = $this->tokenManager->token();
        $mw = new CsrfMiddleware($this->tokenManager);

        try {
            $mw->handle(
                ['method' => 'POST', '_token' => $token],
                function (array $ctx) {
                    throw new RuntimeException('handler error');
                },
            );
        } catch (RuntimeException) {
            // Expected
        }

        // Token should still be valid
        $this->assertTrue($this->tokenManager->validate($token));
    }

    public function testExcludedRouteWithSafeMethodStillPasses(): void
    {
        $mw = new CsrfMiddleware(
            $this->tokenManager,
            excludedRoutes: ['/api/webhook'],
        );

        $result = $mw->handle(
            ['method' => 'GET', 'route' => '/api/webhook'],
            fn (array $ctx) => 'safe_excluded',
        );

        $this->assertSame('safe_excluded', $result);
    }

    public function testNullTokenInPost(): void
    {
        $this->tokenManager->token();
        $_POST['_token'] = null;

        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testEmptyStringTokenInPost(): void
    {
        $this->tokenManager->token();
        $_POST['_token'] = '';

        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testIntTokenInPostIsRejected(): void
    {
        $this->tokenManager->token();
        $_POST['_token'] = 12345;

        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testNoSessionTokenMeansAllPostsBlocked(): void
    {
        // Fresh session, no token generated
        $mw = new CsrfMiddleware($this->tokenManager);

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST', '_token' => 'any_random_string'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testCustomExtractorReturningEmptyStringFails(): void
    {
        $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            tokenExtractor: fn (array $ctx) => '',
        );

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }

    public function testCustomExtractorReturningIntFails(): void
    {
        $this->tokenManager->token();

        $mw = new CsrfMiddleware(
            $this->tokenManager,
            tokenExtractor: fn (array $ctx) => 42,
        );

        $handlerCalled = false;
        $mw->handle(
            ['method' => 'POST'],
            function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            },
        );

        $this->assertFalse($handlerCalled);
    }
}
