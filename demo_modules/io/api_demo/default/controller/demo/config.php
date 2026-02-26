<?php
/**
 * Config API Demo
 * 
 * @llm Demonstrates the config API command.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('Config API', 'Configuration retrieval demo');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    
    // Get all config
    $all = $this->api('io/api_provider')->config('all');
    
    echo '<div class="card">';
    echo '<h2>Get All Configuration</h2>';
    echo '<pre>$result = $this->api(\'io/api_provider\')->config(\'all\');</pre>';
    echo '<h4>Available Sections</h4>';
    echo '<p>' . implode(', ', array_map(fn($s) => "<code>{$s}</code>", $all['sections'])) . '</p>';
    echo '<h4>Full Response</h4>';
    echo '<pre>' . json_encode($all, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    // Get specific sections
    $sections = ['app', 'database', 'cache', 'mail'];
    
    foreach ($sections as $section) {
        $result = $this->api('io/api_provider')->config($section);
        
        echo '<div class="card">';
        echo '<h2>' . ucfirst($section) . ' Configuration</h2>';
        echo '<pre>$this->api(\'io/api_provider\')->config(\'' . $section . '\');</pre>';
        echo '<h4>Result</h4>';
        echo '<table class="table"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
        
        foreach ($result['config'] as $key => $value) {
            echo '<tr>';
            echo '<td><code>' . htmlspecialchars($key) . '</code></td>';
            echo '<td>' . htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
    
    // Unknown section
    $unknown = $this->api('io/api_provider')->config('unknown');
    
    echo '<div class="card">';
    echo '<h2>Unknown Section</h2>';
    echo '<pre>$this->api(\'io/api_provider\')->config(\'unknown\');</pre>';
    echo '<h4>Result</h4>';
    echo '<pre>' . json_encode($unknown, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    echo $footer;
};
