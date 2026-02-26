<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Http\HttpException;
use Razy\Http\HttpResponse;

#[CoversClass(HttpResponse::class)]
#[CoversClass(HttpException::class)]
class HttpResponseTest extends TestCase
{
    // ── Status helpers ──────────────────────────────────────────

    public function testStatusReturnsCode(): void
    {
        $r = new HttpResponse(200, '');
        $this->assertSame(200, $r->status());
    }

    public function testSuccessfulForTwoHundredRange(): void
    {
        $this->assertTrue((new HttpResponse(200, ''))->successful());
        $this->assertTrue((new HttpResponse(201, ''))->successful());
        $this->assertTrue((new HttpResponse(299, ''))->successful());
        $this->assertFalse((new HttpResponse(300, ''))->successful());
        $this->assertFalse((new HttpResponse(199, ''))->successful());
    }

    public function testOkAliasesSuccessful(): void
    {
        $r = new HttpResponse(200, '');
        $this->assertSame($r->successful(), $r->ok());
    }

    public function testFailedForClientAndServerErrors(): void
    {
        $this->assertTrue((new HttpResponse(400, ''))->failed());
        $this->assertTrue((new HttpResponse(500, ''))->failed());
        $this->assertFalse((new HttpResponse(200, ''))->failed());
        $this->assertFalse((new HttpResponse(301, ''))->failed());
    }

    public function testRedirectForThreeHundredRange(): void
    {
        $this->assertTrue((new HttpResponse(301, ''))->redirect());
        $this->assertTrue((new HttpResponse(302, ''))->redirect());
        $this->assertTrue((new HttpResponse(399, ''))->redirect());
        $this->assertFalse((new HttpResponse(200, ''))->redirect());
        $this->assertFalse((new HttpResponse(400, ''))->redirect());
    }

    public function testClientError(): void
    {
        $this->assertTrue((new HttpResponse(404, ''))->clientError());
        $this->assertTrue((new HttpResponse(422, ''))->clientError());
        $this->assertFalse((new HttpResponse(500, ''))->clientError());
        $this->assertFalse((new HttpResponse(200, ''))->clientError());
    }

    public function testServerError(): void
    {
        $this->assertTrue((new HttpResponse(500, ''))->serverError());
        $this->assertTrue((new HttpResponse(503, ''))->serverError());
        $this->assertFalse((new HttpResponse(404, ''))->serverError());
    }

    // ── Body ────────────────────────────────────────────────────

    public function testBodyReturnsRawString(): void
    {
        $r = new HttpResponse(200, 'Hello World');
        $this->assertSame('Hello World', $r->body());
    }

    public function testToStringReturnsBody(): void
    {
        $r = new HttpResponse(200, 'body-text');
        $this->assertSame('body-text', (string) $r);
    }

    // ── JSON ────────────────────────────────────────────────────

    public function testJsonDecodesBody(): void
    {
        $data = ['name' => 'Razy', 'version' => 5];
        $r = new HttpResponse(200, \json_encode($data));
        $this->assertSame($data, $r->json());
    }

    public function testJsonReturnsNullForInvalidJson(): void
    {
        $r = new HttpResponse(200, 'not-json');
        $this->assertNull($r->json());
    }

    public function testJsonAsObject(): void
    {
        $r = new HttpResponse(200, '{"a":1}');
        $obj = $r->json(false);
        $this->assertIsObject($obj);
        $this->assertSame(1, $obj->a);
    }

    public function testJsonGetDotNotation(): void
    {
        $data = ['data' => ['users' => [['name' => 'Alice']]]];
        $r = new HttpResponse(200, \json_encode($data));
        $this->assertSame('Alice', $r->jsonGet('data.users.0.name'));
    }

    public function testJsonGetReturnsDefaultForMissingKey(): void
    {
        $r = new HttpResponse(200, '{"a":1}');
        $this->assertSame('default', $r->jsonGet('b.c', 'default'));
    }

    public function testJsonGetOnNonJsonReturnsDefault(): void
    {
        $r = new HttpResponse(200, 'not-json');
        $this->assertNull($r->jsonGet('key'));
    }

    // ── Headers ─────────────────────────────────────────────────

    public function testHeadersReturnsAll(): void
    {
        $headers = ['content-type' => 'text/html', 'x-custom' => 'val'];
        $r = new HttpResponse(200, '', $headers);
        $this->assertSame($headers, $r->headers());
    }

    public function testHeaderReturnsValueCaseInsensitive(): void
    {
        $r = new HttpResponse(200, '', ['content-type' => 'application/json']);
        $this->assertSame('application/json', $r->header('Content-Type'));
    }

    public function testHeaderReturnsDefaultWhenMissing(): void
    {
        $r = new HttpResponse(200, '', []);
        $this->assertSame('fallback', $r->header('x-missing', 'fallback'));
    }

    public function testHasHeader(): void
    {
        $r = new HttpResponse(200, '', ['x-token' => 'abc']);
        $this->assertTrue($r->hasHeader('x-token'));
        $this->assertFalse($r->hasHeader('x-other'));
    }

    public function testContentType(): void
    {
        $r = new HttpResponse(200, '', ['content-type' => 'text/plain']);
        $this->assertSame('text/plain', $r->contentType());
    }

    public function testContentTypeReturnsNullWhenAbsent(): void
    {
        $r = new HttpResponse(200, '', []);
        $this->assertNull($r->contentType());
    }

    // ── Error handling ──────────────────────────────────────────

    public function testThrowDoesNothingOnSuccess(): void
    {
        $r = new HttpResponse(200, 'ok');
        $this->assertSame($r, $r->throw());
    }

    public function testThrowThrowsOnFailure(): void
    {
        $r = new HttpResponse(500, 'Server Error');
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(500);
        $r->throw();
    }

    public function testThrowIfWithTrueCondition(): void
    {
        $r = new HttpResponse(404, 'Not Found');
        $this->expectException(HttpException::class);
        $r->throwIf(true);
    }

    public function testThrowIfWithFalseCondition(): void
    {
        $r = new HttpResponse(404, 'Not Found');
        $this->assertSame($r, $r->throwIf(false));
    }

    // ── HttpException ───────────────────────────────────────────

    public function testHttpExceptionHoldsResponse(): void
    {
        $r = new HttpResponse(503, 'Service Unavailable');
        $ex = new HttpException($r);
        $this->assertSame($r, $ex->getResponse());
        $this->assertSame(503, $ex->getCode());
        $this->assertStringContainsString('503', $ex->getMessage());
    }

    // ── Conversion ──────────────────────────────────────────────

    public function testToArray(): void
    {
        $r = new HttpResponse(201, 'created', ['location' => '/items/1']);
        $arr = $r->toArray();
        $this->assertSame(201, $arr['status']);
        $this->assertSame('created', $arr['body']);
        $this->assertSame('/items/1', $arr['headers']['location']);
    }
}
