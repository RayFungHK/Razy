<?php
/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use DateTime;
use Exception;
use Razy\Terminal\Table;

class Terminal
{
	public const COLOR_DEFAULT      = "\033[39m";
	public const COLOR_BLACK        = "\033[30m";
	public const COLOR_RED          = "\033[31m";
	public const COLOR_GREEN        = "\033[32m";
	public const COLOR_YELLOW       = "\033[33m";
	public const COLOR_BLUE         = "\033[34m";
	public const COLOR_MAGENTA      = "\033[35m";
	public const COLOR_CYAN         = "\033[36m";
	public const COLOR_LIGHTGRAY    = "\033[37m";
	public const COLOR_DARKGRAY     = "\033[90m";
	public const COLOR_LIGHTRED     = "\033[91m";
	public const COLOR_LIGHTGREEN   = "\033[92m";
	public const COLOR_LIGHTYELLOW  = "\033[93m";
	public const COLOR_LIGHTBLUE    = "\033[94m";
	public const COLOR_LIGHTMAGENTA = "\033[95m";
	public const COLOR_LIGHTCYAN    = "\033[96m";
	public const COLOR_WHITE        = "\033[97m";
	public const RESET_STLYE        = "\033[0m";

	public const BACKGROUND_BLACK      = "\033[40m";
	public const BACKGROUND_RED        = "\033[41m";
	public const BACKGROUND_GREEN      = "\033[42m";
	public const BACKGROUND_YELLOW     = "\033[43m";
	public const BACKGROUND_BLUE       = "\033[44m";
	public const BACKGROUND_MAGENTA    = "\033[45m";
	public const BACKGROUND_CYAN       = "\033[46m";
	public const BACKGROUND_LIGHTGRAYE = "\033[47m";

	public const CLEAR_LINE = "\033[0G\033[2K";
	public const NEWLINE    = "\n";
	public const TEXT_BLINK = "\033[5m";

	/**
	 * @var string
	 */
	private string $code = '';

	/**
	 * @var Terminal[]
	 */
	private array $navigation = [];

	/**
	 * @var null|Terminal
	 */
	private ?Terminal $parent;

	/**
	 * @var Closure
	 */
	private Closure $processor;

	/**
	 * @var bool
	 */
	private bool $logging = false;

	/**
	 * @var array
	 */
	private array $logs = [];

	/**
	 * Terminal constructor.
	 *
	 * @param string        $code
	 * @param null|Terminal $parent
	 *
	 * @throws Error
	 */
	public function __construct(string $code, ?Terminal $parent = null)
	{
		$code = trim($code);
		if (!$code) {
			throw new Error('The terminal code is required.');
		}
		$this->code   = $code;
		$this->parent = $parent;
	}

	/**
	 * @return string
	 */
	public static function read(): string
	{
		$response = trim(fgets(STDIN));
		// Remove arrow character to prevent character overlap
		if (preg_match('/\\033\[[ABCD]/', $response)) {
			return preg_replace('/(?:\\033\[[ABCD])+/', '', $response);
		}

		return $response;
	}

	/**
	 * @return string
	 */
	public function getCode(): string
	{
		return $this->code;
	}

	/**
	 * @return Terminal
	 */
	public function showNavigation(): Terminal
	{
		return $this;
	}

	/**
	 * @param Closure $callback
	 *
	 * @return $this
	 */
	public function setProcessor(Closure $callback): Terminal
	{
		$this->processor = $callback->bindTo($this);

		return $this;
	}

	/**
	 * @param array $args
	 *
	 * @return $this
	 */
	public function run(array $args = []): Terminal
	{
		call_user_func_array($this->processor, $args);

		return $this;
	}

	/**
	 * @return null|Terminal
	 */
	public function getParent(): ?Terminal
	{
		return $this->parent;
	}

	/**
	 * @param string $message
	 * @param int    $length
	 *
	 * @return $this
	 */
	public function displayHeader(string $message, int $length = 26): Terminal
	{
		$message = trim($message);
		$length  = max(4, $length);
		$border  = max(16, $length) + 4;
		if ($message) {
			if (strlen($message) > $length) {
				$clips = str_split($message, $length);
			}
		}

		return $this;
	}

