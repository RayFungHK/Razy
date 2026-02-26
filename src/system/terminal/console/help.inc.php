<?php

/**
 * Console Sub-command: help.
 *
 * Displays the help page for the interactive console shell,
 * listing available sub-commands and their descriptions.
 *
 * @license MIT
 */

namespace Razy;

return function () {
    $this->writeLineLogging('{@s:ub}Console help page' . PHP_EOL, true);
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'help', 'This help.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'list', 'List all modules.'));
};
