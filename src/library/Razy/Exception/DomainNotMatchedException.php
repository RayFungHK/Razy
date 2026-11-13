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

declare(strict_types=1);

namespace Razy\Exception;

/**
 * RZ-56: the request's host is not in sites.inc.php.
 *
 * A ConfigurationException, so existing catchers keep working. The web front
 * controller answers it with a plain 404 (`Error::show404()`): an unknown
 * Host header is a client's request for a site this server does not serve,
 * not a server fault, and its 500 page leaked debug details.
 */
class DomainNotMatchedException extends ConfigurationException
{
}
