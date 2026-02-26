<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Introduced in Phase 3.3 to replace exit() in Controller::goto().
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Exception;

/**
 * Exception thrown to trigger an HTTP redirect.
 *
 * Replaces the direct exit() call in Controller::goto(). The top-level
 * handler in main.php catches this, sends the Location header, and terminates
 * the response cleanly without exit().
 */
class RedirectException extends HttpException
{
    /** @var string The target URL to redirect to */
    private string $url;

    /** @var int The HTTP redirect status code (301, 302, etc.) */
    private int $redirectCode;

    /**
     * @param string $url The redirect target URL
     * @param int $redirectCode The HTTP status code (default: 301 Moved Permanently)
     */
    public function __construct(string $url, int $redirectCode = 301)
    {
        $this->url = $url;
        $this->redirectCode = $redirectCode;
        parent::__construct($redirectCode, "Redirect to {$url}");
    }

    /**
     * Get the redirect target URL.
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the redirect HTTP status code.
     *
     * @return int
     */
    public function getRedirectCode(): int
    {
        return $this->redirectCode;
    }
}
