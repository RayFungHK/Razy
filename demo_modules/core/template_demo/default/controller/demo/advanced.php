<?php
/**
 * Template Engine Demo - Advanced Patterns
 * 
 * @llm Demonstrates INCLUDE blocks, RECURSION blocks for tree structures,
 * global template loading, comments, and combined real-world patterns.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');

    $results = [];

    // === INCLUDE Block ===
    $source = $this->loadTemplate('demo_adv_include');
    $source->assign([
        'page_title' => 'My Page',
    ]);

    $results['include_block'] = [
        'description' => '<!-- INCLUDE BLOCK: path/file.tpl --> — Include external template files inline',
        'tpl_code' => "<!-- INCLUDE BLOCK: include/alert.tpl -->\n<p>Main content after the included alert.</p>",
        'code' => '$source = $this->loadTemplate(\'demo_adv_include\');',
        'output' => trim($source->output()),
    ];

    // === RECURSION Block (tree structure) ===
    $source = $this->loadTemplate('demo_adv_recursion');
    $root = $source->getRoot();

    // Build a file tree structure recursively
    $tree = [
        'src' => [
            'controllers' => ['HomeController.php', 'UserController.php'],
            'models' => ['User.php', 'Post.php'],
            'views' => [
                'layouts' => ['main.tpl'],
                'home.tpl',
                'about.tpl',
            ],
        ],
        'tests' => ['HomeTest.php', 'UserTest.php'],
        'composer.json',
        'README.md',
    ];

    // Recursive closure to build tree blocks
    $buildTree = function ($parent, array $items) use (&$buildTree): void {
        foreach ($items as $key => $value) {
            if (is_array($value)) {
                // Directory node
                $node = $parent->newBlock('node')->assign([
                    'name' => is_string($key) ? $key : $value,
                    'icon' => '📁',
                    'is_dir' => true,
                ]);
                $buildTree($node, $value);
            } else {
                // File node
                $parent->newBlock('node')->assign([
                    'name' => $value,
                    'icon' => '📄',
                    'is_dir' => false,
                ]);
            }
        }
    };

    $buildTree($root, $tree);

    $results['recursion_block'] = [
        'description' => 'RECURSION blocks enable self-referencing for tree structures (e.g., file trees, nested menus)',
        'tpl_code' => "<!-- START BLOCK: node -->\n<div style=\"margin-left:16px\">\n  {{\$icon}} {{\$name}}\n  <!-- RECURSION BLOCK: node -->\n</div>\n<!-- END BLOCK: node -->",
        'code' => "// Recursively: \$parent->newBlock('node')->assign(['name' => 'src', 'icon' => '📁']);\n// Children nest inside the same 'node' block definition",
        'output' => trim($source->output()),
    ];

    // === Template Comments ===
    $source = $this->loadTemplate('demo_adv_comments');
    $source->assign([
        'visible' => 'This is visible content',
    ]);

    $results['comments'] = [
        'description' => '{# comment #} — Template comments are stripped from output',
        'tpl_code' => "{# This comment won't appear in output #}\n{{\$visible}}\n{# Another hidden comment #}",
        'code' => '$source->assign([\'visible\' => \'This is visible content\']);',
        'output' => trim($source->output()),
    ];

    // === Global Template with loadTemplate() ===
    $tpl = $this->getTemplate();
    $tpl->loadTemplate([
        'AlertBox' => $this->getTemplateFilePath('include/alert_global'),
    ]);

    $source = $this->loadTemplate('demo_adv_global_template');
    $source->assign([
        'msg1' => 'Operation completed successfully!',
        'msg2' => 'Please check your input values.',
    ]);

    $results['global_template'] = [
        'description' => 'Load templates globally via $this->getTemplate()->loadTemplate() and reference with {@template:Name}',
        'tpl_code' => '{@template:AlertBox type="success" message=$msg1}\n{@template:AlertBox type="warning" message=$msg2}',
        'code' => "\$tpl = \$this->getTemplate();\n\$tpl->loadTemplate(['AlertBox' => \$this->getTemplateFilePath('include/alert_global')]);\n// In template: {@template:AlertBox type=\"success\" message=\$msg1}",
        'output' => trim($source->output()),
    ];

    // === Combined Real-World: Dashboard Cards ===
    $source = $this->loadTemplate('demo_adv_dashboard');
    $root = $source->getRoot();
    $source->assign(['dashboard_title' => 'System Overview']);

    $stats = [
        ['title' => 'Users', 'value' => '1,234', 'icon' => '👥', 'trend' => 'up'],
        ['title' => 'Revenue', 'value' => '$45.2k', 'icon' => '💰', 'trend' => 'up'],
        ['title' => 'Orders', 'value' => '892', 'icon' => '📦', 'trend' => 'down'],
        ['title' => 'Uptime', 'value' => '99.9%', 'icon' => '🟢', 'trend' => 'stable'],
    ];

    foreach ($stats as $stat) {
        $root->newBlock('card')->assign($stat);
    }

    $results['dashboard_pattern'] = [
        'description' => 'Real-world pattern: Dashboard stat cards combining blocks, @if conditional, and modifiers',
        'code' => "foreach (\$stats as \$stat) {\n    \$root->newBlock('card')->assign(\$stat);\n}",
        'output' => trim($source->output()),
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);
};