	/**
	 * Format the CLI text by using CLI styling tag.
	 *
	 * @param string $message
	 *
	 * @return string
	 */
	public function format(string $message): string
	{
		return preg_replace_callback('/{@(?:((?<code>clear|reset|nl)(?:\|(?&code))*)|((?<config>[cbsk]:\w+)(?:,(?&config))*))}/', function ($matches) {
			$styleString = '';
			if ($matches[3] ?? '') {
				$clips = explode(',', $matches[3]);
				foreach ($clips as $clip) {
					[$style, $value] = explode(':', $clip, 2);
					if ('c' == $style || 'b' === $style) {
						// Font color style
						$value = strtoupper($value);
						$constant = __CLASS__ . '::' . (('c' === $style) ? 'COLOR' : 'BACKGROUND') . '_' . $value;
						if (defined($constant)) {
							$styleString .= constant($constant);
						}
					} elseif ($style = 's') {
						// Text decoration
						$values = array_keys(array_flip(str_split($value)));
						foreach ($values as $styleCode) {
							switch ($styleCode) {
									case 'b':
										$styleString .= "\e[1m";

										break;

									case 'i':
										$styleString .= "\e[3m";

										break;

									case 'u':
										$styleString .= "\e[4m";

										break;

									case 's':
										$styleString .= "\e[9m";

										break;

									case 'k':
										$styleString .= "\e[5m";

										break;
								}
						}
					}
				}
			} else {
				$clips = array_keys(array_flip(explode('|', $matches[1])));
				foreach ($clips as $clip) {
					switch ($clip) {
							case 'reset':
								$styleString .= self::RESET_STLYE;

								break;

							case 'clear':
								$styleString .= self::CLEAR_LINE;

								break;

							case 'nl':
								$styleString .= PHP_EOL;

								break;
						}
				}
			}

			return $styleString;
		}, $message) ?? '';
	}

	/**
	 * @param string   $text
	 * @param null|int $escaped
	 *
	 * @return int
	 */
	public function length(string $text, ?int &$escaped = 0): int
	{
		$escaped = 0;
		if (preg_match_all("/\e\\[(?:\\d+m|[ABCD])/", $text, $matches)) {
			array_walk($matches[0], function (&$value) use (&$lengthOfEscape, &$escaped) {
				$escaped += strlen($value);
			});
		}

		return strlen($text) - $escaped;
	}

	/**
	 * @param string $message
	 * @param bool   $resetStyle
	 * @param string $format
	 *
	 * @return $this
	 */
	public function writeLine(string $message, bool $resetStyle = false, string $format = ''): Terminal
	{
		$message = str_replace("\t", '    ', $message);
		$format  = trim($format);
		$message = ($format) ? sprintf($format, $message) : $message;
		echo $this->format($message) . ($resetStyle ? self::RESET_STLYE : '') . PHP_EOL;
		if ($this->logging) {
			$this->addLog($message);
		}

		return $this;
	}

	/**
	 * @param bool $enable
	 *
	 * @return $this
	 */
	public function logging(bool $enable): Terminal
	{
		$this->logging = $enable;

		return $this;
	}

	/**
	 * @param string $path
	 *
	 * @return bool
	 */
	public function saveLog(string $path): bool
	{
		$length  = 20;
		$content = '';
		$path    = fix_path($path);
		foreach ($this->logs as $log) {
			$content .= sprintf('%-22s%s', '[' . $log[0] . ']', $log[1]) . PHP_EOL;
		}

		$realPath = realpath($path);
		if ($realPath) {
			// If the path is a valid file or directory
			if (is_dir($realPath)) {
				$path = append($path, (new DateTime())->format('Y_m_d_H_i_s') . '_' . $this->code . '.txt');
			}
		} else {
			// If the path is not exists, extract the directory and the file name
			// If no file name is provided, use default file name
			$fileName = (new DateTime())->format('Y_m_d_H_i_s') . '_' . $this->code . '.txt';
			if (!is_dir_path($path)) {
				$fileName = basename($path);
				$path     = dirname($path);
			}

			try {
				// Create directory
				mkdir($path, 0777, true);
			} catch (Exception $e) {
				return false;
			}
			$path = append($path, $fileName);
		}

		try {
			file_put_contents($path, $content);
		} catch (Exception $e) {
			return false;
		}

		return true;
	}

	/**
	 * @param string $message
	 *
	 * @return $this
	 */
	public function addLog(string $message): Terminal
	{
		$message      = trim($message);
		$this->logs[] = [(new DateTime())->format('Y-m-d H:i:s'), $message];

		return $this;
	}

	public function table(): Table
	{
		return new Table($this);
	}

	/**
	 * @return int
	 */
	public function getScreenWidth(): int
	{
		if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
			$setting      = shell_exec('mode');
			$clips        = preg_split('/\-+/', $setting, -1, PREG_SPLIT_NO_EMPTY);
			$terminalInfo = explode("\n", trim(end($clips)));
			if (count($terminalInfo) >= 2) {
				[$name, $value] = explode(':', trim($terminalInfo[1]), 2);

				return (int) $value;
			}

			return 0;
		}

		return (int) shell_exec('tput cols');
	}
}
