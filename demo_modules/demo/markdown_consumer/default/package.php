<?php

/**
 * Markdown Consumer Package Configuration
 * 
 * KEY POINT: This module does NOT declare the commonmark library directly.
 * Instead, it requires the markdown_service module which provides the API.
 * 
 * BENEFITS:
 * 1. No version conflict - service module manages the version
 * 2. Stable API - service API doesn't change even if library version changes
 * 3. Easier testing - can mock the service API
 * 4. Single upgrade point - update service module to upgrade library
 */

return [
    'label' => 'Markdown Consumer Demo',
    'version' => '1.0.0',
    'author' => 'Razy Framework',
    
    // Require the service module, NOT the library
    // This is the key to avoiding version conflicts!
    'required' => [
        'system/markdown_service' => '*',  // ANY version of the service
    ],
    
    // NO prerequisite for commonmark!
    // The service module handles the library dependency
    'prerequisite' => [],
];
