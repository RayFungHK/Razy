<?php
/**
 * Register Client API Closure
 * 
 * @llm API Command: registerClient
 * Called by other modules after they await this module.
 */

use Razy\Controller;

return function (string $moduleCode): bool {
    /** @var Controller $this */
    
    $this->addClient($moduleCode);
    return true;
};
