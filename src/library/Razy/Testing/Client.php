<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Testing;

use Closure;
use Razy\Application;
use Razy\Domain;
use Razy\Error;
use Razy\Exception\HttpException;
use Razy\Http\Headers;
use Razy\Http\RequestBody;
use Razy\RuntimeMode;
use Throwable;
use WeakReference;

/**
 * RZ-15: in-process HTTP test client.
 *
 * Runs a web request through a real dist inside the test process: the
 * modules load, the route table, middleware, CSRF/authz doors, controllers
 * and error pages all run as they would under FPM. Each request boots the
 * dist afresh. Status, headers, cookies and body come back as a
 * {@see TestResponse}.
 *
 * ```php
 * $client = new Client(['/' => 'mysite'], 'shop.example.test');
 * $response = $client->get('/products/', ['page' => 2]);
 * $this->assertSame(200, $response->status());
 *
 * $response = $client->postJson('/api/cart/', ['sku' => 'A1']);
 * $this->assertSame('ok', $response->json()['status']);
 * ```
 *
 * The host's framework constants (SYSTEM_ROOT, SITES_FOLDER, DATA_FOLDER …)
 * must be defined, as in Razy's own `tests/bootstrap.php`.
 *
 * Limits: headers a module sends with PHP's `header()` are not captured (use
 * `Razy\Http\Headers::send()` or `Razy\Http\Response`); the session is the
 * `$_SESSION` array, kept by the client between requests (no session files);
 * cookies set by responses are sent back on later requests.
 *
 * @license MIT
 */
final class Client
{
    /** @var array<string, string> Header name => value sent with every request */
    private array $headers = [];

    /** @var array<string, string> Cookie jar */
    private array $cookies = [];

    /** @var array<string, mixed> Emulated session array, kept between requests */
    private array $session = [];

    /** The domain of the request in flight (module library autoloading) */
    private ?Domain $domain = null;

    /** @var Closure(string): void|null */
    private ?Closure $autoloader = null;

    /**
     * @param array<string, string> $sites URL path => dist code (`code[@tag]`), as in sites.inc.php for one host
     * @param string $host Request host
     * @param int $port Request port
     * @param bool $https Whether requests are HTTPS
     */
    public function __construct(
        private readonly array $sites,
        private readonly string $host = 'localhost',
        private readonly int $port = 80,
        private readonly bool $https = false,
    ) {
    }

    public function __destruct()
    {
        if ($this->autoloader !== null) {
            \spl_autoload_unregister($this->autoloader);
        }
    }

    /**
     * Send a header with every later request.
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Put a cookie in the jar.
     */
    public function withCookie(string $name, string $value): static
    {
        $this->cookies[$name] = $value;

        return $this;
    }

    /**
     * The cookie jar.
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * The emulated session (read or seed it, e.g. to log a user in).
     *
     * @return array<string, mixed>
     */
    public function &session(): array
    {
        return $this->session;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function get(string $path, array $query = [], array $headers = []): TestResponse
    {
        return $this->request('GET', $this->withQuery($path, $query), $headers);
    }

    /**
     * POST a form (application/x-www-form-urlencoded).
     *
     * @param array<string, mixed> $form
     * @param array<string, string> $headers
     */
    public function post(string $path, array $form = [], array $headers = []): TestResponse
    {
        $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];

        return $this->request('POST', $path, $headers, \http_build_query($form), $form);
    }

