<?php

namespace Razy;

return function (string $fqdn = '') {
	// Check the parameters is valid
	$fqdn = trim($fqdn);
	if (!$fqdn) {
		$this->writeLine('{@c:red}[ERROR] The FQDN is required.', true);

		exit;
	}

	// Load default config setting
	$configFilePath = append(RAZY_PATH, 'sites.inc.php');
	$config         = loadSiteConfig($configFilePath);

	// Extract the domain and the path from the FQDN string
	$fqdn            = trim(preg_replace('/[\\\\\/]+/', '/', $fqdn), '/');
	[$domain, $path] = explode('/', $fqdn, 2);
	$path            = '/' . $path;

	// Remove the specified domain and path setting
	unset($config['domains'][$domain][$path]);

	// Remove empty domain sites
	if (empty($config['domains'][$domain] ?? [])) {
		unset($config['domains'][$domain]);
	}

	if (writeSiteConfig($configFilePath, $config)) {
		$this->writeLine('{@c:green}Done.', true);
	} else {
		$this->writeLine('{@c:red}Failed.', true);
	}
};
