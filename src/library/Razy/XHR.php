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
 * @package Razy
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
    public function allowOrigin(string $origin): XHR
    {
        $origin = trim($origin);
        if ('*' == $origin) {
            // Wildcard: allow all origins
            $this->allowOrigin = $origin;
        } else {
            // Validate each comma-separated origin against URL pattern
            $clips = explode(',', $origin);
            foreach ($clips as $index => $clip) {
                // Remove origins that don't match a valid protocol://domain[:port] pattern
                if (!preg_match('/(?<protocol>\w*)\:\/\/(?:(?<thld>[\w\-]*)(?:\.(?<sld>[\w\-]*))*\.(?<tld>\w*)(?:\:(?<port>\d*))?)/', $clip)) {
                    unset($clips[$index]);
                }
            }
            if (empty($clips)) {
                $this->allowOrigin = SITE_URL_ROOT;
            } else {
                $this->allowOrigin = implode(',', $clips);
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
    public function corp(string $type = ''): XHR
    {
        $this->corp = $type;

        return $this;
    }

    /**
     * Set the parameters and its value.
     *
     * @param $dataset
     *
     * @return $this
     */
    public function data($dataset): XHR
    {
        $this->content = $this->parse($dataset);

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
        if (is_scalar($dataset)) {
            return $dataset;
        }

        // Recursively parse iterable datasets (arrays, collections)
        if (is_iterable($dataset)) {
            foreach ($dataset as &$data) {
                $data = $this->parse($data);
            }

            return $dataset;
        }

        // Objects with __toString can be coerced to string
        if (method_exists($dataset, '__toString')) {
            return strval($dataset);
        }

        return null;
    }

    /**
     * Send the response to client side.
     *
     * @param bool $success
     * @param string $message
     * @return mixed
     */
    public function send(bool $success = true, string $message = ''): mixed
    {
        // Build the standard response envelope
        $response = [
            'result' => $success,
            'hash' => $this->hash,
            'timestamp' => time(),
            'response' => $this->content,
        ];

        $message = trim($message);
        if ($message) {
            $response['message'] = $message;
        }

        if (!empty($this->parameters)) {
            $response['params'] = $this->parameters;
        }
        if ($this->returnAsArray) {
            return $response;
        } else {
            $this->output($response);
            return true;
        }
    }

    /**
     * Output the JSON response with appropriate headers and terminate.
     *
     * Sets Content-Type, CORS, and CORP headers, sends the JSON-encoded
     * response, invokes the completion callback if set, then exits.
     *
     * @param array $data The response data to encode and output
     *
     * @return void
     */
    private function output(array $data): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: ' . $this->allowOrigin);
        header('Cross-Origin-Resource-Policy: ' . $this->corp);
        ob_clean();
        echo json_encode($data);

        if ($this->closure) {
            call_user_func($this->closure);
        }

        throw new Exception\HttpException(200, 'XHR response sent');
    }

    /**
     * Set the parameters and its value.
     *
     * @param string $name
     * @param        $dataset
     *
     * @return $this
     * @throws \Razy\Error
     *
     */
    public function set(string $name, $dataset): XHR
    {
        $name = trim($name);
        if (!$name) {
            throw new \Razy\Error('The name of the parameter cannot be empty.');
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
}
