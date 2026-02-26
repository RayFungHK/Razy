<?php
/**
 * Thread Demo Controller
 * 
 * @llm Main controller for the Thread Demo module.
 * Demonstrates ThreadManager for async task execution.
 * 
 * Two modes supported:
 * 1. Inline mode - Execute PHP callable synchronously (blocking)
 * 2. Process mode - Execute shell command asynchronously (non-blocking)
 * 
 * Key Classes:
 * - ThreadManager: Manages thread pool with concurrency control
 * - Thread: Represents a single thread with status, result, stdout/stderr
 */

namespace Razy\Module\thread_demo;

use Razy\Agent;
use Razy\Controller;
use Razy\ThreadManager;

return new class extends Controller {
    private ?ThreadManager $threadManager = null;
    
    /**
     * Module Initialization
     * 
     * @llm Sets up routes for the thread demo module.
     */
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/'        => 'main',
            'inline'   => 'inline',
            'process'  => 'process',
            'complex'  => 'complex',   // spawnPHPCode demo with base64 encoding
            'multi'    => 'multi',
            'parallel' => 'parallel',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Thread Demo',
                'description' => 'ThreadManager for async task execution',
                'url'         => '/thread_demo/',
                'category'    => 'Advanced',
                'icon'        => '🧵',
                'routes'      => '6 routes: /, inline, process, complex, multi, parallel',
            ];
        });

        return true;
    }
    
    /**
     * Get or create ThreadManager instance
     */
    public function getThreadManager(): ThreadManager
    {
        if ($this->threadManager === null) {
            $this->threadManager = new ThreadManager();
        }
        return $this->threadManager;
    }
};
