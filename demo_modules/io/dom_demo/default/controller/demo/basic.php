<?php
/**
 * Basic DOM Demo
 * 
 * @llm Demonstrates DOM basics: tags, attributes, classes, text.
 * 
 * ## Creating Elements
 * 
 * ```php
 * $dom = new DOM($name, $id);  // Optional name and id
 * $dom->setTag('div');         // Set tag name
 * $dom->setText('content');    // Set text content
 * ```
 * 
 * ## Element Types
 * 
 * - Regular: `<div>content</div>`
 * - Void (self-closing): `<br />`, `<img />`
 */

use Razy\Controller;
use Razy\DOM;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Basic Element ===
    $div = new DOM();
    $div->setTag('div')
        ->setText('Hello, World!');
    
    $results['basic'] = [
        'html' => $div->saveHTML(),
        'description' => 'Simple div with text',
    ];
    
    // === Element with ID and Name ===
    $input = new DOM('username', 'user-input');
    $input->setTag('input')
        ->setAttribute('type', 'text')
        ->setAttribute('placeholder', 'Enter username')
        ->setVoidElement(true);
    
    $results['named'] = [
        'html' => $input->saveHTML(),
        'description' => 'Input with name and id',
    ];
    
    // === Adding Classes ===
    $button = new DOM();
    $button->setTag('button')
        ->addClass('btn')
        ->addClass('btn-primary')
        ->addClass(['btn-lg', 'rounded'])  // Array of classes
        ->setText('Click Me');
    
    $results['classes'] = [
        'html' => $button->saveHTML(),
        'description' => 'Button with multiple classes',
    ];
    
    // === Removing Classes ===
    $element = new DOM();
    $element->setTag('span')
        ->addClass(['class-a', 'class-b', 'class-c'])
        ->removeClass('class-b');
    
    $results['remove_class'] = [
        'html' => $element->saveHTML(),
        'description' => 'Span with class-b removed',
    ];
    
    // === Attributes ===
    $link = new DOM();
    $link->setTag('a')
        ->setAttribute('href', 'https://example.com')
        ->setAttribute('target', '_blank')
        ->setAttribute('disabled', null)  // Boolean attribute
        ->setText('Visit Site');
    
    $results['attributes'] = [
        'html' => $link->saveHTML(),
        'description' => 'Link with href, target, and boolean disabled',
    ];
    
    // === Data Attributes ===
    $card = new DOM();
    $card->setTag('div')
        ->addClass('card')
        ->setDataset('id', 123)
        ->setDataset('user', ['name' => 'John', 'role' => 'admin'])
        ->setText('User Card');
    
    $results['dataset'] = [
        'html' => $card->saveHTML(),
        'description' => 'Div with data-id and data-user (JSON)',
    ];
    
    // === Void Elements ===
    $br = new DOM();
    $br->setTag('br')->setVoidElement(true);
    
    $img = new DOM();
    $img->setTag('img')
        ->setAttribute('src', '/images/logo.png')
        ->setAttribute('alt', 'Logo')
        ->setVoidElement(true);
    
    $results['void'] = [
        'br' => $br->saveHTML(),
        'img' => $img->saveHTML(),
        'description' => 'Self-closing elements',
    ];
    
    // === HTML Escaping ===
    $safe = new DOM();
    $safe->setTag('p')
        ->setText('<script>alert("xss")</script>');
    
    $results['escaping'] = [
        'html' => $safe->saveHTML(),
        'description' => 'Script tags are escaped automatically',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
