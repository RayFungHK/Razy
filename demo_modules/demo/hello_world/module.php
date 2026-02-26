<?php
/*
 * module.php — Module Identity
 *
 * Every Razy module MUST have this file at its root.
 * It returns an array telling the framework who this module is.
 *
 * File location: demo/hello_world/module.php
 */
return [
    // Module code: <vendor>/<name> — must match the directory path
    'module_code' => 'demo/hello_world',

    // Human-readable name shown in CLI tools (e.g. `runapp` → `modules`)
    'name'        => 'Hello World',

    // Author name
    'author'      => 'Razy Framework',

    // Short description
    'description' => 'The simplest possible Razy module — one route, plain text output.',

    // Semantic version
    'version'     => '1.0.0',
];
