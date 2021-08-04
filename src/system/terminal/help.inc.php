<?php

namespace Razy;

return function () {
	$this->writeLine('Usage: php Razy.phar [options] [args...]' . PHP_EOL);
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'build', 'Build the Razy environment in specified location.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'help', 'This help.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'fix', 'Fix the sites configuration and .htaccess routing.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'man', 'Read the command user manual.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'version', 'Razy version.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'update', 'Update the Razy.phar to latest stable version.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'validate', 'Validate the specified distributor package version.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'query', 'Query the return the result by the FQDN.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'console', 'Open the console, default to localhost.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'set', 'Create or update the site.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'remove', 'Remove the specified site.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'pack', 'Pack the Razy.phar and others modules and plugins into a phar file.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'link', 'Add an alias to specified site.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'unlink', 'Remove an alias.' . PHP_EOL));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', 'unpackasset', 'Unpack all modules asset under the distributor.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', '-f', 'The folder which is installed the Razy framework.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', '-debug', 'Enable logging and save the log to a file.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', '-p', 'The file or directory to save the log to.'));
	$this->writeLine(sprintf('  {@c:green}%-14s{@reset} %s', '-i', 'Initial the distributor folder.'));
};
