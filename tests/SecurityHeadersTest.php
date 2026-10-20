<?php

/**
 * RZ-10: security headers and a per-request CSP nonce from dist.php
 * 'security_headers', sent on every response including error pages.
 *
 * @license MIT
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\ConfigurationException;
use Razy\Http\SecurityHeaders;

#[CoversClass(SecurityHeaders::class)]
class SecurityHeadersTest extends TestCase
{
    protected function tearDown(): void
    {
        SecurityHeaders::setCurrent(null);
    }

    public static function invalidConfigs(): array
    {
        return [
            'string' => ['on'],
            'unknown key' => [['cps' => "default-src 'self'"]],
            'header injection' => [['headers' => ['X-A' => "1\r\nSet-Cookie: a=b"]]],
            'bad header name' => [['headers' => ['X A' => '1']]],
            'csp with newline' => [['csp' => "default-src 'self'\nscript-src *"]],
            'negative hsts' => [['hsts' => -1]],
            'referrer not string' => [['referrer_policy' => 1]],
        ];
    }

    public function testAbsentConfigSendsTheNonBreakingBaseline(): void
    {
        $headers = SecurityHeaders::fromConfig([], 'site')->build(false);

        $this->assertSame([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ], $headers);
    }

    public function testFalseOptsOut(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => false], 'site');

        $this->assertFalse($headers->isEnabled());
        $this->assertSame([], $headers->build(true));
    }

    public function testCspNonceIsSubstitutedAndStableWithinARequest(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'csp' => "default-src 'self'; script-src 'self' 'nonce-{nonce}'",
        ]], 'site');

        $nonce = $headers->nonce();
        $this->assertMatchesRegularExpression('~^[A-Za-z0-9+/]{22}==$~', $nonce);
        $this->assertSame($nonce, $headers->nonce());
        $this->assertSame(
            "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; frame-ancestors 'self'",
            $headers->build(false)['Content-Security-Policy'],
        );
    }

    public function testRotateNonceGivesEachRequestAFreshNonce(): void
    {
        $headers = SecurityHeaders::fromConfig([], 'site');
        $first = $headers->rotateNonce();
        $second = $headers->rotateNonce();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $headers->nonce());
    }

    public function testCspArrayFormAndExplicitFrameAncestorsWin(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'csp' => ['default-src' => "'self'", 'img-src' => ["'self'", 'data:'], 'frame-ancestors' => "'none'"],
        ]], 'site')->build(false);

        $this->assertSame("default-src 'self'; img-src 'self' data:; frame-ancestors 'none'", $headers['Content-Security-Policy']);
        $this->assertSame('DENY', $headers['X-Frame-Options']);
    }

    public function testFrameAncestorsListDropsXFrameOptions(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'frame_ancestors' => "'self' https://portal.example.com",
        ]], 'site')->build(false);

        $this->assertSame("frame-ancestors 'self' https://portal.example.com", $headers['Content-Security-Policy']);
        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
    }

    public function testReportOnlyUsesTheReportOnlyHeader(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'csp' => "script-src 'self'",
            'csp_report_only' => true,
        ]], 'site')->build(false);

        $this->assertArrayNotHasKey('Content-Security-Policy', $headers);
        $this->assertSame("script-src 'self'; frame-ancestors 'self'", $headers['Content-Security-Policy-Report-Only']);
    }

    public function testHstsIsSentOnlyOverHttps(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'hsts' => ['max_age' => 31536000, 'include_subdomains' => true],
        ]], 'site');

        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers->build(false));
        $this->assertSame('max-age=31536000; includeSubDomains', $headers->build(true)['Strict-Transport-Security']);
        $this->assertSame(
            'max-age=600',
            SecurityHeaders::fromConfig(['security_headers' => ['hsts' => 600]], 'site')->build(true)['Strict-Transport-Security'],
        );
        $this->assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::fromConfig([], 'site')->build(true));
    }

    public function testHeadersOverrideAddAndRemove(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'referrer_policy' => false,
            'headers' => [
                'Permissions-Policy' => 'camera=()',
                'X-Frame-Options' => false,
                'X-Content-Type-Options' => 'nosniff',
            ],
        ]], 'site')->build(false);

        $this->assertArrayNotHasKey('Referrer-Policy', $headers);
        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertSame('camera=()', $headers['Permissions-Policy']);
    }

    public function testHeaderNamesMatchCaseInsensitively(): void
    {
        $headers = SecurityHeaders::fromConfig(['security_headers' => [
            'headers' => ['x-frame-options' => false, 'referrer-policy' => 'no-referrer'],
        ]], 'site')->build(false);

        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertArrayNotHasKey('Referrer-Policy', $headers);
        $this->assertSame('no-referrer', $headers['referrer-policy']);
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigDiesAtBoot(mixed $config): void
    {
        $this->expectException(ConfigurationException::class);
        SecurityHeaders::fromConfig(['security_headers' => $config], 'site');
    }

    public function testSendEmitsEveryHeaderWithReplace(): void
    {
        $sent = [];
        SecurityHeaders::fromConfig([], 'site')->send(false, function (string $line) use (&$sent): void {
            $sent[] = $line;
        });

        $this->assertSame([
            'X-Content-Type-Options: nosniff',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'X-Frame-Options: SAMEORIGIN',
            "Content-Security-Policy: frame-ancestors 'self'",
        ], $sent);
    }

    public function testCurrentFallsBackToTheBaseline(): void
    {
        $this->assertTrue(SecurityHeaders::current()->isEnabled());

        $off = SecurityHeaders::fromConfig(['security_headers' => false], 'site');
        SecurityHeaders::setCurrent($off);
        $this->assertSame($off, SecurityHeaders::current());
    }

    public function testDistributorAppliesHeadersAndExposesTheNonce(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        $this->assertStringContainsString('SecurityHeaders::fromConfig($config, $distCode)', $src);
        $this->assertSame(2, \substr_count($src, '$this->beginResponse();'), 'matchRoute() and dispatch() both start a response');
        $this->assertStringContainsString("->assign('csp_nonce', \$nonce)", $src);
    }

    public function testErrorPagesSendTheHeadersBeforeTheBody(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Error/ErrorRenderer.php');

        $send404 = \strpos($src, 'SecurityHeaders::sendCurrent();');
        $echo404 = \strpos($src, "echo '<h1>404 Not Found</h1>'");
        $this->assertNotFalse($send404);
        $this->assertLessThan($echo404, $send404);

        $sendException = \strpos($src, 'SecurityHeaders::sendCurrent();', $echo404);
        $echoException = \strpos($src, 'echo $source->output();');
        $this->assertNotFalse($sendException);
        $this->assertLessThan($echoException, $sendException);
    }

    public function testNewDistSkeletonDocumentsTheKey(): void
    {
        $skeleton = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/dist.php.tpl');
        $this->assertStringContainsString("'security_headers'", $skeleton);
    }
}
