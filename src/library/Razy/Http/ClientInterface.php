<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Http;

/**
 * The injectable HTTP seam (OAuth dossier S1).
 *
 * Deliberately narrow: it carries only the verbs an OAuth2 core needs, and
 * returns the concrete HttpResponse. S2's OAuth2 core and every S3 provider
 * receive a `ClientInterface` in their constructor so tests can inject a fake
 * and run with **zero network** — the sole reason this interface exists.
 *
 * `HttpClient` is the one production implementation; the fluent configurators
 * (baseUrl/withToken/timeout/…) stay on the concrete class and are NOT part of
 * this contract, so a fake need not mirror them.
 */
interface ClientInterface
{
    /**
     * Send a GET request.
     *
     * @param string $url Full URL or path relative to a base URL
     * @param array<string,mixed> $query Query parameters
     */
    public function get(string $url, array $query = []): HttpResponse;

    /**
     * Send a POST request.
     *
     * @param array<string,mixed> $data Body (encoded per the configured body format)
     */
    public function post(string $url, array $data = []): HttpResponse;

    /**
     * Send a PUT request.
     *
     * @param array<string,mixed> $data
     */
    public function put(string $url, array $data = []): HttpResponse;

    /**
     * Send a PATCH request.
     *
     * @param array<string,mixed> $data
     */
    public function patch(string $url, array $data = []): HttpResponse;

    /**
     * Send a DELETE request.
     *
     * @param array<string,mixed> $data
     */
    public function delete(string $url, array $data = []): HttpResponse;

    /**
     * Send a HEAD request.
     *
     * @param array<string,mixed> $query
     */
    public function head(string $url, array $query = []): HttpResponse;

    /**
     * Send an OPTIONS request.
     */
    public function options(string $url): HttpResponse;

    /**
     * Send an arbitrary request.
     *
     * @param string $method HTTP method
     * @param string $url Full URL or relative path
     * @param array<string,mixed> $options Request options: 'query', 'body', 'headers'
     */
    public function send(string $method, string $url, array $options = []): HttpResponse;
}
