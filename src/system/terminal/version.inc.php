<?php

/**
 * CLI Command: version.
 *
 * Displays the current Razy framework version.
 *
 * Usage:
 *   php Razy.phar version
 *
 * @license MIT
 */

namespace Razy;

return function () {
    // Output the framework version constant
    $this->writeLineLogging('Razy v' . RAZY_VERSION);
};
