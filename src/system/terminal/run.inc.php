<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

return function (string $path = '') {
    $this->writeLineLogging('Running the application (' . $path . ')...');

    [$hostname, $urlQuery] = explode('/', $path . '/', 2);
    ($app = new Application())->host($hostname);
    Application::Lock();
    if (!$app->query(tidy('/' . $urlQuery, true, '/'))) {
        Error::Show404();
    }

    return true;
};
