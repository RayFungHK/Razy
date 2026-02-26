<?php
/**
 * Chained API Calls Demo
 * 
 * @llm Demonstrates combining multiple API calls.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('Chained API Calls', 'Combining multiple API operations');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    
    // Chain 1: Get user, greet them, transform the greeting
    echo '<div class="card">';
    echo '<h2>Chain 1: User Greeting Pipeline</h2>';
    echo '<p>Get user ??Create greeting ??Transform to uppercase</p>';
    
    $user = $this->api('io/api_provider')->user('get', 1);
    $greeting = $this->api('io/api_provider')->greet($user['user']['name']);
    $transformed = $this->api('io/api_provider')->transform('uppercase', $greeting['message']);
    
    echo '<pre>// Step 1: Get user
$user = $this->api(\'io/api_provider\')->user(\'get\', 1);
// User: ' . $user['user']['name'] . '

// Step 2: Create greeting
$greeting = $this->api(\'io/api_provider\')->greet($user[\'user\'][\'name\']);
// Message: ' . $greeting['message'] . '

// Step 3: Transform to uppercase
$transformed = $this->api(\'io/api_provider\')->transform(\'uppercase\', $greeting[\'message\']);
// Result: ' . $transformed['result'] . '</pre>';
    
    echo '<h4>Final Result</h4>';
    echo '<div class="preview" style="font-size: 1.5em; padding: 1rem; background: #f0f0f0; border-radius: 8px;">';
    echo '<strong>' . htmlspecialchars($transformed['result']) . '</strong>';
    echo '</div>';
    echo '</div>';
    
    // Chain 2: Calculate and format
    echo '<div class="card">';
    echo '<h2>Chain 2: Calculator with Formatting</h2>';
    echo '<p>Calculate ??Build result string ??Create slug</p>';
    
    $calc = $this->api('io/api_provider')->calculate('multiply', 12, 8);
    $resultString = "Result of {$calc['a']} times {$calc['b']} equals {$calc['result']}";
    $slug = $this->api('io/api_provider')->transform('slugify', $resultString);
    
    echo '<pre>// Step 1: Calculate
$calc = $this->api(\'io/api_provider\')->calculate(\'multiply\', 12, 8);
// Expression: ' . $calc['expression'] . '

// Step 2: Build result string
$resultString = "Result of {$calc[\'a\']} times {$calc[\'b\']} equals {$calc[\'result\']}";
// String: ' . $resultString . '

// Step 3: Create URL-friendly slug
$slug = $this->api(\'io/api_provider\')->transform(\'slugify\', $resultString);
// Slug: ' . $slug['result'] . '</pre>';
    
    echo '<h4>Results</h4>';
    echo '<table class="table"><tbody>';
    echo '<tr><td>Calculation</td><td><code>' . $calc['expression'] . '</code></td></tr>';
    echo '<tr><td>Readable</td><td>' . htmlspecialchars($resultString) . '</td></tr>';
    echo '<tr><td>URL Slug</td><td><code>' . htmlspecialchars($slug['result']) . '</code></td></tr>';
    echo '</tbody></table>';
    echo '</div>';
    
    // Chain 3: Multi-user operations
    echo '<div class="card">';
    echo '<h2>Chain 3: Process All Users</h2>';
    echo '<p>List users ??Greet each ??Collect results</p>';
    
    $users = $this->api('io/api_provider')->user('list');
    $greetings = [];
    
    foreach ($users['users'] as $u) {
        $g = $this->api('io/api_provider')->greet($u['name'], 'en');
        $greetings[] = [
            'user' => $u['name'],
            'greeting' => $g['message'],
        ];
    }
    
    echo '<pre>$users = $this->api(\'io/api_provider\')->user(\'list\');
$greetings = [];

foreach ($users[\'users\'] as $user) {
    $g = $this->api(\'io/api_provider\')->greet($user[\'name\'], \'en\');
    $greetings[] = [
        \'user\' => $user[\'name\'],
        \'greeting\' => $g[\'message\'],
    ];
}</pre>';
    
    echo '<h4>Collected Greetings</h4>';
    echo '<table class="table"><thead><tr><th>User</th><th>Greeting</th></tr></thead><tbody>';
    foreach ($greetings as $g) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($g['user']) . '</td>';
        echo '<td>' . htmlspecialchars($g['greeting']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    
    // Chain 4: Config-driven operation
    echo '<div class="card">';
    echo '<h2>Chain 4: Config-Driven Processing</h2>';
    echo '<p>Get config ??Use config value in calculation</p>';
    
    $cacheConfig = $this->api('io/api_provider')->config('cache');
    $ttl = $cacheConfig['config']['ttl'];
    $hoursCalc = $this->api('io/api_provider')->calculate('divide', $ttl, 3600);
    
    echo '<pre>// Step 1: Get cache config
$cacheConfig = $this->api(\'io/api_provider\')->config(\'cache\');
// TTL: ' . $ttl . ' seconds

// Step 2: Convert to hours
$hoursCalc = $this->api(\'io/api_provider\')->calculate(\'divide\', $ttl, 3600);
// Result: ' . $hoursCalc['result'] . ' hours</pre>';
    
    echo '<h4>Result</h4>';
    echo '<p>Cache TTL is <strong>' . $ttl . '</strong> seconds = <strong>' . $hoursCalc['result'] . '</strong> hour(s)</p>';
    echo '</div>';
    
    // Summary
    echo '<div class="card">';
    echo '<h2>Key Takeaways</h2>';
    echo '<ul>';
    echo '<li>API calls can be chained to build complex workflows</li>';
    echo '<li>Each API call is independent - pass data between them explicitly</li>';
    echo '<li>Use <code>$this->api(\'module/code\')->command($args)</code> pattern</li>';
    echo '<li>Handle errors at each step for robust pipelines</li>';
    echo '</ul>';
    echo '</div>';
    
    echo $footer;
};
