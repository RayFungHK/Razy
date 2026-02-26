<?php

/**
 * Markdown Service Module
 * 
 * This module demonstrates the "Shared Service Pattern" for handling
 * Composer package version conflicts within the same distributor.
 * 
 * PROBLEM: If moduleA requires markdown-lib@1.0 and moduleB requires markdown-lib@2.0,
 * they cannot both run in the same distributor (same PHP process, single autoloader).
 * 
 * SOLUTION: Create a shared service module that:
 * 1. Declares the package dependency in ONE place
 * 2. Exposes a version-agnostic API via Razy cross-module API
 * 3. All consumers depend on the service, not the library directly
 * 
 * This way:
 * - Only ONE version of the library is loaded
 * - API remains stable even if underlying library version changes
 * - Consumers are isolated from library internals
 */

return [
    'module_code' => 'system/markdown_service',
    'name' => 'Markdown Service',
    'author' => 'Razy Framework',
    'description' => 'Markdown parsing service using league/commonmark - demonstrates shared service pattern for version conflict resolution',
    'version' => '1.0.0',
];
