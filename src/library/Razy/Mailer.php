<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * SMTP mailer implementation for sending emails with support for
 * attachments, HTML/text bodies, CC/BCC, TLS/SSL encryption,
 * and asynchronous background sending via ThreadManager.
 *
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\MailerException;

/**
 * SMTP mailer for composing and sending emails.
 *
 * Provides a fluent API for building email messages with recipients,
 * attachments, and content, then sending them via an SMTP server
 * with configurable encryption protocols. Also supports async
 * sending through a background PHP process.
 *
 * @class Mailer
 */
class Mailer
{
    /** @var string CRLF line ending required by the SMTP protocol (RFC 2821) */
    public const CRLF = "\r\n";

    /** @var string No encryption */
    public const SECURE_NONE = '';

    /** @var string SSLv2 encryption protocol */
    public const SECURE_SSLv2 = 'sslv2';

    /** @var string SSLv2/v3 combined encryption protocol */
    public const SECURE_SSLv23 = 'sslv23';

    /** @var string SSLv3 encryption protocol */
    public const SECURE_SSLv3 = 'sslv3';

    /** @var string TLS encryption protocol (generic) */
    public const SECURE_TLS = 'tls';

    /** @var string TLS v1.0 encryption protocol */
    public const SECURE_TLSv10 = 'tls1.0';

    /** @var string TLS v1.1 encryption protocol */
    public const SECURE_TLSv11 = 'tls1.1';

    /** @var string TLS v1.2 encryption protocol (default) */
    public const SECURE_TLSv12 = 'tls1.2';

    /** @var array<string, string> File path => filename map of attachments */
    private array $attachments = [];

    /** @var array<string, string> BCC recipients: email => formatted address */
    private array $blindCarbonCopy = [];

    /** @var array<string, string> CC recipients: email => formatted address */
    private array $carbonCopy = [];

    /** @var string Character encoding for the email body */
    private string $charset = 'utf-8';

    /** @var array{0: string, 1: string} Sender address and display name */
    private array $from = ['', ''];

    /** @var array<string, string> Custom SMTP headers: header name => value */
    private array $headers = [];

    /** @var string HTML message body content */
    private string $htmlMessage = '';

    /** @var array<int, string> SMTP session log entries for debugging */
    private array $logs = [];

    /** @var string SMTP authentication password */
    private string $password = '';

    /** @var string Active encryption protocol identifier */
    private string $protocol = self::SECURE_TLSv12;

    /** @var array<string, string> Primary recipients: email => formatted address */
    private array $recipient = [];

    /** @var array<string, string> Reply-To addresses: email => formatted address */
    private array $replyTo = [];

    /** @var resource|null The active SMTP socket connection */
    private mixed $socket = null;

    /** @var string Email subject line */
    private string $subject = '';

    /** @var string Plain-text message body content */
    private string $textMessage = '';

    /** @var string SMTP authentication username */
    private string $username = '';

    /**
     * Mailer constructor.
     *
     * @param string $hostname
     * @param int $port
     * @param int $connectionTimeout
     * @param int $responseTimeout
     * @param string $origin
     */
    public function __construct(private readonly string $hostname, private int $port = 25, private readonly int $connectionTimeout = 30, private readonly int $responseTimeout = 5, private string $origin = '')
    {
        $this->origin = (empty($this->origin)) ? \gethostname() : $this->origin;
        $this->setHeader('X-Mailer', 'PHP/' . \phpversion());
        $this->setHeader('MIME-Version', '1.0');
    }

    /**
     * Send email using a payload file (used by background runner).
     *
     * @param string $payloadPath
     *
     * @return bool
     */
    public static function sendPayloadFile(string $payloadPath): bool
    {
        if (!\is_file($payloadPath)) {
            return false;
        }

        $payload = \json_decode(\file_get_contents($payloadPath), true);
        if (!\is_array($payload)) {
            return false;
        }

        $mailer = self::fromPayload($payload);
        return $mailer->send();
    }

