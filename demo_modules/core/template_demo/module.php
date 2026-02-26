<?php
/**
 * Template Engine Demo Module Configuration
 * 
 * @llm Demonstrates the Razy Template Engine features:
 * - Variable tags with dot-path access
 * - Modifiers (upper, lower, capitalize, trim, join, etc.)
 * - Function tags (@if, @each, @repeat, @def, @template)
 * - Block system (START/END, WRAPPER, TEMPLATE, USE, INCLUDE, RECURSION)
 * - Dynamic block creation with Template\Entity API
 * - Parameter resolution chain (Entity -> Block -> Source -> Template)
 */

return [
    'module_code' => 'core/template_demo',
    'name'        => 'Template Engine Demo',
    'author'      => 'Razy Framework',
    'description' => 'Comprehensive demo of the Razy Template Engine',
    'version'     => '1.0.0',
];
