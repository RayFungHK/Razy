<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Http\HttpClient;
use Razy\Http\HttpException;
use Razy\Http\HttpResponse;
use RuntimeException;

/**
 * Tests for #10: HTTP Client Wrapper — Fluent API.
 *
 * Tests HttpClient configuration (no network calls), HttpResponse parsing,
 * and HttpException behavior.
 *
 * Sections:
 *  1. HttpResponse — Status Helpers
 *  2. HttpResponse — Body & JSON
 *  3. HttpResponse — Headers
 *  4. HttpResponse — Error Handling
 *  5. HttpResponse — Conversion
 *  6. HttpException
 *  7. HttpClient — Static Factory & Defaults
 *  8. HttpClient — Fluent Configuration
 *  9. HttpClient — URL Building
 * 10. HttpClient — Header Building
 * 11. HttpClient — Interceptors & Retry Config
 */
#[CoversClass(HttpClient::class)]
#[CoversClass(HttpResponse::class)]
#[CoversClass(HttpException::class)]
class HttpClientTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: HttpResponse — Status Helpers
    // ═══════════════════════════════════════════════════════════════

    public function testResponseStatusCode(): void
    {
        $response = new HttpResponse(200, '');
        $this->assertSame(200, $response->status());
    }

    public function testResponseSuccessful200(): void
    {
        $response = new HttpResponse(200, '');
        $this->assertTrue($response->successful());
        $this->assertTrue($response->ok());
        $this->assertFalse($response->failed());
    }

    public function testResponseSuccessful201(): void
    {
        $response = new HttpResponse(201, '');
        $this->assertTrue($response->successful());
    }

    public function testResponseSuccessful299(): void
    {
        $response = new HttpResponse(299, '');
        $this->assertTrue($response->successful());
    }

    public function testResponseFailed400(): void
    {
        $response = new HttpResponse(400, '');
        $this->assertTrue($response->failed());
        $this->assertTrue($response->clientError());
        $this->assertFalse($response->serverError());
        $this->assertFalse($response->successful());
    }

    public function testResponseFailed404(): void
    {
        $response = new HttpResponse(404, '');
        $this->assertTrue($response->failed());
        $this->assertTrue($response->clientError());
    }

    public function testResponseFailed500(): void
    {
        $response = new HttpResponse(500, '');
        $this->assertTrue($response->failed());
        $this->assertTrue($response->serverError());
        $this->assertFalse($response->clientError());
    }

    public function testResponseRedirect301(): void
    {
        $response = new HttpResponse(301, '');
        $this->assertTrue($response->redirect());
        $this->assertFalse($response->successful());
        $this->assertFalse($response->failed());
    }

    public function testResponseRedirect302(): void
    {
        $response = new HttpResponse(302, '');
        $this->assertTrue($response->redirect());
    }

    public function testResponse100IsNotSuccessfulOrFailed(): void
    {
        $response = new HttpResponse(100, '');
        $this->assertFalse($response->successful());
        $this->assertFalse($response->failed());
        $this->assertFalse($response->redirect());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: HttpResponse — Body & JSON
    // ═══════════════════════════════════════════════════════════════

    public function testResponseBody(): void
    {
        $response = new HttpResponse(200, 'Hello World');
        $this->assertSame('Hello World', $response->body());
    }

    public function testResponseBodyEmpty(): void
    {
        $response = new HttpResponse(204, '');
        $this->assertSame('', $response->body());
    }

    public function testResponseJson(): void
    {
        $data = ['name' => 'John', 'age' => 30];
        $response = new HttpResponse(200, \json_encode($data));

        $json = $response->json();
        $this->assertSame($data, $json);
    }

    public function testResponseJsonAssocFalse(): void
    {
        $data = ['name' => 'John'];
        $response = new HttpResponse(200, \json_encode($data));

        $json = $response->json(false);
        $this->assertIsObject($json);
        $this->assertSame('John', $json->name);
    }

    public function testResponseJsonCached(): void
    {
        $response = new HttpResponse(200, '{"key":"value"}');
        $first = $response->json();
        $second = $response->json();
        $this->assertSame($first, $second);
    }

    public function testResponseJsonInvalidBody(): void
    {
        $response = new HttpResponse(200, 'not-json');
        $this->assertNull($response->json());
    }

    public function testResponseJsonNested(): void
    {
        $data = ['data' => ['users' => [['name' => 'Alice'], ['name' => 'Bob']]]];
        $response = new HttpResponse(200, \json_encode($data));

        $json = $response->json();
        $this->assertSame('Alice', $json['data']['users'][0]['name']);
    }

    public function testResponseJsonGetDotNotation(): void
    {
        $data = ['data' => ['users' => [['name' => 'Alice']], 'count' => 1]];
        $response = new HttpResponse(200, \json_encode($data));

        $this->assertSame(1, $response->jsonGet('data.count'));
        $this->assertSame('Alice', $response->jsonGet('data.users.0.name'));
    }

    public function testResponseJsonGetMissing(): void
    {
        $response = new HttpResponse(200, '{"key":"value"}');
        $this->assertNull($response->jsonGet('missing'));
        $this->assertSame('fallback', $response->jsonGet('missing', 'fallback'));
    }

    public function testResponseJsonGetInvalidBody(): void
    {
        $response = new HttpResponse(200, 'not-json');
        $this->assertSame('default', $response->jsonGet('key', 'default'));
    }

    public function testResponseJsonGetTopLevel(): void
    {
        $response = new HttpResponse(200, '{"name":"test"}');
        $this->assertSame('test', $response->jsonGet('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: HttpResponse — Headers
    // ═══════════════════════════════════════════════════════════════

    public function testResponseHeaders(): void
    {
        $headers = ['content-type' => 'application/json', 'x-custom' => 'test'];
        $response = new HttpResponse(200, '', $headers);

        $this->assertSame($headers, $response->headers());
    }

    public function testResponseHeaderGet(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'text/html']);
        $this->assertSame('text/html', $response->header('Content-Type'));
    }

    public function testResponseHeaderCaseInsensitive(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'text/html']);
        $this->assertSame('text/html', $response->header('CONTENT-TYPE'));
        $this->assertSame('text/html', $response->header('content-type'));
    }

    public function testResponseHeaderMissing(): void
    {
        $response = new HttpResponse(200, '', []);
        $this->assertNull($response->header('x-missing'));
    }

    public function testResponseHeaderDefault(): void
    {
        $response = new HttpResponse(200, '', []);
        $this->assertSame('fallback', $response->header('x-missing', 'fallback'));
    }

    public function testResponseHasHeader(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'text/html']);
        $this->assertTrue($response->hasHeader('content-type'));
        $this->assertTrue($response->hasHeader('Content-Type'));
        $this->assertFalse($response->hasHeader('x-missing'));
    }

    public function testResponseContentType(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'application/json; charset=utf-8']);
        $this->assertSame('application/json; charset=utf-8', $response->contentType());
    }

    public function testResponseContentTypeNull(): void
    {
        $response = new HttpResponse(200, '', []);
        $this->assertNull($response->contentType());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: HttpResponse — Error Handling
    // ═══════════════════════════════════════════════════════════════

    public function testThrowOnFailedResponse(): void
    {
        $response = new HttpResponse(404, 'Not Found');
        $this->expectException(HttpException::class);
        $response->throw();
    }

    public function testThrowOnServerError(): void
    {
        $response = new HttpResponse(500, 'Internal Server Error');
        $this->expectException(HttpException::class);
        $response->throw();
    }

    public function testThrowReturnsSelfOnSuccess(): void
    {
        $response = new HttpResponse(200, 'OK');
        $result = $response->throw();
        $this->assertSame($response, $result);
    }

    public function testThrowIfConditionTrue(): void
    {
        $response = new HttpResponse(404, 'Not Found');
        $this->expectException(HttpException::class);
        $response->throwIf(true);
    }

    public function testThrowIfConditionFalse(): void
    {
        $response = new HttpResponse(404, 'Not Found');
        $result = $response->throwIf(false);
        $this->assertSame($response, $result);
    }

    public function testThrowIfConditionTrueSuccessful(): void
    {
        $response = new HttpResponse(200, 'OK');
        $result = $response->throwIf(true);
        $this->assertSame($response, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: HttpResponse — Conversion
    // ═══════════════════════════════════════════════════════════════

    public function testResponseToArray(): void
    {
        $response = new HttpResponse(200, 'body', ['x-test' => 'val']);
        $arr = $response->toArray();

        $this->assertSame(200, $arr['status']);
        $this->assertSame('body', $arr['body']);
        $this->assertSame(['x-test' => 'val'], $arr['headers']);
    }

    public function testResponseToString(): void
    {
        $response = new HttpResponse(200, 'Hello');
        $this->assertSame('Hello', (string) $response);
    }

    public function testResponseToStringEmpty(): void
    {
        $response = new HttpResponse(204, '');
        $this->assertSame('', (string) $response);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: HttpException
    // ═══════════════════════════════════════════════════════════════

    public function testHttpExceptionMessage(): void
    {
        $response = new HttpResponse(404, 'Not Found');
        $exception = new HttpException($response);

        $this->assertSame('HTTP request returned status code 404', $exception->getMessage());
        $this->assertSame(404, $exception->getCode());
    }

    public function testHttpExceptionGetResponse(): void
    {
        $response = new HttpResponse(500, 'Error');
        $exception = new HttpException($response);

        $this->assertSame($response, $exception->getResponse());
        $this->assertSame(500, $exception->getResponse()->status());
    }

    public function testHttpExceptionFromThrow(): void
    {
        $response = new HttpResponse(422, '{"errors":["Invalid"]}');

        try {
            $response->throw();
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame($response, $e->getResponse());
            $this->assertSame(['errors' => ['Invalid']], $e->getResponse()->json());
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: HttpClient — Static Factory & Defaults
    // ═══════════════════════════════════════════════════════════════

    public function testCreateReturnsInstance(): void
    {
        $client = HttpClient::create();
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testConstructorDefaults(): void
    {
        $client = new HttpClient();

        $this->assertSame('', $client->getBaseUrl());
        $this->assertSame([], $client->getHeaders());
        $this->assertSame(30, $client->getTimeout());
        $this->assertSame(10, $client->getConnectTimeout());
        $this->assertTrue($client->getVerifySsl());
        $this->assertSame('json', $client->getBodyFormat());
        $this->assertSame([], $client->getQueryParams());
        $this->assertNull($client->getBasicAuth());
        $this->assertNull($client->getBearerToken());
        $this->assertSame(0, $client->getRetries());
        $this->assertSame(100, $client->getRetryDelay());
        $this->assertNull($client->getUserAgent());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: HttpClient — Fluent Configuration
    // ═══════════════════════════════════════════════════════════════

    public function testBaseUrl(): void
    {
        $client = HttpClient::create()->baseUrl('https://api.example.com/v1/');
        $this->assertSame('https://api.example.com/v1', $client->getBaseUrl());
    }

    public function testBaseUrlTrimsTrailingSlash(): void
    {
        $client = HttpClient::create()->baseUrl('https://example.com/');
        $this->assertSame('https://example.com', $client->getBaseUrl());
    }

    public function testWithHeaders(): void
    {
        $client = HttpClient::create()->withHeaders([
            'X-Custom' => 'value',
            'Accept' => 'text/html',
        ]);

        $headers = $client->getHeaders();
        $this->assertSame('value', $headers['x-custom']);
        $this->assertSame('text/html', $headers['accept']);
    }

    public function testWithHeaderSingle(): void
    {
        $client = HttpClient::create()->withHeader('X-API-Key', 'abc123');
        $this->assertSame('abc123', $client->getHeaders()['x-api-key']);
    }

    public function testWithHeadersMerges(): void
    {
        $client = HttpClient::create()
            ->withHeader('x-first', 'one')
            ->withHeader('x-second', 'two');

        $headers = $client->getHeaders();
        $this->assertSame('one', $headers['x-first']);
        $this->assertSame('two', $headers['x-second']);
    }

    public function testWithHeaderOverrides(): void
    {
        $client = HttpClient::create()
            ->withHeader('x-key', 'old')
            ->withHeader('x-key', 'new');

        $this->assertSame('new', $client->getHeaders()['x-key']);
    }

    public function testWithToken(): void
    {
        $client = HttpClient::create()->withToken('my-token');
        $this->assertSame('my-token', $client->getBearerToken());
    }

    public function testWithBasicAuth(): void
    {
        $client = HttpClient::create()->withBasicAuth('admin', 'secret');
        $auth = $client->getBasicAuth();
        $this->assertSame('admin', $auth['username']);
        $this->assertSame('secret', $auth['password']);
    }

    public function testTimeout(): void
    {
        $client = HttpClient::create()->timeout(60);
        $this->assertSame(60, $client->getTimeout());
    }

    public function testConnectTimeout(): void
    {
        $client = HttpClient::create()->connectTimeout(5);
        $this->assertSame(5, $client->getConnectTimeout());
    }

    public function testWithoutVerifying(): void
    {
        $client = HttpClient::create()->withoutVerifying();
        $this->assertFalse($client->getVerifySsl());
    }

    public function testWithVerifying(): void
    {
        $client = HttpClient::create()->withoutVerifying()->withVerifying();
        $this->assertTrue($client->getVerifySsl());
    }

    public function testAsJson(): void
    {
        $client = HttpClient::create()->asForm()->asJson();
        $this->assertSame('json', $client->getBodyFormat());
    }

    public function testAsForm(): void
    {
        $client = HttpClient::create()->asForm();
        $this->assertSame('form', $client->getBodyFormat());
    }

    public function testAsMultipart(): void
    {
        $client = HttpClient::create()->asMultipart();
        $this->assertSame('multipart', $client->getBodyFormat());
    }

    public function testWithQuery(): void
    {
        $client = HttpClient::create()->withQuery(['page' => 1, 'limit' => 10]);
        $this->assertSame(['page' => 1, 'limit' => 10], $client->getQueryParams());
    }

    public function testWithQueryMerges(): void
    {
        $client = HttpClient::create()
            ->withQuery(['page' => 1])
            ->withQuery(['limit' => 10]);

        $this->assertSame(['page' => 1, 'limit' => 10], $client->getQueryParams());
    }

    public function testUserAgent(): void
    {
        $client = HttpClient::create()->userAgent('Razy/1.0');
        $this->assertSame('Razy/1.0', $client->getUserAgent());
    }

    public function testFluentChainReturnsSelf(): void
    {
        $client = HttpClient::create();

        $this->assertSame($client, $client->baseUrl('http://x'));
        $this->assertSame($client, $client->withHeaders([]));
        $this->assertSame($client, $client->withHeader('x', 'y'));
        $this->assertSame($client, $client->withToken('t'));
        $this->assertSame($client, $client->withBasicAuth('u', 'p'));
        $this->assertSame($client, $client->timeout(1));
        $this->assertSame($client, $client->connectTimeout(1));
        $this->assertSame($client, $client->withoutVerifying());
        $this->assertSame($client, $client->withVerifying());
        $this->assertSame($client, $client->asJson());
        $this->assertSame($client, $client->asForm());
        $this->assertSame($client, $client->asMultipart());
        $this->assertSame($client, $client->withQuery([]));
        $this->assertSame($client, $client->userAgent('x'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: HttpClient — URL Building
    // ═══════════════════════════════════════════════════════════════

    public function testBuildUrlRelative(): void
    {
        $client = new TestableHttpClient();
        $client->baseUrl('https://api.example.com');

        $url = $client->exposeBuildUrl('/users');
        $this->assertSame('https://api.example.com/users', $url);
    }

    public function testBuildUrlAbsolute(): void
    {
        $client = new TestableHttpClient();
        $client->baseUrl('https://api.example.com');

        $url = $client->exposeBuildUrl('https://other.com/path');
        $this->assertSame('https://other.com/path', $url);
    }

    public function testBuildUrlWithQuery(): void
    {
        $client = new TestableHttpClient();
        $client->baseUrl('https://api.example.com');

        $url = $client->exposeBuildUrl('/search', ['q' => 'hello']);
        $this->assertStringContainsString('q=hello', $url);
    }

    public function testBuildUrlMergesQueryParams(): void
    {
        $client = new TestableHttpClient();
        $client->baseUrl('https://api.example.com');
        $client->withQuery(['api_key' => 'xyz']);

        $url = $client->exposeBuildUrl('/search', ['q' => 'hello']);
        $this->assertStringContainsString('api_key=xyz', $url);
        $this->assertStringContainsString('q=hello', $url);
    }

    public function testBuildUrlNoBaseNoQuery(): void
    {
        $client = new TestableHttpClient();
        $url = $client->exposeBuildUrl('https://example.com/test');
        $this->assertSame('https://example.com/test', $url);
    }

    public function testBuildUrlRelativeNoLeadingSlash(): void
    {
        $client = new TestableHttpClient();
        $client->baseUrl('https://api.example.com');

        $url = $client->exposeBuildUrl('users');
        $this->assertSame('https://api.example.com/users', $url);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: HttpClient — Header Building
    // ═══════════════════════════════════════════════════════════════

    public function testBuildRequestHeadersDefaultJson(): void
    {
        $client = new TestableHttpClient();
        $headers = $client->exposeBuildRequestHeaders();

        $headerString = \implode("\n", $headers);
        $this->assertStringContainsString('Content-Type: application/json', $headerString);
        $this->assertStringContainsString('Accept: application/json', $headerString);
    }

    public function testBuildRequestHeadersForm(): void
    {
        $client = new TestableHttpClient();
        $client->asForm();
        $headers = $client->exposeBuildRequestHeaders();

        $headerString = \implode("\n", $headers);
        $this->assertStringContainsString('application/x-www-form-urlencoded', $headerString);
    }

    public function testBuildRequestHeadersCustom(): void
    {
        $client = new TestableHttpClient();
        $client->withHeader('x-custom', 'val');
        $headers = $client->exposeBuildRequestHeaders();

        $headerString = \implode("\n", $headers);
        $this->assertStringContainsString('X-Custom: val', $headerString);
    }

    public function testBuildRequestHeadersPerRequest(): void
    {
        $client = new TestableHttpClient();
        $headers = $client->exposeBuildRequestHeaders(['headers' => ['x-request' => 'per']]);

        $headerString = \implode("\n", $headers);
        $this->assertStringContainsString('X-Request: per', $headerString);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: HttpClient — Interceptors & Retry Config
    // ═══════════════════════════════════════════════════════════════

    public function testRetryConfig(): void
    {
        $client = HttpClient::create()->retry(3, 200, [500, 503]);
        $this->assertSame(3, $client->getRetries());
        $this->assertSame(200, $client->getRetryDelay());
    }

    public function testRetryDefaultDelay(): void
    {
        $client = HttpClient::create()->retry(2);
        $this->assertSame(2, $client->getRetries());
        $this->assertSame(100, $client->getRetryDelay());
    }

    public function testBeforeSendingCallback(): void
    {
        $called = false;
        $client = HttpClient::create()->beforeSending(function () use (&$called) {
            $called = true;
        });

        // Callback stored — not called yet (only on send)
        $this->assertFalse($called);
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testAfterResponseCallback(): void
    {
        $client = HttpClient::create()->afterResponse(function (HttpResponse $r) {
            // no-op
        });

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    public function testWithCurlOption(): void
    {
        $client = HttpClient::create()->withCurlOption(CURLOPT_FOLLOWLOCATION, false);
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    // ═══════════════════════════════════════════════════════════════
    // Additional Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testResponseJsonEmptyBody(): void
    {
        $response = new HttpResponse(200, '');
        $this->assertNull($response->json());
    }

    public function testResponseJsonArray(): void
    {
        $response = new HttpResponse(200, '[1,2,3]');
        $this->assertSame([1, 2, 3], $response->json());
    }

    public function testResponseMultipleStatusChecks(): void
    {
        // 200 — success only
        $r200 = new HttpResponse(200, '');
        $this->assertTrue($r200->successful());
        $this->assertFalse($r200->redirect());
        $this->assertFalse($r200->failed());
        $this->assertFalse($r200->clientError());
        $this->assertFalse($r200->serverError());

        // 301 — redirect only
        $r301 = new HttpResponse(301, '');
        $this->assertFalse($r301->successful());
        $this->assertTrue($r301->redirect());
        $this->assertFalse($r301->failed());

        // 403 — client error
        $r403 = new HttpResponse(403, '');
        $this->assertFalse($r403->successful());
        $this->assertTrue($r403->failed());
        $this->assertTrue($r403->clientError());
        $this->assertFalse($r403->serverError());

        // 502 — server error
        $r502 = new HttpResponse(502, '');
        $this->assertTrue($r502->serverError());
        $this->assertTrue($r502->failed());
        $this->assertFalse($r502->clientError());
    }

    public function testResponseJsonGetDeepNesting(): void
    {
        $data = ['a' => ['b' => ['c' => ['d' => 42]]]];
        $response = new HttpResponse(200, \json_encode($data));
        $this->assertSame(42, $response->jsonGet('a.b.c.d'));
    }

    public function testHttpExceptionIsRuntimeException(): void
    {
        $exception = new HttpException(new HttpResponse(500, ''));
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    public function testClientFullConfigChain(): void
    {
        $client = HttpClient::create()
            ->baseUrl('https://api.example.com')
            ->withToken('tok-123')
            ->timeout(15)
            ->connectTimeout(3)
            ->withoutVerifying()
            ->asForm()
            ->withQuery(['version' => '2'])
            ->userAgent('MyApp/1.0')
            ->retry(3, 500)
            ->withHeader('x-trace', 'abc');

        $this->assertSame('https://api.example.com', $client->getBaseUrl());
        $this->assertSame('tok-123', $client->getBearerToken());
        $this->assertSame(15, $client->getTimeout());
        $this->assertSame(3, $client->getConnectTimeout());
        $this->assertFalse($client->getVerifySsl());
        $this->assertSame('form', $client->getBodyFormat());
        $this->assertSame(['version' => '2'], $client->getQueryParams());
        $this->assertSame('MyApp/1.0', $client->getUserAgent());
        $this->assertSame(3, $client->getRetries());
        $this->assertSame(500, $client->getRetryDelay());
        $this->assertSame('abc', $client->getHeaders()['x-trace']);
    }
}

// ═══════════════════════════════════════════════════════════════
// Test Double: expose protected methods for unit testing
// ═══════════════════════════════════════════════════════════════

class TestableHttpClient extends HttpClient
{
    /**
     * Expose buildUrl for unit testing.
     */
    public function exposeBuildUrl(string $url, array $query = []): string
    {
        return $this->buildUrl($url, $query);
    }

    /**
     * Expose buildRequestHeaders for unit testing.
     */
    public function exposeBuildRequestHeaders(array $options = []): array
    {
        return $this->buildRequestHeaders($options);
    }
}
