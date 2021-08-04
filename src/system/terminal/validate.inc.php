<?php

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
	$this->writeLine('{@s:bu}Update distributor module and package', true);

	// Check the parameters is valid
	$distCode = trim($distCode);
	if (!$distCode) {
		$this->writeLine('{@c:r}[ERROR] The distributor code is required.', true);

		exit;
	}

	// Load default config setting
	$configFilePath = append(RAZY_PATH, 'sites.inc.php');
	$config         = loadSiteConfig($configFilePath);

	$application = Application::GetMaster();
	if (!Application::distributorExists($distCode)) {
		$this->writeLine('The distributor `' . $distCode . '` has not found', true);

		return false;
	}

	Application::ValidatePackage($distCode, function (string $type, string $packageName, ...$args) {
		if (PackageManager::TYPE_READY == $type) {
			$this->writeLine('Validating package: {@c:green}' . $packageName . '{@reset} (' . $args[0] . ')', true);
		} elseif (PackageManager::TYPE_DOWNLOAD_PROGRESS == $type) {
			$size = (int) $args[1];
			$downloaded = (int) $args[2];
			echo $this->format('{@clear} - Downloading: {@c:green}' . $packageName . ' @' . $args[0] . '{@reset} (' . ((!$downloaded) ? '0' : floor(($downloaded / $size) * 100)) . '%)', true);
		} elseif (PackageManager::TYPE_DOWNLOAD_FINISHED == $type) {
			echo PHP_EOL;
		} elseif (PackageManager::TYPE_UPDATED == $type) {
			$this->writeLine(' - {@c:green}Done.{@reset}', true);
		} elseif (PackageManager::TYPE_FAILED == $type) {
			$this->writeLine(' - {@c:red}Cannot update package ' . $args[0] . ' (' . $args[1] . ').{@reset}', true);
		} elseif (PackageManager::TYPE_EXTRACT == $type) {
			$this->writeLine(' - {@c:green}' . $packageName . '{@reset}: Extracting `' . $args[0] . '` from `' . $args[1] . '`', true);
		}
	});

	return true;
};
