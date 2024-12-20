<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;

class XHR
{
    public const CORP_SAME_SITE = 'same-site';
    public const CORP_SAME_ORIGIN = 'same-origin';
    public const CORP_CROSS_ORIGIN = 'cross-origin';

    private string $allowOrigin = SITE_URL_ROOT;
    private mixed $content = '';
    private string $corp = self::CORP_CROSS_ORIGIN;
    private string $hash;
    private array $parameters = [];
    private ?Closure $closure = null;

    /**
     * XHR constructor.
     *
     * @param bool $returnAsArray
     */
    public function __construct(private readonly bool $returnAsArray = false)
    {
        $this->hash = guid(1);
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
            $this->allowOrigin = $origin;
        } else {
            $clips = explode(',', $origin);
            foreach ($clips as $index => $clip) {
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

        if (is_scalar($dataset)) {
            return $dataset;
        }

        if (is_iterable($dataset)) {
            foreach ($dataset as &$data) {
                $data = $this->parse($data);
            }

            return $dataset;
        }

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
     * Output the response to the screen.
     *
     * @param array $data
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

        exit;
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
     * @param callable $closure
     * @return $this
     */
    public function onComplete(callable $closure): static
    {
        $this->closure = $closure(...);

        return $this;
    }
}
