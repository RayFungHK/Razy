<?php
/**
 * SimplifiedMessage Demo - Basic Operations
 * 
 * @llm JSON endpoint for message creation, parsing and encoding demos.
 */

use Razy\Controller;
use Razy\SimplifiedMessage;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Creating Message ===
    $msg = new SimplifiedMessage('SEND');
    $msg->setHeader('destination', '/queue/orders');
    $msg->setHeader('content-type', 'application/json');
    $msg->setBody(json_encode(['order_id' => 123, 'amount' => 99.99]));
    
    $results['create'] = [
        'command' => $msg->getCommand(),
        'headers' => [
            'destination' => $msg->getHeader('destination'),
            'content-type' => $msg->getHeader('content-type'),
        ],
        'body' => $msg->getBody(),
        'raw_message' => $msg->getMessage(),
        'description' => 'Create and serialize message',
    ];
    
    // === Parsing Message ===
    $rawMessage = "MESSAGE\r\nid:msg-001\r\ndestination:/topic/notifications\r\n\r\nHello, World!\0\r\n";
    $parsed = SimplifiedMessage::Fetch($rawMessage);
    
    $results['parse'] = [
        'command' => $parsed->getCommand(),
        'id' => $parsed->getHeader('id'),
        'destination' => $parsed->getHeader('destination'),
        'body' => $parsed->getBody(),
        'description' => 'Parse raw STOMP message',
    ];
    
    // === Escape Characters ===
    $textWithColon = 'key:value';
    $textWithSlash = 'path\\to\\file';
    
    $results['encoding'] = [
        'original' => $textWithColon,
        'encoded' => SimplifiedMessage::Encode($textWithColon),
        'decoded' => SimplifiedMessage::Decode(SimplifiedMessage::Encode($textWithColon)),
        'description' => 'Encode/decode colons and slashes',
    ];
    
    // === Common Commands ===
    $results['common_commands'] = [
        'CONNECT' => 'Establish connection',
        'CONNECTED' => 'Connection acknowledged',
        'SEND' => 'Send message to destination',
        'SUBSCRIBE' => 'Subscribe to destination',
        'UNSUBSCRIBE' => 'Unsubscribe from destination',
        'MESSAGE' => 'Message from server',
        'ACK' => 'Acknowledge message',
        'NACK' => 'Negative acknowledge',
        'DISCONNECT' => 'Close connection',
        'ERROR' => 'Error response',
    ];
    
    // === Real-World Patterns ===
    $results['patterns'] = [
        'subscribe' => [
            'code' => <<<'PHP'
$subscribe = new SimplifiedMessage('SUBSCRIBE');
$subscribe->setHeader('id', 'sub-001');
$subscribe->setHeader('destination', '/topic/updates');
$subscribe->setHeader('ack', 'auto');
echo $subscribe->getMessage();
PHP,
        ],
        'ack' => [
            'code' => <<<'PHP'
$ack = new SimplifiedMessage('ACK');
$ack->setHeader('id', 'msg-001');
$ack->setHeader('transaction', 'tx-001');
echo $ack->getMessage();
PHP,
        ],
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
