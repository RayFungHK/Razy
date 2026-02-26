<?php
/**
 * Template Plugin Demo
 * 
 * @llm Demonstrates creating and using Template plugins.
 * 
 * ## Plugin Structure
 * 
 * Function plugin (function.NAME.php):
 * ```php
 * return function(Controller $controller): BlockFunction|InlineFunction {
 *     return new class extends BlockFunction {
 *         public function render(string $parameter, string $content): string { }
 *     };
 * };
 * ```
 * 
 * Modifier plugin (modifier.NAME.php):
 * ```php
 * return function(mixed $value, ...args): mixed {
 *     return modified_value;
 * };
 * ```
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'creating_function_plugin' => [
            'file' => 'function.greeting.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Template/function.greeting.php
use Razy\Template\Plugin\InlineFunction;

return function($controller) {
    return new class extends InlineFunction {
        public function render(string $parameter): string
        {
            $params = $this->parseParameter($parameter);
            $name = $params['name'] ?? 'World';
            return "Hello, {$name}!";
        }
    };
};
PHP,
            'usage' => '{greeting name="Alice"}',
            'output' => 'Hello, Alice!',
        ],
        
        'creating_block_function' => [
            'file' => 'function.highlight.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Template/function.highlight.php
use Razy\Template\Plugin\BlockFunction;

return function($controller) {
    return new class extends BlockFunction {
        public function render(string $parameter, string $content): string
        {
            $params = $this->parseParameter($parameter);
            $color = $params['color'] ?? 'yellow';
            return "<mark style=\"background:{$color}\">{$content}</mark>";
        }
    };
};
PHP,
            'usage' => '{highlight color="cyan"}Important text{/highlight}',
            'output' => '<mark style="background:cyan">Important text</mark>',
        ],
        
        'creating_modifier' => [
            'file' => 'modifier.slug.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Template/modifier.slug.php
return function($value) {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    return preg_replace('/-+/', '-', $slug);
};
PHP,
            'usage' => '{"Hello World!"|slug}',
            'output' => 'hello-world-',
        ],
        
        'registering_plugin_folder' => [
            'description' => 'Add custom plugin folder in module',
            'code' => <<<'PHP'
<?php
use Razy\Template;

// In your module controller __onInit:
Template::AddPluginFolder(__DIR__ . '/plugins');
PHP,
        ],
        
        'common_modifiers' => [
            'upper' => ['usage' => '{$name|upper}', 'desc' => 'Uppercase'],
            'lower' => ['usage' => '{$name|lower}', 'desc' => 'Lowercase'],
            'escape' => ['usage' => '{$html|escape}', 'desc' => 'HTML escape'],
            'json' => ['usage' => '{$data|json}', 'desc' => 'JSON encode'],
            'default' => ['usage' => '{$val|default:"N/A"}', 'desc' => 'Default value'],
            'count' => ['usage' => '{$arr|count}', 'desc' => 'Array count'],
            'join' => ['usage' => '{$arr|join:","}', 'desc' => 'Join array'],
        ],
    ], JSON_PRETTY_PRINT);
};