    /**
     * Send JSON with any method (default POST).
     *
     * @param array<string, string> $headers
     */
    public function postJson(string $path, mixed $data, array $headers = [], string $method = 'POST'): TestResponse
    {
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        return $this->request($method, $path, $headers, (string) \json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * Send any request.
     *
     * @param string $method HTTP method
     * @param string $uri Path with optional query string, relative to the host root
     * @param array<string, string> $headers Request headers (win over withHeader() defaults)
     * @param string|null $body Raw body (what php://input would hold)
     * @param array<string, mixed> $post Parsed form fields for $_POST
     */
    public function request(string $method, string $uri, array $headers = [], ?string $body = null, array $post = []): TestResponse
    {
        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE, $_REQUEST, $_FILES, $_SESSION ?? null];
        $level = \ob_get_level();
        // per-request headers win over withHeader() defaults (case-insensitive: later $_SERVER keys overwrite)
        $this->install(\strtoupper($method), $uri, [...$this->pairs($this->headers), ...$this->pairs($headers)], $body, $post);

        $domain = null;
        \ob_start();
        try {
            $domain = $this->dispatch($uri);
        } finally {
            $output = '';
            while (\ob_get_level() > $level) {
                $output = (string) \ob_get_clean() . $output;
            }
            $captured = Headers::stopCapture();
            RuntimeMode::simulateWeb(false);
            RequestBody::override(null);
            $this->session = $_SESSION ?? [];
            try {
                $domain?->dispose();
            } finally {
                // a module's __onDispose may throw: still leave the process as it was found
                [$_SERVER, $_GET, $_POST, $_COOKIE, $_REQUEST, $_FILES, $session] = $saved;
                if ($session === null) {
                    unset($_SESSION);
                } else {
                    $_SESSION = $session;
                }
            }
        }

        $response = new TestResponse($captured['status'], $captured['headers'], $output);
        // in send order: a later live cookie survives an earlier delete of the same name, as in a browser
        foreach ($response->cookieChanges() as ['name' => $name, 'value' => $value, 'delete' => $delete]) {
            if ($delete) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $response;
    }

    /**
     * Route the request like src/main.php does for one FPM request.
     */
    private function dispatch(string $uri): ?Domain
    {
        $path = \explode('?', $uri, 2)[0];
        $path = $path === '' ? '/' : $path;
        $domain = null;
        try {
            $domain = new Domain(new Application(), $this->host, '', $this->sites);
            $this->registerAutoloader($domain);
            $distributor = $domain->matchQuery($path);
            if ($distributor === null || !$distributor->matchRoute()) {
                Error::show404();
            }
        } catch (HttpException) {
            // the response (404, redirect, XHR) was already sent
        } catch (Throwable $e) {
            Error::showException($e);
        }

        return $domain;
    }

    /**
     * @param list<array{0: string, 1: string}> $headers
     * @param array<string, mixed> $post
     */
    private function install(string $method, string $uri, array $headers, ?string $body, array $post): void
    {
        $query = [];
        $queryString = \explode('?', $uri, 2)[1] ?? '';
        \parse_str($queryString, $query);

        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $queryString,
            'SERVER_NAME' => $this->host,
            'HTTP_HOST' => $this->host . (\in_array($this->port, [80, 443], true) ? '' : ':' . $this->port),
            'SERVER_PORT' => (string) $this->port,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_TIME' => \time(),
            'REQUEST_TIME_FLOAT' => \microtime(true),
        ] + \array_intersect_key($_SERVER, \array_flip(['argv', 'argc', 'PATH', 'HOME']));
        if ($this->https) {
            $server['HTTPS'] = 'on';
        }
        foreach ($headers as [$name, $value]) {
            $key = \strtoupper(\str_replace('-', '_', $name));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }
        if ($body !== null) {
            $server['CONTENT_LENGTH'] = (string) \strlen($body);
        }
        if ($this->cookies !== []) {
            $server['HTTP_COOKIE'] = \implode('; ', \array_map(
                static fn (string $n, string $v): string => \rawurlencode($n) . '=' . \rawurlencode($v),
                \array_keys($this->cookies),
                $this->cookies,
            ));
        }

        $_SERVER = $server;
        $_GET = $query;
        $_POST = $post;
        $_COOKIE = $this->cookies;
        $_REQUEST = $post + $query;
        $_FILES = [];
        $_SESSION = $this->session;

        RequestBody::override($body ?? '');
        RuntimeMode::simulateWeb(true);
        Headers::startCapture();
    }

    /**
     * Module `library/` classes load through the dist, as Application::host() arranges under FPM.
     */
    private function registerAutoloader(Domain $domain): void
    {
        $this->domain = $domain;
        if ($this->autoloader === null) {
            // Weak: a closure holding $this would keep the client alive on the SPL stack, so __destruct never ran.
            $self = WeakReference::create($this);
            $this->autoloader = static function (string $className) use ($self): void {
                $self->get()?->domain?->autoload($className);
            };
            \spl_autoload_register($this->autoloader);
        }
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(array $headers): array
    {
        $pairs = [];
        foreach ($headers as $name => $value) {
            $pairs[] = [(string) $name, $value];
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function withQuery(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        return $path . (\str_contains($path, '?') ? '&' : '?') . \http_build_query($query);
    }
}
