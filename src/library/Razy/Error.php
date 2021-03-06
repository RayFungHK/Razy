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

use Exception;
use Throwable;

class Error extends Exception
{
    public const DEFAULT_HEADING = 'There seems to is something wrong...';

    /**
     * @var bool
     */
    private static bool $debug = false;

    /**
     * @var string
     */
    private static string $cached = '';

    /**
     * @var string
     */
    private string $heading;

    /**
     * @var string
     */
    private string $debugMessage;

    /**
     * Error constructor.
     *
     * @param string         $message
     * @param int            $statusCode
     * @param string         $heading
     * @param string         $debugMessage
     * @param null|Throwable $exception
     */
    public function __construct(string $message, int $statusCode = 400, string $heading = self::DEFAULT_HEADING, string $debugMessage = '', Throwable $exception = null)
    {
        $this->heading      = $heading;
        $this->debugMessage = $debugMessage;
        parent::__construct($message, $statusCode, $exception);
    }

    /**
     * Show the custom exception page by the given exception object.
     *
     * @param Throwable $exception
     *
     * @throws Throwable
     */
    public static function ShowException(Throwable $exception): void
    {
        if (WEB_MODE) {
            $tplFolder = append(PHAR_PATH, 'asset', 'exception');
            if (is_file(append($tplFolder, $exception->getCode() . '.html'))) {
                $tplFile = append($tplFolder, $exception->getCode() . '.html');
            } else {
                $tplFile = append($tplFolder, 'any.html');
            }

            $template = new Template();
            $source   = $template->load($tplFile);
            $root     = $source->getRootBlock();

            $root->assign([
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'message' => $exception->getMessage(),
                'heading' => ($exception instanceof self) ? $exception->getHeading() : 'There seems to is something wrong...',
            ]);

            if (self::$debug) {
                $debugBlock = $root->newBlock('debug');
                if ($exception instanceof self && $debugMessage = $exception->getDebugMessage()) {
                    $debugBlock->assign([
                        'debug_message' => $debugMessage,
                    ]);
                }

                $stacktrace = explode("\n", $exception->getTraceAsString());
                array_pop($stacktrace);

                $index = 0;
                foreach ($stacktrace as $trace) {
                    preg_match('/^#\d+ (.+)$/', $trace, $matches);
                    $debugBlock->newBlock('backtrace')->assign([
                        'index' => $index++,
                        'stack' => $matches[1],
                    ]);
                }
            }

            self::$cached = ob_get_contents();
            ob_clean();
            echo $source->output();
            // Set the status code
            http_response_code(is_numeric($exception->getCode()) ? $exception->getCode() : 400);
        } else {
            echo $exception;
        }

        exit;
    }

    /**
     * @return string
     */
    public static function GetCached(): string
    {
        return self::$cached;
    }

    /**
     * Get the heading.
     *
     * @return string The exception page heading
     */
    public function getHeading(): string
    {
        return $this->heading;
    }

    /**
     * Get the debug message.
     *
     * @return string The exception page heading
     */
    public function getDebugMessage(): string
    {
        return $this->debugMessage;
    }

    /**
     * Enable debug to display the detail backtrack.
     *
     * @param bool $enable
     */
    public static function SetDebug(bool $enable): void
    {
        self::$debug = $enable;
    }

    /**
     * Display 404 Not Found error page.
     */
    public static function Show404(): void
    {
        ob_clean();
        header('HTTP/1.0 404 Not Found');
        echo '<h1>404 Not Found</h1>';
        echo 'The requested URL was not found on this server.';

        exit();
    }
}
