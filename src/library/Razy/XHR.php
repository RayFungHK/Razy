<?php

/**
 * This file is part of Razy v0.5.
 *
 * XHR (XMLHttpRequest) response handler for the Razy framework.
 * Provides a fluent API for building and sending JSON API responses
 * with CORS and CORP header support.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 *
 * @license MIT
 */

namespace Razy;

use Closure;
use Razy\Util\StringUtil;

/**
 * XHR response builder and sender for the Razy framework.
 *
 * Constructs JSON responses with configurable CORS (Access-Control-Allow-Origin)
 * and CORP (Cross-Origin-Resource-Policy) headers. Supports response data parsing,
 * additional parameter injection, and optional completion callbacks.
 *
 * @class XHR
 */
class XHR
{
    /** @var string CORP policy: same-site */
    public const CORP_SAME_SITE = 'same-site';

    /** @var string CORP policy: same-origin */
    public const CORP_SAME_ORIGIN = 'same-origin';

    /** @var string CORP policy: cross-origin (most permissive) */
    public const CORP_CROSS_ORIGIN = 'cross-origin';

    /** @var string Allowed origin(s) for CORS header */
    private string $allowOrigin = SITE_URL_ROOT;

    /** @var mixed The response body content */
    private mixed $content = '';

    /** @var string Cross-Origin Resource Policy header value */
    private string $corp = self::CORP_CROSS_ORIGIN;

    /** @var string Unique response hash for request tracking */
    private string $hash;

    /** @var array<string, mixed> Additional response parameters */
    private array $parameters = [];

    /** @var Closure|null Optional callback invoked after response output */
    private ?Closure $closure = null;

    /** @var int HTTP status for the next {@see output()} */
    private int $httpStatus = 200;

    /** @var array<string, mixed>|null Pre-built oaao SPA envelope for {@see sendEnvelope()} */
    private ?array $envelopeBody = null;

    /**
     * XHR constructor.
     *
     * @param bool $returnAsArray
     */
    public function __construct(private readonly bool $returnAsArray = false)
    {
        $this->hash = StringUtil::guid(1);
    }

    /**
     * Set the allow origin (CORS).
     *
     * @param string $origin
     *
     * @return $this
     */
    public function allowOrigin(string $origin): self
    {
        $origin = \trim($origin);
        if ('*' == $origin) {
            // Wildcard: allow all origins
            $this->allowOrigin = $origin;
        } else {
            // Validate each comma-separated origin against URL pattern
            $clips = \explode(',', $origin);
            foreach ($clips as $index => $clip) {
                // Validate each origin: must be http(s)://domain[:port] with anchored match
                if (!\preg_match('/^https?:\/\/(?:[\w\-]+\.)*[\w\-]+(?::\d+)?$/', \trim($clip))) {
                    unset($clips[$index]);
                }
            }
            if (empty($clips)) {
                $this->allowOrigin = SITE_URL_ROOT;
            } else {
                $this->allowOrigin = \implode(',', $clips);
            }
        }

        return $this;
    }

    /**
     * Set the Cross-Origin Resource Policy (CORP).
     *
     * @param string $type
     *
     * @return $this
     */
    public function corp(string $type = ''): self
    {
        // Validate against allowed CORP values to prevent header injection
        $allowed = ['same-origin', 'same-site', 'cross-origin', ''];
        $this->corp = \in_array($type, $allowed, true) ? $type : 'same-origin';

        return $this;
    }

    /**
     * Set the parameters and its value.
     *
     * @param $dataset
     *
     * @return $this
     */
    public function data($dataset): self
    {
        $this->content = $this->parse($dataset);

        return $this;
    }

    /**
     * Set HTTP status for the next terminal output ({@see send()}, {@see sendData()}, {@see sendEnvelope()}).
     */
    public function responseCode(int $status): self
    {
        $this->httpStatus = \max(100, \min(599, $status));

        return $this;
    }

    /**
     * oaao SPA envelope — {@code {success, data?, message?, params?, hash, timestamp}}.
     *
     * @return array<string, mixed>|true
     */
    public function sendData(bool $success, string $message = ''): mixed
    {
        $response = [
            'success' => $success,
            'hash' => $this->hash,
            'timestamp' => \time(),
        ];
        $message = \trim($message);
        if ($message !== '') {
            $response['message'] = $message;
        }
        if ($success) {
            $parsed = $this->parse($this->content);
            if ($parsed !== null && $parsed !== '') {
                $response['data'] = $parsed;
            }
        }
        if (!empty($this->parameters)) {
            $response['params'] = $this->parameters;
        }
        if ($this->returnAsArray) {
            return $response;
        }
        $this->output($response);

        return true;
    }

