<?php
/**
 * Transform API Demo
 * 
 * @llm Demonstrates the transform API command.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('Transform API', 'String transformation utilities');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    
    $testString = 'Hello World from Razy Framework';
    
    echo '<div class="card">';
    echo '<h2>String Transformations</h2>';
    echo '<p>Test string: <code>' . htmlspecialchars($testString) . '</code></p>';
    echo '</div>';
    
    // String transforms
    $transforms = ['uppercase', 'lowercase', 'titlecase', 'reverse', 'slugify', 'wordcount'];
    
    echo '<div class="card">';
    echo '<h2>Available Transforms</h2>';
    echo '<table class="table"><thead><tr><th>Type</th><th>Code</th><th>Result</th></tr></thead><tbody>';
    
    foreach ($transforms as $type) {
        $result = $this->api('io/api_provider')->transform($type, $testString);
        echo '<tr>';
        echo '<td><code>' . $type . '</code></td>';
        echo '<td><pre style="margin:0">$this->api(\'io/api_provider\')->transform(\'' . $type . '\', $string);</pre></td>';
        echo '<td><strong>' . htmlspecialchars((string) $result['result']) . '</strong></td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    // Slugify examples
    $slugExamples = [
        'Hello World',
        'Product Name (Special Edition)',
        '  Trim   Spaces  ',
        'UPPERCASE to lowercase',
    ];
    
    echo '<div class="card">';
    echo '<h2>Slugify Examples</h2>';
    echo '<table class="table"><thead><tr><th>Input</th><th>Output</th></tr></thead><tbody>';
    
    foreach ($slugExamples as $input) {
        $result = $this->api('io/api_provider')->transform('slugify', $input);
        echo '<tr>';
        echo '<td><code>' . htmlspecialchars($input) . '</code></td>';
        echo '<td><code>' . htmlspecialchars($result['result']) . '</code></td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    // JSON encode
    $arrayData = ['name' => 'Razy', 'version' => '0.5', 'features' => ['API', 'Routing', 'Templates']];
    $jsonResult = $this->api('io/api_provider')->transform('json_encode', $arrayData);
    
    echo '<div class="card">';
    echo '<h2>JSON Encode</h2>';
    echo '<pre>$data = [\'name\' => \'Razy\', \'version\' => \'0.5\', \'features\' => [\'API\', \'Routing\', \'Templates\']];
$this->api(\'io/api_provider\')->transform(\'json_encode\', $data);</pre>';
    echo '<h4>Result</h4>';
    echo '<pre>' . htmlspecialchars($jsonResult['result']) . '</pre>';
    echo '</div>';
    
    // Reverse array
    $arrayInput = ['first', 'second', 'third', 'fourth'];
    $reverseArray = $this->api('io/api_provider')->transform('reverse', $arrayInput);
    
    echo '<div class="card">';
    echo '<h2>Reverse Array</h2>';
    echo '<pre>$array = [\'first\', \'second\', \'third\', \'fourth\'];
$this->api(\'io/api_provider\')->transform(\'reverse\', $array);</pre>';
    echo '<h4>Result</h4>';
    echo '<pre>' . json_encode($reverseArray, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    echo $footer;
};
