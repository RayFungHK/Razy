<?php
/**
 * Calculator API Demo
 * 
 * @llm Demonstrates the calculate API command.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('Calculator API', 'Math operations demo');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    
    // Basic operations
    $operations = [
        ['add', 10, 5],
        ['subtract', 10, 3],
        ['multiply', 6, 7],
        ['divide', 100, 4],
        ['power', 2, 8],
        ['mod', 17, 5],
    ];
    
    echo '<div class="card">';
    echo '<h2>Basic Operations</h2>';
    echo '<pre>$this->api(\'io/api_provider\')->calculate($operation, $a, $b);</pre>';
    echo '<h4>Results</h4>';
    echo '<table class="table"><thead><tr><th>Operation</th><th>A</th><th>B</th><th>Result</th></tr></thead><tbody>';
    
    foreach ($operations as [$op, $a, $b]) {
        $result = $this->api('io/api_provider')->calculate($op, $a, $b);
        echo '<tr>';
        echo '<td><code>' . $op . '</code></td>';
        echo '<td>' . $a . '</td>';
        echo '<td>' . $b . '</td>';
        echo '<td><strong>' . ($result['result'] ?? 'Error') . '</strong></td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '</div>';
    
    // Single operation detail
    $detail = $this->api('io/api_provider')->calculate('multiply', 12, 8);
    
    echo '<div class="card">';
    echo '<h2>Detailed Response</h2>';
    echo '<pre>$result = $this->api(\'io/api_provider\')->calculate(\'multiply\', 12, 8);</pre>';
    echo '<h4>Full Response</h4>';
    echo '<pre>' . json_encode($detail, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    // Error handling
    $divByZero = $this->api('io/api_provider')->calculate('divide', 10, 0);
    $unknownOp = $this->api('io/api_provider')->calculate('sqrt', 16, 0);
    
    echo '<div class="card">';
    echo '<h2>Error Handling</h2>';
    echo '<h4>Division by Zero</h4>';
    echo '<pre>$this->api(\'io/api_provider\')->calculate(\'divide\', 10, 0);</pre>';
    echo '<pre>' . json_encode($divByZero, JSON_PRETTY_PRINT) . '</pre>';
    
    echo '<h4>Unknown Operation</h4>';
    echo '<pre>$this->api(\'io/api_provider\')->calculate(\'sqrt\', 16, 0);</pre>';
    echo '<pre>' . json_encode($unknownOp, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    echo $footer;
};
