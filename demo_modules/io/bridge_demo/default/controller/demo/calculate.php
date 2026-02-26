<?php
/**
 * Bridge Demo - calculate API
 * 
 * @llm Demonstrates calling siteB's calculate API via bridge.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('calculate API', 'Remote computation in siteB');
    $footer = $this->api('demo/demo_index')->footer();
    
    // Simulated bridge calls with different operations
    $operations = [
        ['add', 100, 50],
        ['subtract', 100, 30],
        ['multiply', 15, 8],
        ['divide', 100, 4],
        ['power', 2, 10],
    ];
    
    echo $header;
    echo '<div class="card"><h2>Remote Calculation via Bridge</h2>';
    echo '<p>These calculations are performed in <code>siteB</code> distributor:</p>';
    echo '<table><thead><tr><th>Code</th><th>Result (simulated)</th></tr></thead><tbody>';
    
    foreach ($operations as [$op, $a, $b]) {
        // Simulated result
        $result = match ($op) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $a / $b,
            'power' => pow($a, $b),
            default => null,
        };
        
        $code = "\$bridge->call('siteB', 'bridge/provider', 'calculate', ['{$op}', {$a}, {$b}])";
        
        echo "<tr><td><code>{$code}</code></td><td><strong>{$result}</strong></td></tr>";
    }
    
    echo '</tbody></table></div>';
    
    echo <<<HTML
    <div class="card">
        <h2>Full Response Example</h2>
        <pre>\$bridge->call('siteB@1.0.0', 'bridge/provider', 'calculate', ['power', 2, 10]);</pre>
        <h4>Response (simulated)</h4>
        <pre>{
    "success": true,
    "source": "siteB@1.0.0",
    "operation": "power",
    "operands": {"a": 2, "b": 10},
    "result": 1024,
    "error": null,
    "timestamp": "2026-02-10 15:30:00"
}</pre>
    </div>
    
    <div class="card">
        <h2>Use Case</h2>
        <p>Why use bridge for computation?</p>
        <ul>
            <li><strong>Library isolation</strong> - siteB might use a math library incompatible with testsite</li>
            <li><strong>Resource isolation</strong> - Heavy computation doesn't block main process</li>
            <li><strong>Version testing</strong> - Compare results from different versions</li>
        </ul>
    </div>
HTML;
    
    echo $footer;
};
