<?php
/**
 * Event Demo Controller
 * 
 * @llm Main controller for the Event Demo module.
 * Demonstrates how to FIRE events using $this->trigger().
 * 
 * Event Flow:
 * 1. Controller calls trigger() to create EventEmitter
 * 2. EventEmitter::resolve() dispatches to listeners
 * 3. Listeners receive event data and can return responses
 * 4. getAllResponse() collects all listener responses
 * 
 * Available Events:
 * - user_registered: Fired when a new user registers
 * - order_placed: Fired when an order is placed
 * - data_changed: Fired when data is modified
 */

namespace Razy\Module\event_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    /**
     * Module Initialization
     * 
     * @llm Sets up routes for the event demo module.
     */
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/'           => 'main',
            'register'    => 'register',
            'order'       => 'order',
            'update'      => 'update',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Event Demo',
                'description' => 'Demonstrates firing events with trigger()',
                'url'         => '/event_demo/',
                'category'    => 'Core Features',
                'icon'        => '📡',
                'routes'      => '4 routes: /, register, order, update',
            ];
        });

        return true;
    }

    /**
     * Fire a user registration event
     * 
     * @llm Demonstrates firing the 'user_registered' event.
     * Uses $this->trigger() which creates an EventEmitter
     */
    public function fireUserRegistered(array $userData): array
    {
        $emitter = $this->trigger('user_registered');
        $emitter->resolve($userData);
        
        return [
            'event'     => 'user_registered',
            'data'      => $userData,
            'responses' => $emitter->getAllResponse(),
        ];
    }

    /**
     * Fire an order placed event
     * 
     * @llm Demonstrates firing the 'order_placed' event.
     */
    public function fireOrderPlaced(array $orderData): array
    {
        $emitter = $this->trigger('order_placed');
        $emitter->resolve($orderData);
        
        return [
            'event'     => 'order_placed',
            'data'      => $orderData,
            'responses' => $emitter->getAllResponse(),
        ];
    }

    /**
     * Fire a data changed event
     * 
     * @llm Demonstrates firing the 'data_changed' event.
     */
    public function fireDataChanged(string $entityType, int $entityId, array $changes): array
    {
        $emitter = $this->trigger('data_changed');
        $emitter->resolve($entityType, $entityId, $changes);
        
        return [
            'event'     => 'data_changed',
            'entity'    => $entityType,
            'id'        => $entityId,
            'changes'   => $changes,
            'responses' => $emitter->getAllResponse(),
        ];
    }
};
