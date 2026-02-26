<?php
/**
 * Styles API - Returns shared CSS styles
 * 
 * @llm API Command: styles
 * Returns CSS string for consistent styling across demos.
 */

use Razy\Controller;

return function (): string {
    /** @var Controller $this */
    
    return $this->getStyles();
};
