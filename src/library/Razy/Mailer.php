<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

class Mailer
{
    const CRLF = "\r\n";
    const SECURE_NONE = '';
    const SECURE_SSLv2 = 'sslv2';
    const SECURE_SSLv23 = 'sslv23';
    const SECURE_SSLv3 = 'sslv3';
    const SECURE_TLS = 'tls';
    const SECURE_TLSv10 = 'tls1.0';
    const SECURE_TLSv11 = 'tls1.1';
    const SECURE_TLSv12 = 'tls1.2';

    private array $attachments = [];
    private array $blindCarbonCopy = [];
    private array $carbonCopy = [];
    private string $charset = 'utf-8';
    private array $from = [];
    private array $headers = [];
    private string $htmlMessage = '';
    private string $password = '';
    private string $protocol = self::SECURE_TLSv12;
    private array $recipient = [];
    private array $replyTo = [];
    private mixed $socket = null;
    private string $subject = '';
    private string $textMessage = '';
    private string $username = '';

    /**
     * Mailer constructor
     *
     * @param string $hostname
     * @param int $port
     * @param int $connectionTimeout
     * @param int $responseTimeout
     * @param string $origin
     */
    public function __construct(private readonly string $hostname, private int $port = 25, private readonly int $connectionTimeout = 30, private readonly int $responseTimeout = 5, private string $origin = '')
    {
        $this->origin = (empty($his->origin)) ? gethostname() : $this->origin;
        $this->setHeader('X-Mailer', 'PHP/' . phpversion());
        $this->setHeader('MIME-Version', '1.0');
    }

    /**
     * Set the header.
     *
     * @param string $key
     * @param string $value
     *
     * @return $this
     */
    public function setHeader(string $key, string $value = ''): Mailer
    {
        $this->headers[$key] = trim($value);

        return $this;
    }

