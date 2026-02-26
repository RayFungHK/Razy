<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Error;

use Razy\Error;
use Razy\Exception\NotFoundException;
use Razy\Template;
use Razy\Terminal;
use Throwable;
use Razy\Util\PathUtil;
/**
 * Handles rendering of exception pages and error output.
 *
 * Extracted from the Error class to separate rendering/display logic
 * from exception semantics. Supports HTML template-based exception pages
 * in web mode and plain text output in CLI mode.
 *
 * @package Razy\Error
 * @license MIT
 */
class ErrorRenderer
{
    /**
     * Display 404 Not Found error page.
     *
     * @throws NotFoundException
     */
    public static function show404(): void
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

        throw new NotFoundException();
    }

    /**
     * Render the custom exception page for the given exception.
     *
     * In web mode, uses the template engine to render a styled error page
     * with optional debug information (backtrace, console messages).
     * In CLI mode, outputs the exception as plain text.
     *
     * @param Throwable $exception The exception to display
     *
     * @throws Throwable
     */
    public static function showException(Throwable $exception): void
    {
        if (WEB_MODE) {
            // Resolve the exception template: use status-code-specific file if available, fallback to generic
            $tplFolder = PathUtil::append(PHAR_PATH, 'asset', 'exception');
            if (is_file(PathUtil::append($tplFolder, $exception->getCode() . '.html'))) {
                $tplFile = PathUtil::append($tplFolder, $exception->getCode() . '.html');
            } else {
                $tplFile = PathUtil::append($tplFolder, 'any.html');
            }

            $template = new Template();
            $source = $template->load($tplFile);
            $root = $source->getRoot();

            $root->assign([
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'message' => $exception->getMessage(),
                'heading' => ($exception instanceof Error) ? $exception->getHeading() : 'There seems to is something wrong...',
            ]);

            if (ErrorConfig::isDebug()) {
                // Build debug information block with debug message, console output, and stack trace
                $debugBlock = $root->newBlock('debug');
                if ($exception instanceof Error && $debugMessage = $exception->getDebugMessage()) {
                    $debugBlock->assign([
                        'debug_message' => $debugMessage,
                    ]);
                }
                $debugConsole = ErrorConfig::getDebugConsole();
                if (count($debugConsole)) {
                    $debugBlock->newBlock('console')->assign([
                        'console' => implode("\n", $debugConsole),
                    ]);
                }

                // Parse the stack trace string into individual frames for template rendering
                $stacktrace = explode("\n", $exception->getTraceAsString());
                array_pop($stacktrace);

                $index = 0;
                foreach ($stacktrace as $trace) {
                    // Extract trace detail after the "#N " prefix
                    preg_match('/^#\d+ (.+)$/', $trace, $matches);
                    $debugBlock->newBlock('backtrace')->assign([
                        'index' => $index++,
                        'stack' => htmlspecialchars($matches[1]),
                    ]);
                }
            }

            // Capture any buffered output before replacing with the error page
            ErrorConfig::setCached(ob_get_contents());
            ob_clean();
            echo $source->output();
            // Set the HTTP status code; default to 400 if code is non-numeric
            http_response_code(is_numeric($exception->getCode()) ? $exception->getCode() : 400);
        } else {
            echo $exception;
        }
    }
}
