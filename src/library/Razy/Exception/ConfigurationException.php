<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Introduced in Phase 3.3 to replace exit() in Application::loadSiteConfig().
 *
 *
 * @license MIT
 */

namespace Razy\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when the site configuration cannot be loaded or parsed.
 *
 * Replaces the bare exit() call in Application::loadSiteConfig() catch block.
 */
class ConfigurationException extends RuntimeException
{
    /**
     * @param string $message Description of the configuration error
     * @param Throwable|null $previous The original exception that caused the failure
     */
    public function __construct(string $message = 'Failed to load site configuration.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
