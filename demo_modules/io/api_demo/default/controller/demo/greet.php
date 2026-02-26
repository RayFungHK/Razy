<?php
/**
 * Greeting API Demo
 * 
 * @llm Demonstrates the greet API command.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('Greeting API', 'Multi-language greeting demo');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    
    // Basic greeting
    $basic = $this->api('io/api_provider')->greet('World');
    
    echo '<div class="card">';
    echo '<h2>Basic Greeting</h2>';
    echo '<pre>$result = $this->api(\'io/api_provider\')->greet(\'World\');</pre>';
    echo '<h4>Result</h4>';
    echo '<pre>' . json_encode($basic, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    // Named greeting
    $named = $this->api('io/api_provider')->greet('Alice');
    
    echo '<div class="card">';
    echo '<h2>Named Greeting</h2>';
    echo '<pre>$result = $this->api(\'io/api_provider\')->greet(\'Alice\');</pre>';
    echo '<h4>Result</h4>';
    echo '<pre>' . json_encode($named, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    // Multi-language greetings
    $languages = ['en', 'es', 'fr', 'de', 'jp', 'cn'];
    
    echo '<div class="card">';
    echo '<h2>Multi-language Greetings</h2>';
    echo '<pre>$languages = [\'en\', \'es\', \'fr\', \'de\', \'jp\', \'cn\'];
foreach ($languages as $lang) {
    $result = $this->api(\'io/api_provider\')->greet(\'Razy\', $lang);
}</pre>';
    echo '<h4>Results</h4>';
    echo '<table class="table"><thead><tr><th>Language</th><th>Message</th></tr></thead><tbody>';
    
    foreach ($languages as $lang) {
        $result = $this->api('io/api_provider')->greet('Razy', $lang);
        echo '<tr>';
        echo '<td><code>' . $lang . '</code></td>';
        echo '<td>' . htmlspecialchars($result['message']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    echo $footer;
};
