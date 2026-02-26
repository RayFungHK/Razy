<?php
/**
 * HashMap Object Keys Demo
 * 
 * @llm Demonstrates using objects as HashMap keys.
 * 
 * ## Object Key Behavior
 * 
 * When pushing an object without a custom key:
 * - Key is generated as: 'o:' . spl_object_hash($object)
 * 
 * When using has() or remove() with an object:
 * - Automatically calculates spl_object_hash internally
 */

use Razy\Controller;
use Razy\HashMap;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Object as Value (Auto Key) ===
    $map = new HashMap();
    $obj = new \stdClass();
    $obj->name = 'Test Object';
    
    $map->push($obj);  // Key becomes o:spl_object_hash($obj)
    
    $results['object_value'] = [
        'has_object' => $map->has($obj),  // has() detects object and uses spl_object_hash
        'description' => 'Objects auto-keyed by spl_object_hash',
    ];
    
    // === Object Lookup Pattern ===
    $map = new HashMap();
    
    // Create multiple objects
    $user1 = new \stdClass();
    $user1->id = 1;
    $user1->name = 'John';
    
    $user2 = new \stdClass();
    $user2->id = 2;
    $user2->name = 'Jane';
    
    // Store with object hash as key
    $map->push('User 1 data', spl_object_hash($user1));
    $map->push('User 2 data', spl_object_hash($user2));
    
    $results['object_lookup'] = [
        'user1_exists' => $map->has($user1),
        'user2_exists' => $map->has($user2),
        'description' => 'Lookup data by object reference',
    ];
    
    // === Remove by Object ===
    $map->remove($user1);
    
    $results['remove_object'] = [
        'user1_after_remove' => $map->has($user1),
        'user2_still_exists' => $map->has($user2),
        'description' => 'remove() with object calculates hash',
    ];
    
    // === Real World: Event Listener Registry ===
    $listenerMap = new HashMap();
    
    class EventListener {
        public function __construct(public string $event, public \Closure $handler) {}
    }
    
    $listener1 = new EventListener('click', fn() => 'clicked');
    $listener2 = new EventListener('submit', fn() => 'submitted');
    
    $listenerMap->push($listener1);
    $listenerMap->push($listener2);
    
    $results['event_registry'] = [
        'count' => count($listenerMap),
        'has_listener1' => $listenerMap->has($listener1),
        'description' => 'Event listener registry pattern',
    ];
    
    // === Unregister Listener ===
    $listenerMap->remove($listener1);
    
    $results['unregister'] = [
        'listener1_removed' => !$listenerMap->has($listener1),
        'listener2_remains' => $listenerMap->has($listener2),
        'description' => 'Remove specific listener by reference',
    ];
    
    // === Mixed Keys ===
    $map = new HashMap();
    
    $obj = new \stdClass();
    $map->push('string value', 'string_key');  // c:string_key
    $map->push($obj);                          // o:spl_object_hash
    $map->push('auto value');                  // i:guid
    
    $results['mixed_keys'] = [
        'count' => count($map),
        'has_string' => $map->has('c:string_key'),
        'has_object' => $map->has($obj),
        'description' => 'Mix of c:, o:, and i: prefixed keys',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