    /**
     * Rebuild a Mailer instance from payload.
     *
     * @param array $payload
     *
     * @return Mailer
     */
    private static function fromPayload(array $payload): self
    {
        $mailer = new self(
            (string) ($payload['hostname'] ?? ''),
            (int) ($payload['port'] ?? 25),
            (int) ($payload['connection_timeout'] ?? 30),
            (int) ($payload['response_timeout'] ?? 5),
            (string) ($payload['origin'] ?? ''),
        );

        $mailer->protocol = (string) ($payload['protocol'] ?? self::SECURE_TLSv12);
        $mailer->username = (string) ($payload['username'] ?? '');
        $mailer->password = (string) ($payload['password'] ?? '');
        $mailer->from = (array) ($payload['from'] ?? []);
        $mailer->recipient = (array) ($payload['recipient'] ?? []);
        $mailer->carbonCopy = (array) ($payload['carbon_copy'] ?? []);
        $mailer->blindCarbonCopy = (array) ($payload['blind_carbon_copy'] ?? []);
        $mailer->replyTo = (array) ($payload['reply_to'] ?? []);
        $mailer->subject = (string) ($payload['subject'] ?? '');
        $mailer->textMessage = (string) ($payload['text_message'] ?? '');
        $mailer->htmlMessage = (string) ($payload['html_message'] ?? '');
        $mailer->charset = (string) ($payload['charset'] ?? 'utf-8');
        $mailer->attachments = (array) ($payload['attachments'] ?? []);
        $mailer->headers = (array) ($payload['headers'] ?? []);

        return $mailer;
    }

    /**
     * Set the header.
     *
     * @param string $key
     * @param string $value
     *
     * @return $this
     */
    public function setHeader(string $key, string $value = ''): self
    {
        $this->headers[$key] = \trim($value);

        return $this;
    }

    /**
     * Add the file as attachment.
     *
     * @param array|string $attachment
     * @param string $name
     *
     * @return $this
     */
    public function addAttachment(array|string $attachment, string $name = ''): self
    {
        if (\is_array($attachment)) {
            foreach ($attachment as $path => $filename) {
                $this->addAttachment($path, $filename);
            }
        } elseif (\is_string($attachment)) {
            if (\file_exists($attachment)) {
                $this->attachments[\realpath($attachment)] = (\trim($name)) ?: \pathinfo($attachment, PATHINFO_BASENAME);
            }
        }

        return $this;
    }

    /**
     * Add bcc recipient.
     *
     * @param array|string $email
     * @param string|null $name
     *
     * @return $this
     */
    public function bcc(array|string $email, string $name = null): self
    {
        if (\is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->bcc($address, $recipientName);
            }
        } elseif (\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->blindCarbonCopy[$email] = $this->formatAddress($email, \trim($name));
        }

