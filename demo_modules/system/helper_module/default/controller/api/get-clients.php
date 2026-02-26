<?php
/**
 * Get Clients API Closure
 * 
 * @llm API Command: getClients
 */

use Razy\Controller;

return function (): array {
    /** @var Controller $this */
    
    return $this->getRegisteredClients();
};