    /**
     * Emit a pre-built flat JSON body NOW (headers + {@see responseCode()}),
     * ending dispatch via the HttpException control-flow the readiness 503
     * rail already uses.
     *
     * v1.2 history (FORMREQUEST-RAIL M1): this call once only STORED the
     * body and required a separate sendEnvelope() tail — which every
     * consumer in the repo forgot (queue-admin and oauth shipped empty
     * bodies for their whole JSON surface). An API used wrong by 100% of
     * its callers is the bug; the emission moved here. sendEnvelope()
     * remains idempotent for any chain that still ends with it.
     *
     * @param array<string, mixed> $body
     */
    public function responseAsBody(array $body): self
    {
        $this->envelopeBody = $body;

        if ($this->returnAsArray) {
            return $this; // array mode: the caller pulls via sendEnvelope()
        }

        $this->envelopeBody = null;
        $this->output($body); // throws HttpException: dispatch ends gracefully

        return $this;
    }

    /**
     * Pull the body set by {@see responseAsBody()} in array mode
     * (`$this->xhr(true)`); a no-op returning true in normal mode, where
     * responseAsBody() has already emitted (kept so pre-v1.2 chains ending
     * with this call still read correctly).
     *
     * @return array<string, mixed>|true
     */
    public function sendEnvelope(): mixed
    {
        if ($this->envelopeBody === null) {
            return true; // already emitted by responseAsBody()
        }
        $response = $this->envelopeBody;
        if ($this->returnAsArray) {
            return $response;
        }
        $this->output($response);

        return true;
    }

    /**
     * Send the response to client side.
     *
     * @param bool $success
     * @param string $message
     *
     * @return mixed
     */
    public function send(bool $success = true, string $message = ''): mixed
    {
        // Build the standard response envelope
        $response = [
            'result' => $success,
            'hash' => $this->hash,
            'timestamp' => \time(),
            'response' => $this->content,
        ];

        $message = \trim($message);
        if ($message) {
            $response['message'] = $message;
        }

        if (!empty($this->parameters)) {
            $response['params'] = $this->parameters;
        }
        if ($this->returnAsArray) {
            return $response;
        }
        $this->output($response);
        return true;
    }

    /**
     * Set the parameters and its value.
     *
     * @param string $name
     * @param $dataset
     *
     * @return $this
     *
     * @throws Error
     */
    public function set(string $name, $dataset): self
    {
        $name = \trim($name);
        if (!$name) {
            throw new Error('The name of the parameter cannot be empty.');
        }
        $this->parameters[$name] = $this->parse($dataset);

        return $this;
    }

    /**
     * Register a closure to be called after the response is sent.
     *
     * Useful for cleanup tasks or post-response processing.
     *
     * @param callable $closure The callback to invoke after output
     *
     * @return static Chainable
     */
    public function onComplete(callable $closure): static
    {
        $this->closure = $closure(...);

        return $this;
    }

    /**
     * Parse the dataset into an accepted data format.
     *
     * @param $dataset
     *
     * @return mixed
     */
    private function parse($dataset): mixed
    {
        if ($dataset === null) {
            return null;
        }

        // Scalar values (string, int, float, bool) are returned as-is
        if (\is_scalar($dataset)) {
            return $dataset;
        }

        // Recursively parse iterable datasets (arrays, collections)
        if (\is_iterable($dataset)) {
            foreach ($dataset as &$data) {
                $data = $this->parse($data);
            }

            return $dataset;
        }

        // Objects with __toString can be coerced to string
        if (\method_exists($dataset, '__toString')) {
            return (string) $dataset;
        }

        return null;
    }

    /**
     * Output the JSON response with appropriate headers and terminate.
     *
     * Sets Content-Type, CORS, and CORP headers, sends the JSON-encoded
     * response, invokes the completion callback if set, then exits.
     *
     * @param array $data The response data to encode and output
     */
    private function output(array $data): void
    {
        // PHP 8.5 warns when http_response_code() arrives after the status line
        // is already sent (CLI/test runs accumulate that state across cases; the
        // 8.5 CI matrix caught it live, 2026-09). In normal web dispatch headers
        // are not yet sent and behavior is byte-identical — the guard only skips
        // a call PHP would refuse anyway.
        \header('HTTP/1.1 ' . $this->httpStatus, true, $this->httpStatus);

        \header('Content-Type: application/json');
        \header('Access-Control-Allow-Origin: ' . $this->allowOrigin);
        \header('Cross-Origin-Resource-Policy: ' . $this->corp);
        if ($this->allowOrigin !== '*') {
            \header('Vary: Origin');
        }
        if (\ob_get_level() > 0) {
            \ob_clean();
        }
        echo \json_encode($data);

        if ($this->closure) {
            \call_user_func($this->closure);
        }

        throw new Exception\HttpException($this->httpStatus, 'XHR response sent');
    }
}
