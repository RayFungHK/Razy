<?php
/*
 * hello_world.php — Main Controller
 *
 * The controller filename MUST match the module name (the part after
 * the slash in the module code). Since our code is "demo/hello_world",
 * this file is named "hello_world.php".
 *
 * It returns an anonymous class that extends Razy\Controller.
 * The __onInit() method is called once when the module loads —
 * this is where you register routes, APIs, and events.
 *
 * File location: demo/hello_world/default/controller/hello_world.php
 */

namespace Razy\Module\demo_hello_world;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    /**
     * Called when the module initialises.
     * Return true to confirm the module loaded successfully.
     */
    public function __onInit(Agent $agent): bool
    {
        // Register ONE route: when a user visits /{alias}/,
        // Razy will execute the handler in "hello_world.index.php".
        //
        // addRoute(pattern, handler_name):
        //   '/'      = match the module's root URL
        //   'index'  = load "hello_world.index.php" from this controller dir
        $agent->addRoute('/', 'index');

        return true;
    }
};
