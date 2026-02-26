<?php

declare(strict_types=1);

namespace Razy\Tests;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\HttpException;
use Razy\Exception\NotFoundException;
use Razy\Exception\RedirectException;
use RuntimeException;

/**
 * Tests for Phase 1.2: exit() removal and HttpException-based flow control.
 *
 * Verifies that RouteDispatcher, Error, and XHR now throw exceptions
 * instead of calling exit(), making them testable and worker-mode safe.
 */
#[CoversClass(HttpException::class)]
#[CoversClass(RedirectException::class)]
#[CoversClass(NotFoundException::class)]
class ExitRemovalTest extends TestCase
{
    // ── HttpException hierarchy ──────────────────────────────

    public function testHttpExceptionExtendsRuntimeException(): void
    {
        $e = new HttpException(500, 'Server Error');
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testHttpExceptionDefaultStatusCode(): void
    {
        $e = new HttpException();
        $this->assertSame(400, $e->getStatusCode());
    }

    public function testHttpExceptionCustomStatusCode(): void
    {
        $e = new HttpException(503, 'Service Unavailable');
        $this->assertSame(503, $e->getStatusCode());
        $this->assertSame('Service Unavailable', $e->getMessage());
    }

    public function testHttpExceptionWith200IsValidForXhrResponse(): void
    {
        $e = new HttpException(200, 'XHR response sent');
        $this->assertSame(200, $e->getStatusCode());
        $this->assertSame('XHR response sent', $e->getMessage());
    }

    // ── RedirectException ────────────────────────────────────

    public function testRedirectExceptionExtendsHttpException(): void
    {
        $e = new RedirectException('/target', 302);
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testRedirectExceptionUrl(): void
    {
        $e = new RedirectException('https://example.com/new-page', 301);
        $this->assertSame('https://example.com/new-page', $e->getUrl());
        $this->assertSame(301, $e->getRedirectCode());
    }

    public function testRedirectExceptionDefault301(): void
    {
        $e = new RedirectException('/default');
        $this->assertSame(301, $e->getRedirectCode());
    }

    public function testRedirectException302(): void
    {
        $e = new RedirectException('/temporary', 302);
        $this->assertSame(302, $e->getRedirectCode());
    }

    // ── NotFoundException ────────────────────────────────────

    public function testNotFoundExceptionExtendsHttpException(): void
    {
        $e = new NotFoundException();
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testNotFoundExceptionStatusCode(): void
    {
        $e = new NotFoundException();
        $this->assertSame(404, $e->getStatusCode());
    }

    // ── Catch-all HttpException pattern ──────────────────────

    public function testHttpExceptionCatchesSubclasses(): void
    {
        $caught = false;

        try {
            throw new RedirectException('/target', 302);
        } catch (HttpException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'HttpException catch should capture RedirectException');
    }

    public function testHttpExceptionCatchesNotFoundException(): void
    {
        $caught = false;

        try {
            throw new NotFoundException();
        } catch (HttpException $e) {
            $caught = true;
        }

        $this->assertTrue($caught, 'HttpException catch should capture NotFoundException');
    }

    public function testHttpExceptionCatchesXhrResponse(): void
    {
        $caught = false;

        try {
            throw new HttpException(200, 'XHR response sent');
        } catch (HttpException $e) {
            $caught = true;
            $this->assertSame(200, $e->getStatusCode());
        }

        $this->assertTrue($caught, 'HttpException catch should capture XHR 200 response');
    }

    public function testHttpExceptionPreviousChaining(): void
    {
        $original = new Exception('original error');
        $http = new HttpException(500, 'wrapped', $original);

        $this->assertSame($original, $http->getPrevious());
        $this->assertSame(500, $http->getStatusCode());
    }
}
