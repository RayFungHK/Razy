<?php
/**
 * Nested DOM Demo
 * 
 * @llm Demonstrates building complex nested DOM structures.
 */

use Razy\Controller;
use Razy\DOM;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Simple Nesting ===
    $container = new DOM();
    $container->setTag('div')
        ->addClass('container');
    
    $header = new DOM();
    $header->setTag('h1')
        ->setText('Welcome');
    
    $paragraph = new DOM();
    $paragraph->setTag('p')
        ->setText('This is nested content.');
    
    $container->append($header)
        ->append($paragraph);
    
    $results['simple'] = [
        'html' => $container->saveHTML(),
        'description' => 'Container with header and paragraph',
    ];
    
    // === Prepend vs Append ===
    $list = new DOM();
    $list->setTag('ul');
    
    $item1 = new DOM();
    $item1->setTag('li')->setText('Item 1');
    
    $item2 = new DOM();
    $item2->setTag('li')->setText('Item 2');
    
    $item3 = new DOM();
    $item3->setTag('li')->setText('Item 3 (prepended)');
    
    $list->append($item1)
        ->append($item2)
        ->prepend($item3);  // Prepend adds to beginning
    
    $results['prepend'] = [
        'html' => $list->saveHTML(),
        'description' => 'List with prepended item',
    ];
    
    // === Building a Card Component ===
    $card = new DOM();
    $card->setTag('div')
        ->addClass(['card', 'shadow-sm'])
        ->setDataset('id', 1);
    
    $cardHeader = new DOM();
    $cardHeader->setTag('div')
        ->addClass('card-header')
        ->setText('Card Title');
    
    $cardBody = new DOM();
    $cardBody->setTag('div')
        ->addClass('card-body');
    
    $cardText = new DOM();
    $cardText->setTag('p')
        ->addClass('card-text')
        ->setText('Some example content.');
    
    $cardButton = new DOM();
    $cardButton->setTag('button')
        ->addClass(['btn', 'btn-primary'])
        ->setAttribute('type', 'button')
        ->setText('Click me');
    
    $cardBody->append($cardText)
        ->append($cardButton);
    
    $card->append($cardHeader)
        ->append($cardBody);
    
    $results['card'] = [
        'html' => $card->saveHTML(),
        'description' => 'Bootstrap-style card component',
    ];
    
    // === Building a Table ===
    $table = new DOM();
    $table->setTag('table')
        ->addClass('table');
    
    // Table header
    $thead = new DOM();
    $thead->setTag('thead');
    
    $headerRow = new DOM();
    $headerRow->setTag('tr');
    
    foreach (['ID', 'Name', 'Email'] as $header) {
        $th = new DOM();
        $th->setTag('th')
            ->setAttribute('scope', 'col')
            ->setText($header);
        $headerRow->append($th);
    }
    $thead->append($headerRow);
    
    // Table body
    $tbody = new DOM();
    $tbody->setTag('tbody');
    
    $data = [
        [1, 'John Doe', 'john@example.com'],
        [2, 'Jane Doe', 'jane@example.com'],
        [3, 'Bob Smith', 'bob@example.com'],
    ];
    
    foreach ($data as $row) {
        $tr = new DOM();
        $tr->setTag('tr');
        
        foreach ($row as $cell) {
            $td = new DOM();
            $td->setTag('td')
                ->setText((string) $cell);
            $tr->append($td);
        }
        $tbody->append($tr);
    }
    
    $table->append($thead)
        ->append($tbody);
    
    $results['table'] = [
        'html' => $table->saveHTML(),
        'description' => 'Complete table with header and data rows',
    ];
    
    // === Building a Navigation ===
    $nav = new DOM();
    $nav->setTag('nav')
        ->addClass('navbar');
    
    $ul = new DOM();
    $ul->setTag('ul')
        ->addClass('nav');
    
    $links = [
        ['Home', '/', true],
        ['About', '/about', false],
        ['Contact', '/contact', false],
    ];
    
    foreach ($links as [$text, $href, $active]) {
        $li = new DOM();
        $li->setTag('li')
            ->addClass('nav-item');
        
        $a = new DOM();
        $a->setTag('a')
            ->addClass('nav-link')
            ->setAttribute('href', $href)
            ->setText($text);
        
        if ($active) {
            $a->addClass('active')
                ->setAttribute('aria-current', 'page');
        }
        
        $li->append($a);
        $ul->append($li);
    }
    
    $nav->append($ul);
    
    $results['navigation'] = [
        'html' => $nav->saveHTML(),
        'description' => 'Navigation with active state',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
