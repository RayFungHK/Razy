<?php


namespace Razy;

return function (string $path = '') {
    $this->writeLineLogging('Running the application (' . $path . ')...');

    [$hostname, $urlQuery] = explode('/', $path, 2);
    $app                   = new Application($hostname);
    if (!$app->query(tidy('/' . $urlQuery, true, '/'))) {
        Error::Show404();
    }

    return true;
};
