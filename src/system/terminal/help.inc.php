<?php

/**
 * CLI Command: help.
 *
 * Displays the main help page listing all available Razy CLI commands,
 * their descriptions, and supported global options.
 *
 * Usage:
 *   php Razy.phar help
 *
 * @license MIT
 */

namespace Razy;

return function () {
    // Output usage synopsis and all available commands with descriptions
    $this->writeLineLogging('Usage: php Razy.phar [options] [args...]' . PHP_EOL);
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'build', 'Build the Razy environment in specified location (e.g., build .).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'cache', 'Manage the cache system (clear, gc, stats, status).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'help', 'This help.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'fix', 'Fix the sites configuration and .htaccess routing.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'man', 'Read the command user manual.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'run', 'Run the specified script by the hostname and its path, like route.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'runapp', 'Interactive shell for a distributor (no sites.inc.php needed).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'version', 'Razy version.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'update', 'Update the Razy.phar to latest stable version.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'compose', 'Compose the specified distributor, install the required library from composer.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'install', 'Download and install modules from GitHub repositories.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'inspect', 'Inspect distributor configuration, domains, and modules.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'scaffold', 'Generate a complete module skeleton from one command.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'standalone', 'Scaffold a standalone (lite) application with ultra-flat structure.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'generate-skills', 'Generate skills.md files for framework and modules.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'query', 'Query the return the result by the FQDN.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'set', 'Create or update the site.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'remove', 'Remove the specified site.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'pack', 'Pack the Razy.phar and others modules and plugins into a phar file.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'link', 'Add an alias to specified site.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'unlink', 'Remove an alias.' . PHP_EOL));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'rewrite', 'Update the .htaccess rewrite.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'commit', 'Commit a version for specified module'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-f', 'The folder which is installed the Razy framework.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-debug', 'Enable logging and save the log to a file.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-p', 'The file or directory to save the log to.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-i', 'Initial the distributor folder.'));
};
