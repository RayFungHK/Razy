<?php
/**
 * DOM\Input Demo
 * 
 * @llm Demonstrates DOM\Input for input generation.
 */

use Razy\Controller;
use Razy\DOM;
use Razy\DOM\Input;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Basic Input ===
    $input = new Input();
    $input->setName('username')
        ->setAttribute('type', 'text')
        ->setAttribute('placeholder', 'Enter username');
    
    $results['basic'] = [
        'html' => $input->saveHTML(),
        'description' => 'Text input with placeholder',
    ];
    
    // === Input Types ===
    $types = ['text', 'password', 'email', 'number', 'date', 'file', 'hidden'];
    $inputs = [];
    
    foreach ($types as $type) {
        $input = new Input();
        $input->setName($type . '_field')
            ->setAttribute('type', $type);
        $inputs[$type] = $input->saveHTML();
    }
    
    $results['types'] = [
        'inputs' => $inputs,
        'description' => 'Various input types',
    ];
    
    // === Input with Validation Attributes ===
    $input = new Input();
    $input->setName('email')
        ->setAttribute('type', 'email')
        ->setAttribute('required', null)
        ->setAttribute('minlength', 5)
        ->setAttribute('maxlength', 100)
        ->setAttribute('pattern', '[a-z0-9._%+-]+@[a-z0-9.-]+\\.[a-z]{2,}');
    
    $results['validation'] = [
        'html' => $input->saveHTML(),
        'description' => 'Email input with validation attributes',
    ];
    
    // === Checkbox ===
    $checkbox = new Input();
    $checkbox->setName('agree')
        ->setAttribute('type', 'checkbox')
        ->setAttribute('value', '1')
        ->setAttribute('checked', null);
    
    $results['checkbox'] = [
        'html' => $checkbox->saveHTML(),
        'description' => 'Checked checkbox',
    ];
    
    // === Radio Buttons ===
    $radios = [];
    $options = ['option1' => 'First', 'option2' => 'Second', 'option3' => 'Third'];
    
    foreach ($options as $value => $label) {
        $radio = new Input();
        $radio->setName('choice')  // Same name for radio group
            ->setAttribute('type', 'radio')
            ->setAttribute('value', $value);
        $radios[] = $radio->saveHTML() . ' ' . $label;
    }
    
    $results['radio'] = [
        'html' => implode('<br>', $radios),
        'description' => 'Radio button group',
    ];
    
    // === Disabled and Readonly ===
    $disabled = new Input();
    $disabled->setName('locked')
        ->setAttribute('type', 'text')
        ->setAttribute('value', 'Cannot change')
        ->setAttribute('disabled', null);
    
    $readonly = new Input();
    $readonly->setName('readonly_field')
        ->setAttribute('type', 'text')
        ->setAttribute('value', 'Read only')
        ->setAttribute('readonly', null);
    
    $results['states'] = [
        'disabled' => $disabled->saveHTML(),
        'readonly' => $readonly->saveHTML(),
        'description' => 'Disabled and readonly states',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