    /**
     * Add the file as attachment.
     *
     * @param array|string $attachment
     * @param string $name
     * @return $this
     */
    public function addAttachment(array|string $attachment, string $name = ''): Mailer
    {
        if (is_array($attachment)) {
            foreach ($attachment as $path => $filename) {
                $this->addAttachment($path, $filename);
            }
        } elseif (is_string($attachment)) {
            if (file_exists($attachment)) {
                $this->attachments[realpath($attachment)] = (trim($name)) ?: pathinfo($attachment, PATHINFO_BASENAME);
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
    public function bcc(array|string $email, string $name = null): Mailer
    {
        if (is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->bcc($address, $recipientName);
            }
        } elseif (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->blindCarbonCopy[$email] = $this->formatAddress($email, trim($name));
        }

        return $this;
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
        return (!$name) ? $address : '"' . addslashes($address) . '" <' . $name . '>';
    }

    /**
     * Add cc recipient.
     *
     * @param array|string $email
     * @param string|null $name
     *
     * @return $this
     */
    public function cc(array|string $email, string $name = null): Mailer
    {
        if (is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->cc($address, $recipientName);
            }
        } elseif (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->carbonCopy[$email] = $this->formatAddress($email, trim($name));
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
    public function from(string $address, string $name = ''): Mailer
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
    public function replyTo(array|string $email, string $name = null): Mailer
    {
        if (is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->replyTo($address, $recipientName);
            }
        } elseif (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->replyTo[$email] = $this->formatAddress($email, trim($name));
        }

        return $this;
    }

    /**
     * Start send the email via SMTP.
     *
     * @return bool
     * @throws Error
     */
    public function send(): bool
    {
        $message = null;
        $this->socket = stream_socket_client('tcp://' . $this->hostname . ':' . $this->port, $errno, $error, $this->connectionTimeout);
        if (!$this->socket) {
            throw new Error($errno . ' - ' . $error);
        }
        $cryptoMethod = '';
        switch ($this->protocol) {
            case self::SECURE_SSLv2:
                $cryptoMethod = STREAM_CRYPTO_METHOD_SSLv2_CLIENT;
                break;
            case self::SECURE_SSLv23:
                $cryptoMethod = STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
                break;
            case self::SECURE_SSLv3:
                $cryptoMethod = STREAM_CRYPTO_METHOD_SSLv3_CLIENT;
                break;
            case self::SECURE_TLS:
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                break;
            case self::SECURE_TLSv10:
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
                break;
            case self::SECURE_TLSv11:
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
                break;
            case self::SECURE_TLSv12:
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                break;
        }

        $this->logs[] = 'CONNECTION: ' . $this->getResponse();
        $this->logs[] = $this->push('EHLO ' . $this->origin);

        if ($cryptoMethod) {
            $this->logs[] = $this->push('STARTTLS');
            stream_socket_enable_crypto($this->socket, true, $cryptoMethod);
            $this->logs[] = $this->push('EHLO ' . $this->origin);
        }

        $this->logs[] = $this->push('AUTH LOGIN');
        $this->logs[] = $this->push(base64_encode($this->username));
        $this->logs[] = $this->push(base64_encode($this->password));
        $this->logs[] = $this->push('MAIL FROM: <' . $this->from[0] . '>');

        $recipients = array_merge($this->recipient, $this->carbonCopy, $this->blindCarbonCopy);
        foreach ($recipients as $address => $fullAddress) {
            $this->logs[] = $this->push('RCPT TO: <' . $address . '>');
        }

        $this->setHeader('Date', date('r'));
        $this->setHeader('Subject', $this->subject);
        $this->setHeader('From', $this->formatAddress($this->from[0], $this->from[1]));
        $this->setHeader('Return-Path', $this->formatAddress($this->from[0], $this->from[1]));
        $this->setHeader('To', implode(', ', $this->recipient));

        if (!empty($this->replyTo)) {
            $this->setHeader('Reply-To', implode(', ', $this->replyTo));
        }

        if (!empty($this->cc)) {
            $this->setHeader('Cc', implode(', ', $this->carbonCopy));
        }

        if (!empty($this->bcc)) {
            $this->setHeader('Bcc', implode(', ', $this->blindCarbonCopy));
        }

        $boundary = md5(uniqid(microtime(true), true));
        $this->setHeader('Content-Type', 'multipart/mixed; boundary="' . $boundary . '"');

        if (!empty($this->attachments)) {
            $this->headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundary . '"';
            $message .= '--' . $boundary . self::CRLF;
            $message .= 'Content-Type: multipart/alternative; boundary="alt-' . $boundary . '"' . self::CRLF . self::CRLF;
        } else {
            $this->headers['Content-Type'] = 'multipart/alternative; boundary="alt-' . $boundary . '"';
        }

        if (!empty($this->textMessage)) {
            $message .= '--alt-' . $boundary . self::CRLF;
            $message .= 'Content-Type: text/plain; charset=' . $this->charset . self::CRLF;
            $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
            $message .= chunk_split(base64_encode($this->textMessage)) . self::CRLF;
        }

        if (!empty($this->htmlMessage)) {
            $message .= '--alt-' . $boundary . self::CRLF;
            $message .= 'Content-Type: text/html; charset=' . $this->charset . self::CRLF;
            $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
            $message .= chunk_split(base64_encode($this->htmlMessage)) . self::CRLF;
        }

        $message .= '--alt-' . $boundary . '--' . self::CRLF . self::CRLF;
        if (!empty($this->attachments)) {
            foreach ($this->attachments as $attachment => $filename) {
                $contents = file_get_contents($attachment);
                $type = mime_content_type($attachment);

                if (!$type) {
                    $type = 'application/octet-stream';
                }

                $message .= '--' . $boundary . self::CRLF;
                $message .= 'Content-Type: ' . $type . '; name="' . $filename . '"' . self::CRLF;
                $message .= 'Content-Disposition: attachment; filename="' . $filename . '"' . self::CRLF;
                $message .= 'Content-Transfer-Encoding: base64' . self::CRLF . self::CRLF;
                $message .= chunk_split(base64_encode($contents)) . self::CRLF;
            }

            $message .= '--' . $boundary . '--';
        }

        $headers = '';
        foreach ($this->headers as $header => $value) {
            $headers .= $header . ': ' . $value . self::CRLF;
        }

        $this->logs[] = $this->push('DATA');
        $this->logs[] = $message;
        $this->logs[] = $headers;
        $this->logs[] = $response = $this->push($headers . self::CRLF . $message . self::CRLF . '.');
        $this->logs[] = $this->push('QUIT');
        fclose($this->socket);

        return str_starts_with($response, '250');
    }

    /**
     * Retrieve the response.
     *
     * @return string
     */
    private function getResponse(): string
    {
        $response = '';
        stream_set_timeout($this->socket, $this->responseTimeout);
        while (($line = fread($this->socket, 515)) !== false) {
            $response .= trim($line) . "\n";
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }

        return trim($response);
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
            fwrite($this->socket, $command . self::CRLF);

            return $this->getResponse();
        }

        return '';
    }

    /**
     * Set the email charset.
     *
     * @param string $charset
     *
     * @return $this
     */
    public function setCharset(string $charset): Mailer
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
    public function setHTML(string $message): Mailer
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
    public function setProtocol(string $protocol): Mailer
    {
        $protocol = substr($this->protocol, 0, 3);
        if ($protocol == 'ssl') {
            $this->port = 465;
        } elseif ($protocol == 'tls') {
            $this->port = 587;
        } else {
            $this->port = 25;
        }

        $this->protocol = strtolower($protocol);
        return $this;
    }

    /**
     * Set the subject.
     *
     * @param string $subject
     *
     * @return $this
     */
    public function setSubject(string $subject): Mailer
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Set the text message body
     *
     * @param string $message
     *
     * @return $this
     */
    public function setText(string $message): Mailer
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
    public function to(array|string $email, string $name = null): Mailer
    {
        if (is_array($email)) {
            foreach ($email as $address => $recipientName) {
                $this->to($address, $recipientName);
            }
        } elseif (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->recipient[$email] = $this->formatAddress($email, trim($name));
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
    public function useLogin(string $username, string $password): Mailer
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }
}