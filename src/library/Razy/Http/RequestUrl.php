<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Http;

use Razy\Util\NetworkUtil;
use Razy\Util\PathUtil;

/**
 * RZ-18: the URL facts of the current request, rebuilt for every request.
 *
 * The bootstrap used to `define()` them as constants (HOSTNAME, PORT,
 * SITE_URL_ROOT, RAZY_URL_ROOT, SCRIPT_URL, URL_QUERY, FULL_URL_QUERY). A
 * constant is set once per process, so under a persistent worker
 * (FrankenPHP) every request saw the host and path of the worker's boot,
 * not its own. The framework now reads {@see current()}, which the bootstrap
 * sets for the first request and the worker loop replaces before each
 * request.
 *
 * The constants are still defined for FPM / `php -S` code that reads them,
 * but they are deprecated: under a worker they keep the boot values.
 *
 * @license MIT
 */
final class RequestUrl
{
    private static ?self $current = null;

    /**
     * @param string $scheme `http` or `https`
     * @param string $host Host name, '' or 'UNKNOWN' when the request carried none
     * @param int $port Public port
     * @param string $relativeRoot URL path from the document root to the install ('' at the root)
     * @param string $requestUri Raw REQUEST_URI (path and query string)
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port,
        public readonly string $relativeRoot,
        public readonly string $requestUri,
    ) {
    }

    /**
     * Build from server variables, the way the bootstrap always derived its
     * constants (trusted proxies applied, RZ-04).
     *
     * @param array<string, mixed> $server $_SERVER shape
     * @param string $relativeRoot URL path of the install ({@see relativeRootFrom()})
     */
    public static function fromServer(array $server, string $relativeRoot = ''): self
    {
        $request = new Request($server);
        $behindProxy = $request->isFromTrustedProxy();
        $rawHost = $behindProxy ? $request->host() : (string) ($server['SERVER_NAME'] ?? 'UNKNOWN');

        return new self(
            NetworkUtil::isSsl($server) ? 'https' : 'http',
            (string) \preg_replace('/[^a-zA-Z0-9.\-]/', '', $rawHost),
            $behindProxy ? $request->port() : (int) ($server['SERVER_PORT'] ?? 80),
            $relativeRoot,
            (string) ($server['REQUEST_URI'] ?? '/'),
        );
    }

    /**
     * The URL path from the web server's document root to $systemRoot (e.g.
     * `/abc` for an install in `htdocs/abc/`), else dirname(SCRIPT_NAME), else ''.
     *
     * @param array<string, mixed> $server
     */
    public static function relativeRootFrom(array $server, string $systemRoot): string
    {
        $normalizedSystemRoot = \str_replace('\\', '/', $systemRoot);
        $docPrefix = \rtrim(\str_replace('\\', '/', (string) ($server['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docPrefix !== ''
            && ($normalizedSystemRoot === $docPrefix || \str_starts_with($normalizedSystemRoot, $docPrefix . '/'))) {
            $tail = '/' . \trim(\substr($normalizedSystemRoot, \strlen($docPrefix)), '/');
            if ($tail !== '/') {
                return $tail;
            }
        }

        $scriptName = (string) ($server['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '') {
            $dir = \dirname(\str_replace('\\', '/', $scriptName));
            if ($dir !== '/' && $dir !== '.' && $dir !== '\\') {
                return '/' . \trim($dir, '/');
            }
        }

        return '';
    }

    /**
     * The current request's URL facts, or null outside a web request.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Make $url the current request's (bootstrap, worker loop, test client).
     */
    public static function setCurrent(?self $url): void
    {
        self::$current = $url;
    }

    /**
     * `scheme://host[:port]`; 80 and 443 are omitted (was SITE_URL_ROOT).
     */
    public function siteUrlRoot(): string
    {
        return $this->scheme . '://' . $this->host . (\in_array($this->port, [80, 443], true) ? '' : ':' . $this->port);
    }

    /**
     * Site root plus the install's relative root (was RAZY_URL_ROOT).
     */
    public function razyUrlRoot(): string
    {
        return PathUtil::append($this->siteUrlRoot(), $this->relativeRoot);
    }

    /**
     * Absolute URL of the request path, without the query string (was SCRIPT_URL).
     */
    public function scriptUrl(): string
    {
        return PathUtil::append($this->siteUrlRoot(), \explode('?', $this->requestUri, 2)[0]);
    }

    /**
     * The request URI below the relative root, query string included (was FULL_URL_QUERY).
     */
    public function fullUrlQuery(): string
    {
        if ($this->relativeRoot === '') {
            return $this->requestUri;
        }
        \preg_match('/^' . \preg_quote($this->relativeRoot, '/') . '(.+)$/', $this->requestUri, $matches);

        return $matches[1] ?? '';
    }

    /**
     * The tidied path below the relative root, no query string, at least `/`
     * (was URL_QUERY).
     */
    public function urlQuery(): string
    {
        // explode, not strtok: strtok skips a leading '?', so "?x=1" would become the path "x=1/".
        $path = PathUtil::tidy(\explode('?', $this->fullUrlQuery(), 2)[0], true, '/');

        return $path !== '' ? $path : '/';
    }
}
