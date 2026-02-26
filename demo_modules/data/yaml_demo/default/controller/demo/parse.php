<?php
/**
 * YAML Parse Demo
 * 
 * @llm Demonstrates parsing YAML strings and files.
 */

use Razy\Controller;
use Razy\YAML;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Simple Key-Value ===
    $yaml = <<<YAML
name: MyApp
version: 1.0
debug: true
YAML;
    
    $results['simple'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Simple key-value pairs',
    ];
    
    // === Nested Structure ===
    $yaml = <<<YAML
database:
  host: localhost
  port: 3306
  credentials:
    username: root
    password: secret
YAML;
    
    $results['nested'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Nested mappings',
    ];
    
    // === Lists/Sequences ===
    $yaml = <<<YAML
features:
  - authentication
  - api
  - admin-panel
colors:
  - red
  - green
  - blue
YAML;
    
    $results['lists'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Array sequences',
    ];
    
    // === Mixed Nested ===
    $yaml = <<<YAML
users:
  - name: John
    age: 30
    roles:
      - admin
      - user
  - name: Jane
    age: 25
    roles:
      - user
YAML;
    
    $results['mixed'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Mixed nested structures',
    ];
    
    // === Multi-line Strings ===
    $yaml = <<<YAML
description: |
  This is a literal block.
  Line breaks are preserved.
  Indentation determines scope.
summary: >
  This is a folded block
  that will be joined into
  a single line with spaces.
YAML;
    
    $results['multiline'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Literal (|) and folded (>) blocks',
    ];
    
    // === Flow Collections ===
    $yaml = <<<YAML
inline_array: [1, 2, 3, 4, 5]
inline_object: {name: John, age: 30}
mixed: {users: [John, Jane], count: 2}
YAML;
    
    $results['flow'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Inline flow syntax',
    ];
    
    // === Type Casting ===
    $yaml = <<<YAML
string: hello
integer: 42
float: 3.14
boolean_true: true
boolean_false: false
null_value: null
null_tilde: ~
quoted: "42"
YAML;
    
    $parsed = YAML::parse($yaml);
    $results['types'] = [
        'yaml' => $yaml,
        'parsed' => $parsed,
        'types' => array_map('gettype', $parsed),
        'description' => 'Automatic type casting',
    ];
    
    // === Comments ===
    $yaml = <<<YAML
# This is a comment
name: MyApp  # Inline comment
# Another comment
version: 1.0
YAML;
    
    $results['comments'] = [
        'yaml' => $yaml,
        'parsed' => YAML::parse($yaml),
        'description' => 'Comments are ignored',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
