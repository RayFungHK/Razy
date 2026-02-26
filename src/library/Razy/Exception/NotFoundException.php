<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Introduced in Phase 3.3 to replace exit() in Error::show404().
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Exception;

/**
 * Exception thrown when the requested URL cannot be matched to any route.
 *
 * Replaces the direct exit() call in Error::show404(). The top-level
 * handler in main.php catches this and renders a 404 error page.
 */
class NotFoundException extends HttpException
{
    /**
     * @param string $message A human-readable error message
     */
    public function __construct(string $message = 'The requested URL was not found on this server.')
    {
        parent::__construct(404, $message);
    }
}
