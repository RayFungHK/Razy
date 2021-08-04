<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Phar;

$pharPath = './Razy.phar';
if (is_file('./config.inc.php')) {
	try {
		$razyConfig = require './config.inc.php';
		$pharPath   = realpath(($razyConfig['phar_location'] ?? '.') . '/Razy.phar');
		if (!is_file($pharPath)) {
			echo 'No configuration file has found.';

			exit;
		}
	} catch (\Exception $e) {
		echo 'No configuration file has found.';

		exit;
	}
}

// Load the phar file and start the application in web mode
Phar::loadPhar($pharPath, 'Razy.phar');

include 'phar://Razy.phar/main.php';
