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

namespace Razy\Session;

use Razy\Contract\SessionInterface;
use Razy\Distributor;

/**
 * RZ-05: mounts the dist's one session (Distributor::getSession()) on every
 * route and publishes it on the container, so modules and the CSRF door all
 * read and write the same store.
 */
final class SessionDoor
{
    public static function arm(Distributor $distributor): SessionMiddleware
    {
        $session = $distributor->getSession();
        $middleware = new SessionMiddleware($session);
        $distributor->getRouter()->addGlobalMiddleware($middleware);
        self::publish($distributor, $session);

        return $middleware;
    }

    /**
     * Bind the session on the dist container (also used by the CSRF door,
     * which mounts the session middleware itself, outside its token check).
     */
    public static function publish(Distributor $distributor, SessionInterface $session): void
    {
        if ($container = $distributor->getContainer()) {
            $container->instance(SessionInterface::class, $session);
            if ($session instanceof Session) {
                $container->instance(Session::class, $session);
            }
        }
    }
}
