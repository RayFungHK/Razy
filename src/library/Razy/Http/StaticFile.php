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

/**
 * A static file served from a fixed root (RZ-09).
 *
 * {@see resolve()} is the traversal guard: it maps a URL path below a root
 * to a real file, or null. It rejects null bytes, backslashes, `.`/`..`
 * and dotfile segments (after one URL decode, and any `%` left after it,
 * so `%2e%2e` and `%252e%252e` both fail), then checks the realpath stays
 * inside the root (symlinks out of it are refused).
 *
 * {@see response()} computes status and headers: the content type from the
 * extension (never sniffed; the response sends `nosniff` itself), `ETag` +
 * `Last-Modified`, 304 on a matching `If-None-Match`, and `Cache-Control`
 * `immutable` only when the caller says the URL is content-hashed.
 *
 * @license MIT
 */
final class StaticFile
{
    /** @var array<string, string> Extension => Content-Type; anything else is application/octet-stream */
    public const TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'xml' => 'application/xml',
        'json' => 'application/json',
        'map' => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'wasm' => 'application/wasm',
        'pdf' => 'application/pdf',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * @param string $path A path returned by {@see resolve()}
     * @param string $etag Validator for the content (a content hash), unquoted
     */
    public function __construct(private readonly string $path, private readonly string $etag)
    {
    }

    /**
     * Map a URL path below `$root` to a real file inside it.
     *
     * @param string $root The directory files may be served from
     * @param string $relative The URL path below the root, still URL-encoded
     *
     * @return string|null The real path, or null when missing or not allowed
     */
    public static function resolve(string $root, string $relative): ?string
    {
        $decoded = \rawurldecode($relative);
        if (\str_contains($decoded, "\0") || \str_contains($decoded, '\\') || \str_contains($decoded, '%')) {
            return null;
        }
        $segments = \array_values(\array_filter(\explode('/', $decoded), static fn (string $s): bool => '' !== $s));
        if (!$segments) {
            return null;
        }
        foreach ($segments as $segment) {
            // '.', '..' and dotfiles (.env, .htaccess, .git) are never served.
            if ('.' === $segment[0]) {
                return null;
            }
        }
        $relativePath = \implode('/', $segments);

        // Phar archives have no symlinks and realpath() cannot see into them;
        // the segment check above is the guard there.
        if (\str_starts_with($root, 'phar://')) {
            $path = \rtrim($root, '/') . '/' . $relativePath;

            return \is_file($path) ? $path : null;
        }

        $realRoot = \realpath($root);
        if (false === $realRoot || !\is_dir($realRoot)) {
            return null;
        }
        $real = \realpath($realRoot . DIRECTORY_SEPARATOR . $relativePath);
        if (false === $real || !\is_file($real) || !\str_starts_with($real, \rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * The Content-Type for a file name, from its extension.
     *
     * @param string $path File name or path
     *
     * @return string
     */
    public static function contentType(string $path): string
    {
        return self::TYPES[\strtolower(\pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /**
     * Status and headers for a request.
     *
     * @param string $method GET or HEAD (HEAD sends no body)
     * @param string|null $ifNoneMatch The request's If-None-Match header
     * @param bool $immutable The URL carries this content's hash
     * @param int $immutableMaxAge max-age for content-hashed URLs
     * @param int $maxAge max-age for other URLs (0 = revalidate every time)
     *
     * @return array{status: int, headers: array<string, string>, body: bool}
     */
    public function response(string $method, ?string $ifNoneMatch, bool $immutable, int $immutableMaxAge, int $maxAge): array
    {
        $headers = [
            'Content-Type' => self::contentType($this->path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $immutable
                ? 'public, max-age=' . $immutableMaxAge . ', immutable'
                : ($maxAge > 0 ? 'public, max-age=' . $maxAge : 'public, max-age=0, must-revalidate'),
            'ETag' => '"' . $this->etag . '"',
        ];
        $mtime = @\filemtime($this->path);
        if (false !== $mtime) {
            $headers['Last-Modified'] = \gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        }

        if (null !== $ifNoneMatch && $this->etagMatches($ifNoneMatch)) {
            return ['status' => 304, 'headers' => $headers, 'body' => false];
        }
        $headers['Content-Length'] = (string) (int) @\filesize($this->path);

        return ['status' => 200, 'headers' => $headers, 'body' => 'HEAD' !== \strtoupper($method)];
    }

    /**
     * Send the response for the current request and stream the file.
     *
     * @param array<string, mixed> $server $_SERVER (REQUEST_METHOD, HTTP_IF_NONE_MATCH)
     * @param bool $immutable The URL carries this content's hash
     * @param int $immutableMaxAge max-age for content-hashed URLs
     * @param int $maxAge max-age for other URLs
     * @param callable(string): void|null $emit Null = header()
     * @param callable(int): void|null $status Null = http_response_code()
     */
    public function send(array $server, bool $immutable, int $immutableMaxAge, int $maxAge, ?callable $emit = null, ?callable $status = null): void
    {
        $ifNoneMatch = isset($server['HTTP_IF_NONE_MATCH']) ? (string) $server['HTTP_IF_NONE_MATCH'] : null;
        $response = $this->response((string) ($server['REQUEST_METHOD'] ?? 'GET'), $ifNoneMatch, $immutable, $immutableMaxAge, $maxAge);

        $emit ??= static function (string $line): void {
            if (!Headers::sent()) {
                Headers::send($line, true);
            }
        };
        $status ??= static function (int $code): void {
            if (!Headers::sent()) {
                Headers::status($code);
            }
        };
        $status($response['status']);
        foreach ($response['headers'] as $name => $value) {
            $emit($name . ': ' . $value);
        }
        if ($response['body']) {
            $in = @\fopen($this->path, 'rb');
            $out = \fopen('php://output', 'wb');
            if ($in && $out) {
                \stream_copy_to_stream($in, $out);
            }
            $in && \fclose($in);
            $out && \fclose($out);
        }
    }

    private function etagMatches(string $ifNoneMatch): bool
    {
        if ('*' === \trim($ifNoneMatch)) {
            return true;
        }
        foreach (\explode(',', $ifNoneMatch) as $candidate) {
            $candidate = \trim($candidate);
            if (\str_starts_with($candidate, 'W/')) {
                $candidate = \substr($candidate, 2);
            }
            if ($candidate === '"' . $this->etag . '"') {
                return true;
            }
        }

        return false;
    }
}
