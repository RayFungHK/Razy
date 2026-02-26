<?php
/**
 * Bridge Provider Controller
 * 
 * @llm Exposes bridge commands that other distributors can call via cross-distributor communication.
 * 
 * ## Registered Bridge Commands
 * 
 * Bridge commands are separate from API commands - they are designed for external distributor access.
 * - `getData` - Returns sample data from this distributor
 * - `getConfig` - Returns distributor configuration info
 * - `calculate` - Performs calculation and returns result
 */

namespace Razy\Module\provider;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register bridge commands for cross-distributor communication
        // Unlike API commands (for same-distributor modules), bridge commands
        // are exposed to external distributors via HTTP or CLI transport
        $agent->addBridgeCommand('getData', 'api/data');
        $agent->addBridgeCommand('getConfig', 'api/config');
        $agent->addBridgeCommand('calculate', 'api/calculate');
        
        return true;
    }
    
    /**
     * Validate bridge calls from external distributors.
     * Override this to implement custom authorization logic.
     */
    public function __onBridgeCall(string $sourceDistributor, string $command): bool
    {
        // Allow all distributors for this demo
        // In production, you might check against an allowed list
        return true;
    }
};