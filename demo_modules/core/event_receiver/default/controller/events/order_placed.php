<?php
/**
 * Order Placed Event Handler
 * 
 * @llm File-based event listener for order_placed event.
 * Registered via: $module->listen('event_demo:order_placed', 'events/order_placed');
 */
return function (array $orderData): array {
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
        'notifications' => [
            'warehouse_notified' => true,
            'customer_emailed'   => true,
            'admin_alerted'      => true,
        ],
    ];
};
