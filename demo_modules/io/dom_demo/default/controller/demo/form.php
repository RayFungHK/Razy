<?php
/**
 * Complete Form Demo
 * 
 * @llm Demonstrates building a complete form with DOM.
 * Returns JSON with the form HTML for AJAX display.
 */

use Razy\Controller;
use Razy\DOM;
use Razy\DOM\Select;
use Razy\DOM\Input;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');
    
    $results = [];

    // Build a complete registration form
    $form = new DOM();
    $form->setTag('form')
        ->addClass('registration-form')
        ->setAttribute('method', 'post')
        ->setAttribute('action', '/register')
        ->setDataset('ajax', 'true');
    
    // === Name Field ===
    $nameGroup = createFormGroup('name', 'Full Name');
    $nameInput = new Input('name');
    $nameInput->setName('name')
        ->setAttribute('type', 'text')
        ->setAttribute('required', null)
        ->addClass('form-control');
    $nameGroup->append($nameInput);
    $form->append($nameGroup);
    
    // === Email Field ===
    $emailGroup = createFormGroup('email', 'Email Address');
    $emailInput = new Input('email');
    $emailInput->setName('email')
        ->setAttribute('type', 'email')
        ->setAttribute('required', null)
        ->addClass('form-control');
    $emailGroup->append($emailInput);
    $form->append($emailGroup);
    
    // === Password Field ===
    $passGroup = createFormGroup('password', 'Password');
    $passInput = new Input('password');
    $passInput->setName('password')
        ->setAttribute('type', 'password')
        ->setAttribute('required', null)
        ->setAttribute('minlength', 8)
        ->addClass('form-control');
    $passGroup->append($passInput);
    $form->append($passGroup);
    
    // === Country Select ===
    $countryGroup = createFormGroup('country', 'Country');
    $countrySelect = new Select('country');
    $countrySelect->setName('country')
        ->addClass('form-select')
        ->applyOptions([
            '' => '-- Select Country --',
            'us' => 'United States',
            'uk' => 'United Kingdom',
            'hk' => 'Hong Kong',
            'jp' => 'Japan',
        ]);
    $countryGroup->append($countrySelect);
    $form->append($countryGroup);
    
    // === Role Select (Multiple) ===
    $roleGroup = createFormGroup('roles', 'Roles');
    $roleSelect = new Select('roles');
    $roleSelect->setName('roles[]')
        ->setAttribute('multiple', 'multiple')
        ->addClass('form-select')
        ->applyOptions([
            'user' => 'User',
            'editor' => 'Editor',
            'admin' => 'Administrator',
        ]);
    $roleGroup->append($roleSelect);
    $form->append($roleGroup);
    
    // === Submit Button ===
    $submitGroup = new DOM();
    $submitGroup->setTag('div')->addClass('mb-3');
    
    $submit = new DOM();
    $submit->setTag('button')
        ->setAttribute('type', 'submit')
        ->addClass(['btn', 'btn-primary'])
        ->setText('Register');
    
    $reset = new DOM();
    $reset->setTag('button')
        ->setAttribute('type', 'reset')
        ->addClass(['btn', 'btn-secondary', 'ms-2'])
        ->setText('Reset');
    
    $submitGroup->append($submit)->append($reset);
    $form->append($submitGroup);
    
    $results['registration_form'] = [
        'html' => $form->saveHTML(),
        'description' => 'Complete registration form with text, email, password, select, and buttons',
    ];
    
    $results['form_attributes'] = [
        'method' => 'post',
        'action' => '/register',
        'data-ajax' => 'true',
        'description' => 'Form-level attributes set via DOM fluent API',
    ];
    
    $results['components_used'] = [
        'DOM' => 'Form container, labels, buttons, groups',
        'Input' => 'Text, email, password fields',
        'Select' => 'Country dropdown, roles multi-select',
        'description' => 'All three DOM classes used together',
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);
};

/**
 * Helper function to create form group
 */
function createFormGroup(string $id, string $labelText, bool $addFor = true): DOM
{
    $group = new DOM();
    $group->setTag('div')->addClass('mb-3');
    
    $label = new DOM();
    $label->setTag('label')
        ->addClass('form-label')
        ->setText($labelText);
    
    if ($addFor) {
        $label->setAttribute('for', $id);
    }
    
    $group->append($label);
    return $group;
}
