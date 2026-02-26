<?php
/**
 * DOM\Select Demo
 * 
 * @llm Demonstrates DOM\Select for dropdown generation.
 * Used extensively in production for form builders.
 * 
 * ## Production Pattern
 * 
 * From production-sample (individual.main.php):
 * ```php
 * 'selectbox_gender' => (new DOM\Select())
 *     ->setName('gender')
 *     ->applyOptions($api->getText($this->gender))
 * ```
 */

use Razy\Controller;
use Razy\DOM;
use Razy\DOM\Select;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Basic Select ===
    $select = new Select();
    $select->setName('country');
    $select->addOption('United States', 'us');
    $select->addOption('United Kingdom', 'uk');
    $select->addOption('Hong Kong', 'hk');
    
    $results['basic'] = [
        'html' => $select->saveHTML(),
        'description' => 'Simple select with options',
    ];
    
    // === Select with applyOptions (Key-Value Array) ===
    $statusOptions = [
        'active' => 'Active',
        'pending' => 'Pending',
        'inactive' => 'Inactive',
    ];
    
    $select = new Select('status-select');  // ID
    $select->setName('status')
        ->applyOptions($statusOptions);
    
    $results['apply_options'] = [
        'html' => $select->saveHTML(),
        'description' => 'Select from key=>value array',
    ];
    
    // === Multiple Select ===
    $select = new Select();
    $select->setName('tags[]')
        ->setAttribute('multiple', 'multiple')
        ->applyOptions([
            'php' => 'PHP',
            'js' => 'JavaScript',
            'python' => 'Python',
            'go' => 'Go',
        ]);
    
    $results['multiple'] = [
        'html' => $select->saveHTML(),
        'description' => 'Multiple selection dropdown',
    ];
    
    // === Select with Custom Convertor ===
    $users = [
        ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ['id' => 2, 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
        ['id' => 3, 'name' => 'Bob Smith', 'email' => 'bob@example.com'],
    ];
    
    $select = new Select();
    $select->setName('user_id')
        ->applyOptions($users, function(DOM $option, $key, $user) {
            $option->setText($user['name'] . ' (' . $user['email'] . ')')
                ->setAttribute('value', $user['id'])
                ->setDataset('email', $user['email']);
        });
    
    $results['custom_convertor'] = [
        'html' => $select->saveHTML(),
        'description' => 'Custom option rendering with convertor function',
    ];
    
    // === Production Pattern: i18n Integration ===
    // Simulating the production pattern with language text
    $genderText = [
        'M' => 'Male',
        'F' => 'Female',
        'O' => 'Other',
    ];
    
    $select = new Select();
    $select->setName('gender')
        ->addClass('form-select')
        ->applyOptions($genderText);
    
    $results['production_pattern'] = [
        'html' => $select->saveHTML(),
        'code' => "(new Select())->setName('gender')->applyOptions(\$api->getText(\$this->gender))",
        'description' => 'Pattern from production with i18n text lookup',
    ];
    
    // === Select from Database (Pattern) ===
    $results['database_pattern'] = [
        'code' => <<<'PHP'
// From production: pageblock.main.php
$select = (new Select())
    ->setName('page_id')
    ->applyOptions($dba->prepare()
        ->from('web_page')
        ->where('!disabled')
        ->lazyKeyValuePair('page_id', 'chinese_name')
    );
PHP,
        'description' => 'Select options directly from database query',
    ];
    
    // === Select with Placeholder ===
    $select = new Select();
    $select->setName('category')
        ->addClass('form-select');
    
    // Add placeholder option
    $placeholder = $select->addOption('-- Select Category --', '');
    $placeholder->setAttribute('disabled', null)
        ->setAttribute('selected', null);
    
    // Add actual options
    $select->applyOptions([
        'tech' => 'Technology',
        'news' => 'News',
        'sports' => 'Sports',
    ]);
    
    $results['placeholder'] = [
        'html' => $select->saveHTML(),
        'description' => 'Select with disabled placeholder',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