        return $this;
    }

    /**
     * Add cc recipient.
     *
     * @param array|string $email
     * @param string|null $name
     *
     * @return $this
     */
    public function cc(array|string $email, string $name = null): self
    {
        if (\is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->cc($address, $recipientName);
            }
        } elseif (\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->carbonCopy[$email] = $this->formatAddress($email, \trim($name));
        }

        return $this;
    }

    /**
     * Set the sender.
     *
     * @param string $address
     * @param string $name
     *
     * @return $this
     */
    public function from(string $address, string $name = ''): self
    {
        $this->from = [$address, $name];

        return $this;
    }

    /**
     * Add reply-to recipient.
     *
     * @param array|string $email
     * @param string|null $name
     *
     * @return $this
     */
    public function replyTo(array|string $email, string $name = null): self
    {
        if (\is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->replyTo($address, $recipientName);
            }
        } elseif (\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->replyTo[$email] = $this->formatAddress($email, \trim($name));
        }

        return $this;
    }

    /**
     * Start send the email via SMTP.
     *
     * @return bool
     *
     * @throws MailerException
     */
    public function send(): bool
    {
        $this->openSmtpConnection();
        $cryptoMethod = $this->resolveCryptoMethod();
        $this->performSmtpHandshake($cryptoMethod);

        // Generate a unique MIME boundary for multipart message separation
        $boundary = \md5(\uniqid((string) \microtime(true), true));
        $body = $this->buildMimeBody($boundary);
        $headers = $this->buildMimeHeaders($boundary);

        return $this->transmitAndClose($headers, $body);
    }

    /**
     * Set the email charset.
     *
     * @param string $charset
     *
     * @return $this
     */
    public function setCharset(string $charset): self
    {
        $this->charset = $charset;

        return $this;
    }

    /**
     * Set the HTML message body.
     *
     * @param string $message
     *
     * @return $this
     */
    public function setHTML(string $message): self
    {
        $this->htmlMessage = $message;

        return $this;
    }

    /**
     * Set the secure protocol.
     *
     * @param string $protocol
     *
     * @return $this
     */
    public function setProtocol(string $protocol): self
    {
        // Determine the standard SMTP port based on the protocol prefix
        $protocol = \substr($this->protocol, 0, 3);
        if ($protocol == 'ssl') {
            $this->port = 465; // SSL uses port 465
        } elseif ($protocol == 'tls') {
            $this->port = 587; // TLS uses submission port 587
        } else {
            $this->port = 25;  // Plain SMTP uses port 25
        }

        $this->protocol = \strtolower($protocol);
        return $this;
    }

    /**
     * Set the subject.
     *
     * @param string $subject
     *
     * @return $this
     */
    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set the text message body.
     *
     * @param string $message
     *
     * @return $this
     */
    public function setText(string $message): self
    {
        $this->textMessage = $message;

        return $this;
    }

    /**
     * Add recipient.
     *
     * @param array|string $email
     * @param string|null $name
     *
     * @return $this
     */
    public function to(array|string $email, string $name = null): self
    {
        if (\is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->to($address, $recipientName);
            }
        } elseif (\filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->recipient[$email] = $this->formatAddress($email, \trim($name));
        }

        return $this;
    }

    /**
     * Set the username and password for SMTP authentication.
     *
     * @param string $username
     * @param string $password
     *
     * @return $this
     */
    public function useLogin(string $username, string $password): self
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    /**
     * Send the email via a background process (non-blocking).
     *
     * @param ThreadManager $threadManager
     * @param string|null $phpBinary
     *
     * @return Thread
     *
     * @throws MailerException
     */
    public function sendAsync(ThreadManager $threadManager, ?string $phpBinary = null): Thread
    {
        $payload = $this->buildPayload();
        $payloadPath = $this->writePayloadFile($payload);
        $runnerPath = $this->writeRunnerFile();

        $phpBinary ??= PHP_BINARY;

        return $threadManager->spawnProcessCommand($phpBinary, [$runnerPath, $payloadPath]);
    }

    /**
     * Format the email address with the name.
     *
     * @param string $address
     * @param string $name
     *
     * @return string
     */
    private function formatAddress(string $address, string $name = ''): string
    {
        return (!$name) ? $address : '"' . \addslashes($address) . '" <' . $name . '>';
    }

    /**
     * Open a TCP socket connection to the SMTP server.
     *
     * @throws MailerException
     */
    private function openSmtpConnection(): void
    {
        // Open a TCP socket connection to the SMTP server
        $this->socket = \stream_socket_client('tcp://' . $this->hostname . ':' . $this->port, $errno, $error, $this->connectionTimeout);
        if (!$this->socket) {
            throw new MailerException($errno . ' - ' . $error);
        }
    }

    /**
     * Resolve the PHP stream crypto method constant for the configured protocol.
     *
     * @return int The STREAM_CRYPTO_METHOD_* constant, or 0 for no encryption
     */
    private function resolveCryptoMethod(): int
    {
        // Map the configured protocol to the PHP stream crypto constant
        $map = [
            self::SECURE_SSLv2 => STREAM_CRYPTO_METHOD_SSLv2_CLIENT,
            self::SECURE_SSLv23 => STREAM_CRYPTO_METHOD_SSLv23_CLIENT,
            self::SECURE_SSLv3 => STREAM_CRYPTO_METHOD_SSLv3_CLIENT,
            self::SECURE_TLS => STREAM_CRYPTO_METHOD_TLS_CLIENT,
            self::SECURE_TLSv10 => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
            self::SECURE_TLSv11 => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
            self::SECURE_TLSv12 => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ];

        return $map[$this->protocol] ?? 0;
    }

    /**
     * Perform the SMTP handshake: EHLO, STARTTLS, AUTH LOGIN, MAIL FROM, and RCPT TO.
     *
     * @param int $cryptoMethod The stream crypto method constant (0 for no encryption)
     */
    private function performSmtpHandshake(int $cryptoMethod): void
    {
        // Begin SMTP handshake: read server greeting and send EHLO
        $this->logs[] = 'CONNECTION: ' . $this->getResponse();
        $this->logs[] = $this->push('EHLO ' . $this->origin);

        if ($cryptoMethod) {
            // Upgrade connection to encrypted channel via STARTTLS
            $this->logs[] = $this->push('STARTTLS');
            \stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
            // Re-send EHLO after TLS handshake as required by RFC 3207
            $this->logs[] = $this->push('EHLO ' . $this->origin);
        }

        // Authenticate with base64-encoded credentials (AUTH LOGIN)
        $this->logs[] = $this->push('AUTH LOGIN');
        $this->logs[] = $this->push(\base64_encode($this->username));
        $this->logs[] = $this->push(\base64_encode($this->password));
        $this->logs[] = $this->push('MAIL FROM: <' . $this->from[0] . '>');

        // Notify the server of all recipients (To, CC, and BCC combined)
        $recipients = \array_merge($this->recipient, $this->carbonCopy, $this->blindCarbonCopy);
        foreach ($recipients as $address => $fullAddress) {
            $this->logs[] = $this->push('RCPT TO: <' . $address . '>');
        }
    }

    /**
     * Build the MIME headers string for the email.
     *
     * Sets Date, Subject, From, Return-Path, To, Reply-To, CC, BCC, and
     * Content-Type headers, then formats them into a single CRLF-delimited string.
     *
     * @param string $boundary The MIME boundary identifier
     *
     * @return string The formatted header string
     */
    private function buildMimeHeaders(string $boundary): string
    {
        $this->setHeader('Date', \date('r'));
        $this->setHeader('Subject', $this->subject);
        $this->setHeader('From', $this->formatAddress($this->from[0], $this->from[1]));
        $this->setHeader('Return-Path', $this->formatAddress($this->from[0], $this->from[1]));
        $this->setHeader('To', \implode(', ', $this->recipient));

        if (!empty($this->replyTo)) {
            $this->setHeader('Reply-To', \implode(', ', $this->replyTo));
        }

        if (!empty($this->cc)) {
            $this->setHeader('Cc', \implode(', ', $this->carbonCopy));
        }

        if (!empty($this->bcc)) {
            $this->setHeader('Bcc', \implode(', ', $this->blindCarbonCopy));
        }

        if (!empty($this->attachments)) {
            $this->headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundary . '"';
        } else {
            $this->headers['Content-Type'] = 'multipart/alternative; boundary="alt-' . $boundary . '"';
        }

        // Build the final header string from all accumulated headers
        $headers = '';
        foreach ($this->headers as $header => $value) {
            $headers .= $header . ': ' . $value . self::CRLF;
        }

        return $headers;
    }

    /**
     * Build the MIME message body with text/HTML parts and file attachments.
     *
     * @param string $boundary The MIME boundary identifier
     *
     * @return string The complete MIME body content
     */
    private function buildMimeBody(string $boundary): string
    {
        $message = '';

        if (!empty($this->attachments)) {
            // With attachments: use mixed boundary for parts, alternative for body variants
            $message .= '--' . $boundary . self::CRLF;
            $message .= 'Content-Type: multipart/alternative; boundary="alt-' . $boundary . '"' . self::CRLF . self::CRLF;
        }

        // Encode and attach the plain-text body part (if provided)
        if (!empty($this->textMessage)) {
            $message .= '--alt-' . $boundary . self::CRLF;
            $message .= 'Content-Type: text/plain; charset=' . $this->charset . self::CRLF;
            $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
            $message .= \chunk_split(\base64_encode($this->textMessage)) . self::CRLF;
        }

        // Encode and attach the HTML body part (if provided)
        if (!empty($this->htmlMessage)) {
            $message .= '--alt-' . $boundary . self::CRLF;
            $message .= 'Content-Type: text/html; charset=' . $this->charset . self::CRLF;
            $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
            $message .= \chunk_split(\base64_encode($this->htmlMessage)) . self::CRLF;
        }

        // Close the alternative boundary section
        $message .= '--alt-' . $boundary . '--' . self::CRLF . self::CRLF;

        // Encode each file attachment as a base64 MIME part
        if (!empty($this->attachments)) {
            foreach ($this->attachments as $attachment => $filename) {
                $contents = \file_get_contents($attachment);
                $type = \mime_content_type($attachment);

                if (!$type) {
                    $type = 'application/octet-stream';
                }

                $message .= '--' . $boundary . self::CRLF;
                $message .= 'Content-Type: ' . $type . '; name="' . $filename . '"' . self::CRLF;
                $message .= 'Content-Disposition: attachment; filename="' . $filename . '"' . self::CRLF;
                $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
                $message .= \chunk_split(\base64_encode($contents)) . self::CRLF;
            }

            $message .= '--' . $boundary . '--';
        }

        return $message;
    }

    /**
     * Transmit the email data over SMTP and close the connection.
     *
     * Sends the DATA command, transmits headers and body ending with ".",
     * sends QUIT, and closes the socket.
     *
     * @param string $headers The formatted MIME headers
     * @param string $body The MIME message body
     *
     * @return bool True if the server accepted the message (SMTP 250 response)
     */
    private function transmitAndClose(string $headers, string $body): bool
    {
        // Send the DATA command, then transmit headers + body, ending with "."
        $this->logs[] = $this->push('DATA');
        $this->logs[] = $body;
        $this->logs[] = $headers;
        $this->logs[] = $response = $this->push($headers . self::CRLF . $body . self::CRLF . '.');
        $this->logs[] = $this->push('QUIT');
        \fclose($this->socket);

        // SMTP 250 response indicates the message was accepted for delivery
        return \str_starts_with($response, '250');
    }

    /**
     * Retrieve the response.
     *
     * @return string
     */
    private function getResponse(): string
    {
        $response = '';
        \stream_set_timeout($this->socket, $this->responseTimeout);
        while (($line = \fread($this->socket, 515)) !== false) {
            $response .= \trim($line) . "\n";
            // SMTP multi-line responses use "-" as the 4th char; a space means final line
            if (\substr($line, 3, 1) == ' ') {
                break;
            }
        }

        return \trim($response);
    }

    /**
     * Push the command to SMTP server.
     *
     * @param $command
     *
     * @return string
     */
    private function push($command): string
    {
        if ($this->socket) {
            \fwrite($this->socket, $command . self::CRLF);

            return $this->getResponse();
        }

        return '';
    }

    /**
     * Build payload for background sending.
     *
     * @return array
     */
    private function buildPayload(): array
    {
        return [
            'hostname' => $this->hostname,
            'port' => $this->port,
            'connection_timeout' => $this->connectionTimeout,
            'response_timeout' => $this->responseTimeout,
            'origin' => $this->origin,
            'protocol' => $this->protocol,
            'username' => $this->username,
            'password' => $this->password,
            'from' => $this->from,
            'recipient' => $this->recipient,
            'carbon_copy' => $this->carbonCopy,
            'blind_carbon_copy' => $this->blindCarbonCopy,
            'reply_to' => $this->replyTo,
            'subject' => $this->subject,
            'text_message' => $this->textMessage,
            'html_message' => $this->htmlMessage,
            'charset' => $this->charset,
            'attachments' => $this->attachments,
            'headers' => $this->headers,
        ];
    }

    /**
     * Write payload to a temp file.
     *
     * @param array $payload
     *
     * @return string
     *
     * @throws MailerException
     */
    private function writePayloadFile(array $payload): string
    {
        $payloadPath = \tempnam(\sys_get_temp_dir(), 'razy-mailer-');
        if (!$payloadPath) {
            throw new MailerException('Unable to create payload file for Mailer.');
        }

        \file_put_contents($payloadPath, \json_encode($payload));
        return $payloadPath;
    }

    /**
     * Write the background runner file.
     *
     * @return string
     *
     * @throws MailerException
     */
    private function writeRunnerFile(): string
    {
        $runnerPath = \tempnam(\sys_get_temp_dir(), 'razy-mailer-runner-');
        if (!$runnerPath) {
            throw new MailerException('Unable to create runner file for Mailer.');
        }

        $mailerPath = \str_replace('\\', '/', __FILE__);
        $runner = "<?php\n" .
            "require '" . $mailerPath . "';\n" .
            "use Razy\\Mailer;\n" .
            "\$payloadPath = \$argv[1] ?? '';\n" .
            "\$ok = Mailer::sendPayloadFile(\$payloadPath);\n" .
            "if (is_file(\$payloadPath)) { @unlink(\$payloadPath); }\n" .
            "@unlink(__FILE__);\n" .
            "exit(\$ok ? 0 : 1);\n";

        \file_put_contents($runnerPath, $runner);
        return $runnerPath;
    }
}
