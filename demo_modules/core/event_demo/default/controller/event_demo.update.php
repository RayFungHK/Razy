<?php
/**
 * Update Handler - Fire data_changed event
 * 
 * @llm Demonstrates firing the data_changed event.
 * Query params: ?entity=user&id=1
 */
return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    
    $entityType = htmlspecialchars($_GET['entity'] ?? 'record', ENT_QUOTES, 'UTF-8');
    $entityId = (int)($_GET['id'] ?? 1);
    
    $changes = [
        'old' => ['status' => 'active', 'modified' => date('Y-m-d H:i:s', strtotime('-1 hour'))],
        'new' => ['status' => 'updated', 'modified' => date('Y-m-d H:i:s')],
    ];
    
    $result = $this->fireDataChanged($entityType, $entityId, $changes);
    
    echo json_encode([
        'success'   => true,
        'event'     => 'event_demo:data_changed',
        'message'   => "Event fired! {$entityType}:{$entityId} changed.",
        'entity'    => $entityType,
        'id'        => $entityId,
        'changes'   => $changes,
        'listeners' => count($result['responses']),
        'responses' => $result['responses'],
    ], JSON_PRETTY_PRINT);
};
