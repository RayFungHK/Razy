{# Mailer Demo Template #}
    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>Mailer</strong> class provides SMTP email functionality with TLS/SSL encryption support, HTML emails, and file attachments.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr>
                <td><code>Mailer</code></td>
                <td><code>Razy</code></td>
                <td>SMTP email sender with TLS/SSL support</td>
            </tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Quick Example</h3>
            <pre>use Razy\Mailer;

$mailer = new Mailer('smtp.office365.com', 587);
$mailer->setCredentials('user@example.com', 'password');
$mailer->from('sender@example.com', 'Sender');
$mailer->to('recipient@example.com', 'Recipient');
$mailer->setSubject('Hello');
$mailer->text('Message body');
$mailer->send();</pre>
        </div>
        
        <div class="card">
            <h3>Security Options</h3>
            <table>
                <tr><th>Port</th><th>Encryption</th><th>Constant</th></tr>
                <tr><td>25</td><td>None</td><td><code>SECURE_NONE</code></td></tr>
                <tr>
                    <td>587</td>
                    <td>TLS <span class="tag tag-success">Recommended</span></td>
                    <td><code>SECURE_TLSv12</code></td>
                </tr>
                <tr><td>465</td><td>SSL</td><td><code>SECURE_SSLv3</code></td></tr>
            </table>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <a href="{$module_url}/basic" class="btn">Basic Email</a>
            <a href="{$module_url}/html" class="btn">HTML Email</a>
            <a href="{$module_url}/attachments" class="btn">Attachments</a>
        </div>
    </div>
    
    <div class="card">
        <h2>Key Methods</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>setCredentials($user, $pass)</code></td><td>Set SMTP authentication</td></tr>
            <tr><td><code>from($email, $name)</code></td><td>Set sender address</td></tr>
            <tr><td><code>to($email, $name)</code></td><td>Add recipient</td></tr>
            <tr><td><code>cc($email, $name)</code></td><td>Add CC recipient</td></tr>
            <tr><td><code>bcc($email, $name)</code></td><td>Add BCC recipient</td></tr>
            <tr><td><code>setSubject($subject)</code></td><td>Set email subject</td></tr>
            <tr><td><code>text($body)</code></td><td>Set plain text body</td></tr>
            <tr><td><code>html($body)</code></td><td>Set HTML body</td></tr>
            <tr><td><code>attach($path, $name)</code></td><td>Add file attachment</td></tr>
            <tr><td><code>send()</code></td><td>Send the email</td></tr>
        </table>
    </div>