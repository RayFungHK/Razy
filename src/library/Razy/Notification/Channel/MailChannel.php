<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Notification\Channel;

use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;

/**
 * Mail notification channel.
 *
 * Sends notifications via email by calling `toMail()` on the notification.
 * The notifiable object must expose a `getEmail(): string` method (or a
 * public `$email` property) to determine the recipient address.
 *
 * The `toMail()` method on the notification should return an associative
 * array with the following keys (all optional except `body`):
 * ```
 * [
 *     'subject' => 'Welcome!',
 *     'body'    => 'Hello, welcome to our platform.',
 *     'from'    => 'noreply@example.com',
 *     'cc'      => ['admin@example.com'],
 *     'bcc'     => [],
 *     'html'    => true,     // Whether body is HTML
 * ]
 * ```
 *
 * @package Razy\Notification\Channel
 */
class MailChannel implements NotificationChannelInterface
{
    /**
     * @var callable The mail-sending callable
     */
    private $mailerFn;

    /**
     * @var list<array{to: string, data: array}> Sent messages (for testing/debugging)
     */
    private array $sent = [];

    /**
     * @var bool Whether to record sent messages
     */
    private bool $recording;

    /**
     * Create a new MailChannel.
     *
     * The $mailerFn callable receives (string $to, array $mailData) and is
     * responsible for actually sending the email. This decouples the channel
     * from any specific mailer implementation.
     *
     * @param callable $mailerFn  A callable that sends email: fn(string $to, array $data): void
     * @param bool     $recording Whether to record sent messages for inspection
     */
    public function __construct(callable $mailerFn, bool $recording = false)
    {
        $this->mailerFn  = $mailerFn;
        $this->recording = $recording;
    }

    /**
     * @inheritDoc
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $data = $notification->getData('mail', $notifiable);

        if ($data === null) {
            return;
        }

        $to = $this->resolveRecipient($notifiable);

        if ($to === null) {
            throw new \RuntimeException(
                'Notifiable entity must provide getEmail() method or public $email property.'
            );
        }

        ($this->mailerFn)($to, $data);

        if ($this->recording) {
            $this->sent[] = ['to' => $to, 'data' => $data];
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'mail';
    }

    /**
     * Get all recorded sent messages.
     *
     * @return list<array{to: string, data: array}>
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    /**
     * Clear recorded sent messages.
     *
     * @return $this
     */
    public function clearSent(): static
    {
        $this->sent = [];

        return $this;
    }

    /**
     * Resolve the recipient email address from the notifiable entity.
     */
    private function resolveRecipient(object $notifiable): ?string
    {
        // Method-based resolution
        if (method_exists($notifiable, 'getEmail')) {
            return $notifiable->getEmail();
        }

        // Property-based resolution
        if (property_exists($notifiable, 'email')) {
            return $notifiable->email;
        }

        return null;
    }
}
