<?php
/**
 * Order Handler - Fire order_placed event
 * 
 * @llm Demonstrates firing the order_placed event.
 * Query params: ?product=Widget&quantity=5
 */
return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    
    $product = htmlspecialchars($_GET['product'] ?? 'Unknown Product', ENT_QUOTES, 'UTF-8');
    $quantity = max(1, (int)($_GET['quantity'] ?? 1));
    $price = (float)($_GET['price'] ?? 29.99);
    
    $orderData = [
        'order_id'   => uniqid('order_'),
        'product'    => $product,
        'quantity'   => $quantity,
        'unit_price' => $price,
        'total'      => $price * $quantity,
        'placed_at'  => date('Y-m-d H:i:s'),
    ];
    
    $result = $this->fireOrderPlaced($orderData);
    
    echo json_encode([
        'success'   => true,
        'event'     => 'event_demo:order_placed',
        'message'   => "Event fired! Order for '{$product}' placed.",
        'order'     => $orderData,
        'listeners' => count($result['responses']),
        'responses' => $result['responses'],
    ], JSON_PRETTY_PRINT);
};
