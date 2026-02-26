<?php
/*
 * hello_world.index.php — Route Handler
 *
 * This file handles the "/" route registered in the controller.
 * It returns a Closure that Razy executes when the route is matched.
 *
 * Inside the closure, $this refers to the Controller instance,
 * so you can access module info, load templates, call APIs, etc.
 * For this minimal example we just output plain text.
 *
 * File location: demo/hello_world/default/controller/hello_world.index.php
 *
 * Test with:
 *   php Razy.phar runapp <dist>
 *   [dist]> run /hello_world/
 */

return function (): void {
    // That's it — just output something.
    echo 'Hello, World!' . PHP_EOL;
};
