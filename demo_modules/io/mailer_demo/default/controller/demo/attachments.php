<?php
/**
 * Email Attachments Demo
 * 
 * @llm Demonstrates adding attachments to emails.
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'single_attachment' => [
            'code' => <<<'PHP'
$mailer->setSubject('Report Attached');
$mailer->text('Please find the monthly report attached.');

// Add single file
$mailer->addAttachment('/path/to/report.pdf');

$mailer->send();
PHP,
        ],
        
        'custom_filename' => [
            'code' => <<<'PHP'
// Specify custom display name
$mailer->addAttachment('/path/to/file.pdf', 'Monthly-Report-2024.pdf');
PHP,
        ],
        
        'multiple_attachments' => [
            'code' => <<<'PHP'
// Add one by one
$mailer->addAttachment('/path/to/file1.pdf', 'Report.pdf');
$mailer->addAttachment('/path/to/file2.xlsx', 'Data.xlsx');

// Or use array format
$mailer->addAttachment([
    '/path/to/file1.pdf' => 'Report.pdf',
    '/path/to/file2.xlsx' => 'Data.xlsx',
    '/path/to/file3.jpg' => 'Photo.jpg',
]);
PHP,
        ],
        
        'complete_example' => [
            'description' => 'Full email with attachments',
            'code' => <<<'PHP'
$mailer = new Mailer('smtp.office365.com', 587);
$mailer->setCredentials('sender@company.com', 'password');
$mailer->setProtocol(Mailer::SECURE_TLSv12);

$mailer->from('sender@company.com', 'HR Department')
       ->to('employee@company.com', 'John Doe')
       ->cc('hr-manager@company.com')
       ->setSubject('Your Contract Documents');

$mailer->html(<<<HTML
<p>Dear John,</p>
<p>Please find your contract documents attached.</p>
<p>Best regards,<br>HR Department</p>
HTML);

$mailer->addAttachment([
    '/documents/contract.pdf' => 'Employment-Contract.pdf',
    '/documents/policies.pdf' => 'Company-Policies.pdf',
]);

try {
    $mailer->send();
    echo 'Email sent successfully!';
} catch (\Razy\Error $e) {
    echo 'Failed to send: ' . $e->getMessage();
}
PHP,
        ],
    ], JSON_PRETTY_PRINT);
};
