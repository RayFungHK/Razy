<?php

namespace Razy;

return function (string $distCode = '') use (&$parameters) {
	$this->writeLine('{@s:bu}Unpack asset', true);

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

		return;
	}

	$this->writeLine('The distributor `' . $distCode . '` found, started unpacking...', true);
	Application::UnpackAsset($distCode, function ($moduleCode, $unpacked) {
		if (count($unpacked) > 0) {
			$this->writeLine('Module [' . $moduleCode . '] {@c:green}' . count($unpacked) . '{@reset} assets have unpacked.', true);
		}
	});
};
