<?php

namespace Razy;

class XHR
{
	public const CORP_SAME_SITE    = 'same-site';
	public const CORP_SAME_ORIGIN  = 'same-origin';
	public const CORP_CROSS_ORIGIN = 'cross-origin';

	/**
	 * @var array
	 */
	private array $parameters = [];

	/**
	 * @var mixed
	 */
	private $content = '';

	/**
	 * @var string
	 */
	private string $hash = '';

	/**
	 * @var string
	 */
	private string $corp = self::CORP_CROSS_ORIGIN;

	/**
	 * @var string
	 */
	private string $allowOrigin = SITE_URL_ROOT;

	/**
	 * XHR constructor.
	 */
	public function __construct()
	{
		$this->hash = guid(1);
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
	 * @param string $name
	 * @param        $dataset
	 *
	 * @throws \Razy\Error
	 *
	 * @return $this
	 */
	public function set(string $name, $dataset): XHR
	{
		$name = trim($name);
		if (!$name) {
			throw new Error('The name of the parameter cannot be empty.');
		}
		$this->parameters[$name] = $this->parse($dataset);

		return $this;
	}

	/**
	 * Send the response to client side.
	 *
	 * @param bool   $success
	 * @param string $message
	 */
	public function send(bool $success = true, string $message = '')
	{
		$response = [
			'result'    => $success,
			'hash'      => $this->hash,
			'timestamp' => time(),
			'response'  => $this->content,
		];
		$message = trim($message);
		if ($message) {
			$response['message'] = $message;
		}

		if (!empty($this->parameters)) {
			$response['params'] = $this->parameters;
		}
		$this->output($response);
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
				if (!preg_match('/(?<protocol>\w*)\:\/\/(?:(?:(?<thld>[\w\-]*)(?:\.))?(?<sld>[\w\-]*))\.(?<tld>\w*)(?:\:(?<port>\d*))?/', $clip)) {
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
	 * Parse the dataset into an accepted data format.
	 *
	 * @param $dataset
	 *
	 * @return mixed
	 */
	private function parse($dataset)
	{
		if (is_scalar($dataset)) {
			return $dataset;
		}

		if (method_exists($dataset, '__toString')) {
			return strval($dataset);
		}

		if (is_iterable($dataset)) {
			foreach ($dataset as &$data) {
				$data = $this->parse($data);
			}

			return $dataset;
		}

		return null;
	}

	/**
	 * Output the response to the screen.
	 *
	 * @param array $data
	 */
	private function output(array $data)
	{
		http_response_code(200);
		header('Content-Type: application/json');
		header('Access-Control-Allow-Origin: ' . $this->allowOrigin);
		header('Cross-Origin-Resource-Policy: ' . $this->corp);
		ob_clean();
		echo json_encode($data);

		exit;
	}
}
