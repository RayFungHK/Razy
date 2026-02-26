<?php
/**
 * Route Demo Controller
 * 
 * @llm Demonstrates addRoute() with URL parameter capture patterns.
 * 
 * CRITICAL: addRoute() requires:
 * 1. LEADING SLASH - absolute path from site root
 * 2. PARENTHESES - to capture values: (:d) not :d
 * 
 * addRoute() vs addLazyRoute():
 * - addLazyRoute: Relative to module alias, prefix matching, no capture
 * - addRoute: Absolute path, regex matching, capture groups supported
 * 
 * Pattern Tokens:
 * - :a         Match any non-slash characters [^/]+
 * - :d         Match digits (0-9) \d+
 * - :D         Match non-digits \D+
 * - :w         Match alphabets (a-zA-Z) \w+ (note: actually [a-zA-Z]+)
 * - :W         Match non-alphabets \W+
 * - :[regex]   Custom regex character class, e.g., :[a-z0-9-]
 * - {n}        Exactly n characters
 * - {min,max}  Length range (min to max characters)
 * - ()         Capture group - passes value to handler function
 * 
 * Examples:
 * - '/module/user/(:d)' → Captures numeric ID, handler receives $id
 * - '/module/article/(:a)' → Captures any slug
 * - '/module/tag/(:[a-z0-9-]{1,30})' → Custom regex with length limit
 */

namespace Razy\Module\route_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Main page via addLazyRoute (exact match)
        $agent->addLazyRoute(['/' => 'main']);

        // === addRoute Examples with URL Parameter Capture ===
        // IMPORTANT: addRoute requires LEADING SLASH and is ABSOLUTE from site root

        // 1. Capture numeric ID: /route_demo/user/123
        //    :d matches digits (0-9)
        $agent->addRoute('/route_demo/user/(:d)', 'user');

        // 2. Capture string slug: /route_demo/article/hello-world
        //    :a matches any non-slash characters
        $agent->addRoute('/route_demo/article/(:a)', 'article');

        // 3. Capture with alphabets only: /route_demo/product/Widget (NOT widget123)
        //    :w matches a-zA-Z only
        $agent->addRoute('/route_demo/product/(:w)', 'product');

        // 4. Fixed length: /route_demo/code/ABC123 (exactly 6 characters)
        //    {6} limits to exactly 6 characters
        $agent->addRoute('/route_demo/code/(:a{6})', 'code');

        // 5. Range length: /route_demo/search/abc to /search/abcdefghij (3-10 chars)
        //    {3,10} limits to 3-10 characters
        $agent->addRoute('/route_demo/search/(:a{3,10})', 'search');

        // 6. Custom character class: /route_demo/tag/web-dev
        //    :[regex] uses raw regex character class
        $agent->addRoute('/route_demo/tag/(:[a-z0-9-]{1,30})', 'tag');
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Route Demo',
                'description' => 'URL routing with addRoute() parameter capture',
                'url'         => '/route_demo/',
                'category'    => 'Core Features',
                'icon'        => '🛤️',
                'routes'      => '7 routes: /, user/:d, article/:a, product/:w, code/:a{6}, search/:a{3,10}, tag/:[a-z0-9-]',
            ];
        });

        return true;
    }
};
