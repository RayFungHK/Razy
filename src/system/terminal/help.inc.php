<?php

namespace Razy;

return function () {
    $this->writeLineLogging('Usage: php Razy.phar [options] [args...]' . PHP_EOL);
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'build', 'Build the Razy environment in specified location.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'help', 'This help.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'fix', 'Fix the sites configuration and .htaccess routing.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'man', 'Read the command user manual.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'run', 'Run the specified script by the hostname and its path, like route.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'version', 'Razy version.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'update', 'Update the Razy.phar to latest stable version.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'validate', 'Validate the specified distributor package version.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'query', 'Query the return the result by the FQDN.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'console', 'Open the console, default to localhost.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'set', 'Create or update the site.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'remove', 'Remove the specified site.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'pack', 'Pack the Razy.phar and others modules and plugins into a phar file.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'link', 'Add an alias to specified site.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'unlink', 'Remove an alias.' . PHP_EOL));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'rewrite', 'Update the .htaccess rewrite.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', 'commit', 'Commit a version for specified module'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', '-f', 'The folder which is installed the Razy framework.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', '-debug', 'Enable logging and save the log to a file.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', '-p', 'The file or directory to save the log to.'));
    $this->writeLineLogging(sprintf('  {@c:green}%-14s{@reset} %s', '-i', 'Initial the distributor folder.'));
};
