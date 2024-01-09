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
     * The cache buffer output
     * @var string
     */
    private static string $cached = '';
    /**
     * The setting of debug mode
     * @var bool
     */
    private static bool $debug = false;

    /**
     * Error constructor.
     *
     * @param string         $message
     * @param int            $statusCode
     * @param string         $heading
     * @param string         $debugMessage
     * @param null|Throwable $exception
     */
    public function __construct(string $message, int $statusCode = 400, private readonly string $heading = self::DEFAULT_HEADING, private readonly string $debugMessage = '', Throwable $exception = null)
    {
        if (CLI_MODE) {
            Terminal::WriteLine('{@c:red}' . $message, true);
        }
        parent::__construct(nl2br($message), $statusCode, $exception);
    }

    /**
     * Get the cached buffer content
     *
     * @return string
     */
    public static function GetCached(): string
    {
        return self::$cached;
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
     *
     * @return void
     */
    public static function Show404(): void
    {
        ob_clean();
        header('HTTP/1.0 404 Not Found');

        if (WEB_MODE) {
            echo '<h1>404 Not Found</h1>';
            echo 'The requested URL was not found on this server.';
        } else {
            Terminal::WriteLine('{@c:red}404 Not Found', true);
            Terminal::WriteLine('The requested URL was not found on this server', true);
        }

        exit();
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
            $root     = $source->getRoot();

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
}
