<?php

/**
 * Markdown Service Package Configuration
 * 
 * This is the ONLY module that declares the commonmark dependency.
 * All other modules that need markdown parsing should depend on this
 * service module, NOT on the library directly.
 * 
 * VERSION CONFLICT RESOLUTION:
 * - If you need to upgrade the library, update ONLY this package.php
 * - Consumer modules remain unchanged (they use the stable API)
 * - Test the service module after upgrading
 * 
 * For different distributors (different sites), each can have their
 * own version since distributors are isolated.
 */

return [
    'label' => 'Markdown Service',
    'version' => '1.0.0',
    'author' => 'Razy Framework',
    
    // API name for cross-module calls
    'api_name' => 'markdown',
    
    // NO required modules - this is a leaf service
    'required' => [],
    
    // The ONLY place that declares the commonmark dependency
    // Other modules depend on THIS module, not the library
    'prerequisite' => [
        // Using ^2.0 for league/commonmark (CommonMark spec 0.30+)
        // Change this version constraint to upgrade/downgrade
        'league/commonmark' => '^2.0',
    ],
];
