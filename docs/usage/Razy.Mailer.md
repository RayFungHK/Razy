# Razy\Mailer

## Summary
- SMTP mail sender with TLS support and attachments.

## Construction
- `new Mailer($hostname, $port, $connectionTimeout, $responseTimeout, $origin)`.

## Key methods
- `from()`, `to()`, `cc()`, `bcc()`, `replyTo()`.
- `setSubject()`, `setText()`, `setHTML()`.
- `addAttachment()`.
- `useLogin()`, `setProtocol()`.
- `send()`.
- `sendAsync()`.

## Usage notes
- Uses `stream_socket_client()` and optional STARTTLS.
- Builds multipart/alternative with attachments.
- `sendAsync()` uses the ThreadManager process backend and returns immediately.

## Sample (non-blocking SMTP)
```php
$mailer = (new Mailer('smtp.example.com', 587))
	->useLogin('user@example.com', 'secret')
	->setProtocol(Mailer::SECURE_TLSv12)
	->from('no-reply@example.com', 'Razy')
	->to('user@example.com')
	->setSubject('Hello')
	->setText('Hello from async SMTP');

$thread = $agent->thread();
$job = $mailer->sendAsync($thread);

// Optional: wait for result later
$result = $thread->await($job->getId(), 2000);
```
