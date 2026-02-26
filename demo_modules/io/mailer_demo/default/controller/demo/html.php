<?php
/**
 * HTML Email Demo
 * 
 * @llm Demonstrates sending HTML emails with Mailer.
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'html_only' => [
            'code' => <<<'PHP'
$mailer->setSubject('Welcome to Our Service');
$mailer->html('<h1>Welcome!</h1><p>Thank you for signing up.</p>');
$mailer->send();
PHP,
        ],
        
        'html_with_text_fallback' => [
            'description' => 'Include both for email clients that dont support HTML',
            'code' => <<<'PHP'
$mailer->setSubject('Order Confirmation');

// HTML version
$mailer->html(<<<HTML
<html>
<body>
    <h1>Order Confirmed</h1>
    <p>Your order #12345 has been confirmed.</p>
    <table>
        <tr><td>Item</td><td>Widget</td></tr>
        <tr><td>Quantity</td><td>2</td></tr>
        <tr><td>Total</td><td>\$49.99</td></tr>
    </table>
</body>
</html>
HTML);

// Plain text version (for clients that can't render HTML)
$mailer->text(<<<TEXT
Order Confirmed

Your order #12345 has been confirmed.

Item: Widget
Quantity: 2
Total: \$49.99
TEXT);

$mailer->send();
PHP,
        ],
        
        'template_email' => [
            'description' => 'Using Razy Template for HTML emails',
            'code' => <<<'PHP'
// email_template.tpl
// <h1>Hello, {$name}!</h1>
// <p>Your account has been created.</p>

$template = $this->getTemplate();
$template->load('email_template.tpl');
$template->assign([
    'name' => 'John Doe',
]);

$mailer->html($template->output());
$mailer->send();
PHP,
        ],
        
        'production_pattern' => [
            'description' => 'From production-sample usage',
            'code' => <<<'PHP'
// Production pattern with Office 365
$mailer = new Mailer('smtp.office365.com', 587, 5, 5);
$mailer->setProtocol(Mailer::SECURE_TLSv12);
$mailer->setCredentials($config['email'], $config['password']);

$mailer->from($config['email'], $config['sender_name']);
$mailer->to($recipient['email'], $recipient['name']);
$mailer->setSubject($subject);
$mailer->html($htmlContent);

try {
    $mailer->send();
    // Success
} catch (\Razy\Error $e) {
    // Handle send failure
    error_log('Mail error: ' . $e->getMessage());
}
PHP,
        ],
    ], JSON_PRETTY_PRINT);
};
