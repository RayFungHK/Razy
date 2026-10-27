<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Exception;

/**
 * RZ-35: a module version cannot be honoured.
 *
 * Thrown when a dist.php pin matches no version folder, when a package.php
 * 'require' constraint is not met by the loaded version of its dependency,
 * or when one PHP process would load two versions of the same module.
 */
class ModuleVersionException extends ModuleLoadException
{
}
