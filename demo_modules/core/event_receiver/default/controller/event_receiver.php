<?php
/**
 * Event Receiver Controller
 * 
 * @llm Main controller for the Event Receiver module.
 * Demonstrates how to LISTEN to events using $module->listen().
 * 
 * Listening Pattern:
 *   $module->listen('source_module:event_name', handler);
 * 
 * Handler can be:
 * - A Closure: function($args) { ... }
 * - A file path: 'events/user_registered' -> controller/events/user_registered.php
 */

namespace Razy\Module\event_receiver;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    private array $receivedEvents = [];

    /**
     * Module Initialization - Register Event Listeners
     * 
     * @llm Key method: Registers listeners for events from other modules.
     * Uses Agent->listen() with format: 'vendor/module_code:event_name'
     */
    public function __onInit(Agent $agent): bool
    {
        // Listen to user_registered event - inline closure
        // Format: 'vendor/module_code:event_name'
        $agent->listen('core/event_demo:user_registered', function (array $userData): array {
            $this->logEvent('user_registered', $userData);
            
            return [
                'receiver' => 'event_receiver',
                'action'   => 'logged_registration',
                'user_id'  => $userData['id'] ?? 'unknown',
                'message'  => "Welcome email queued for {$userData['name']}",
            ];
        });

        // Listen to order_placed event - inline closure
        $agent->listen('core/event_demo:order_placed', function (array $orderData): array {
            $this->logEvent('order_placed', $orderData);
            
            $commission = ($orderData['total'] ?? 0) * 0.05;
            
            return [
                'receiver'      => 'event_receiver',
                'action'        => 'order_processed',
                'order_id'      => $orderData['order_id'] ?? 'unknown',
                'product'       => $orderData['product'] ?? 'unknown',
                'quantity'      => $orderData['quantity'] ?? 0,
                'total'         => $orderData['total'] ?? 0,
                'commission'    => $commission,
                'message'       => 'Order confirmation sent, inventory updated',
            ];
        });

        // Listen to data_changed event - inline closure
        $agent->listen('core/event_demo:data_changed', function (string $entityType, int $entityId, array $changes): array {
            $this->logEvent('data_changed', compact('entityType', 'entityId', 'changes'));
            
            return [
                'receiver' => 'event_receiver',
                'action'   => 'audit_logged',
                'entity'   => "{$entityType}:{$entityId}",
                'message'  => 'Change recorded in audit log',
            ];
        });

        // Register routes
        $agent->addLazyRoute([
            '/' => 'main',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Event Receiver',
                'description' => 'Demonstrates listening to events with listen()',
                'url'         => '/event_receiver/',
                'category'    => 'Core Features',
                'icon'        => '👂',
                'routes'      => '1 route: /',
            ];
        });

        return true;
    }

    /**
     * Log an event for display
     */
    public function logEvent(string $event, mixed $data): void
    {
        $this->receivedEvents[] = [
            'event'     => $event,
            'data'      => $data,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get all received events
     */
    public function getReceivedEvents(): array
    {
        return $this->receivedEvents;
    }
};
