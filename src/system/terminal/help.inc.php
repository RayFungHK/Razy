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
    // (list mirrors src/system/terminal/*.inc.php — keep in sync when commands land)
    $this->writeLineLogging('Usage: php Razy.phar [options] [args...]' . PHP_EOL);
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'build', 'Build the Razy environment in specified location (e.g., build .).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'cache', 'Manage the cache system (clear, gc, stats, status).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'help', 'This help.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'run', 'Run the specified script by the hostname and its path, like route.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'runapp', 'Interactive shell for a distributor (no sites.inc.php needed).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'version', 'Razy version.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'compose', 'Compose the specified distributor, install the required library from composer.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'install', 'Download and install modules from GitHub repositories.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'inspect', 'Inspect distributor configuration, domains, and modules.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'scaffold', 'Generate a complete module skeleton from one command.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'standalone', 'Scaffold a standalone (lite) application with ultra-flat structure.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'generate-skills', 'Generate skills.md files for framework and modules.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'set', 'Create or update the site.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'remove', 'Remove the specified site.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'pack', 'Pack the Razy.phar and others modules and plugins into a phar file.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'pkg', 'Manage standalone packages (build, publish, run, list, stop).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'link', 'Add an alias to specified site.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'unlink', 'Remove an alias.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'rewrite', 'Update the .htaccess rewrite.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'serve', 'Start a development web server (PHP built-in) for a distributor.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'routes', 'List registered routes, closures, and optionally API commands.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'validate', 'Validate that route/API closure files exist; optionally generate stubs.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'search', 'Search for modules across configured repositories.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'sync', 'Synchronize modules for a distributor from its repository config.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'queue', 'Manage the job queue system (worker loop, status).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'schedule', 'Run cron-scheduled jobs from scheduler.inc.php (run, list, test).'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', 'bridge', 'Execute internal API commands across distributors via JSON payload.' . PHP_EOL));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-f', 'The folder which is installed the Razy framework.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-debug', 'Enable logging and save the log to a file.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-p', 'The file or directory to save the log to.'));
    $this->writeLineLogging(\sprintf('  {@c:green}%-14s{@reset} %s', '-i', 'Initial the distributor folder.'));
};
