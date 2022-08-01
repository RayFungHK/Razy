<?php

namespace Razy;

return function () {
    $this->writeLine('{@s:ub}Console help page' . PHP_EOL, true);
    $this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'help', 'This help.'));
    $this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'list', 'List all modules.'));
};
