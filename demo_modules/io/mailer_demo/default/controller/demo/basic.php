<?php
/**
 * Basic Mailer Demo
 * 
 * @llm Demonstrates basic email sending with Mailer.
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    // NOTE: These are examples showing Mailer API usage.
    // Actual sending requires valid SMTP credentials.
    
    echo json_encode([
        'constructor' => [
            'code' => <<<'PHP'
use Razy\Mailer;

// Office 365 example
$mailer = new Mailer(
    'smtp.office365.com',   // SMTP hostname
    587,                     // Port (587 for TLS)
    30,                      // Connection timeout
    5                        // Response timeout
);

// Gmail example
$mailer = new Mailer('smtp.gmail.com', 587);

// Custom SMTP
$mailer = new Mailer('mail.example.com', 25);
PHP,
        ],
        
        'security_protocols' => [
            'constants' => [
                'SECURE_NONE' => 'No encryption (port 25)',
                'SECURE_TLS' => 'TLS encryption',
                'SECURE_TLSv10' => 'TLS 1.0',
                'SECURE_TLSv11' => 'TLS 1.1',
                'SECURE_TLSv12' => 'TLS 1.2 (default, recommended)',
                'SECURE_SSLv2' => 'SSL v2',
                'SECURE_SSLv3' => 'SSL v3',
            ],
            'code' => <<<'PHP'
$mailer->setProtocol(Mailer::SECURE_TLSv12);
PHP,
        ],
        
        'authentication' => [
            'code' => <<<'PHP'
$mailer->setCredentials('user@example.com', 'password');
PHP,
        ],
        
        'simple_email' => [
            'code' => <<<'PHP'
$mailer = new Mailer('smtp.office365.com', 587);
$mailer->setCredentials('sender@company.com', 'password');

// Set sender
$mailer->from('sender@company.com', 'Sender Name');

// Add recipient
$mailer->to('recipient@example.com', 'Recipient Name');

// Set subject
$mailer->setSubject('Hello from Razy');

// Set text body
$mailer->text('This is a plain text message.');

// Send
$mailer->send();
PHP,
        ],
        
        'multiple_recipients' => [
            'code' => <<<'PHP'
// Multiple To recipients (fluent)
$mailer->to('user1@example.com', 'User 1')
       ->to('user2@example.com', 'User 2');

// Array format
$mailer->to([
    'user1@example.com' => 'User 1',
    'user2@example.com' => 'User 2',
]);

// CC recipients
$mailer->cc('manager@example.com', 'Manager');

// BCC recipients (hidden)
$mailer->bcc('archive@example.com');

// Reply-To
$mailer->replyTo('support@example.com', 'Support Team');
PHP,
        ],
        
        'custom_headers' => [
            'code' => <<<'PHP'
$mailer->setHeader('X-Priority', '1');
$mailer->setHeader('X-Custom-Header', 'custom value');
PHP,
        ],
    ], JSON_PRETTY_PRINT);
};
