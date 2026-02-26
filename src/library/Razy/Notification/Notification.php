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
 *
 * @license MIT
 */

namespace Razy\Notification;

/**
 * Abstract Notification — base class for all notifications.
 *
 * Subclass and define `via()` to select delivery channels and the
 * corresponding `toMail()`, `toDatabase()`, etc. methods for each
 * channel's payload.
 *
 * Usage:
 * ```php
 * class WelcomeNotification extends Notification
 * {
 *     public function __construct(
 *         private readonly string $userName,
 *     ) {}
 *
 *     public function via(object $notifiable): array
 *     {
 *         return ['mail', 'database'];
 *     }
 *
 *     public function toMail(object $notifiable): array
 *     {
 *         return [
 *             'subject' => 'Welcome!',
 *             'body'    => "Hello {$this->userName}, welcome aboard!",
 *         ];
 *     }
 *
 *     public function toDatabase(object $notifiable): array
 *     {
 *         return [
 *             'type'    => 'welcome',
 *             'message' => "User {$this->userName} registered",
 *         ];
 *     }
 * }
 * ```
 *
 * @package Razy\Notification
 */
abstract class Notification
{
    /**
     * Unique notification ID.
     */
    private ?string $id = null;

    /**
     * Get the delivery channels for this notification.
     *
     * @param object $notifiable The entity receiving the notification
     *
     * @return list<string> Channel names (e.g. ['mail', 'database'])
     */
    abstract public function via(object $notifiable): array;

    /**
     * Get or set the notification ID.
     */
    public function getId(): string
    {
        if ($this->id === null) {
            $this->id = \bin2hex(\random_bytes(16));
        }

        return $this->id;
    }

    /**
     * Set a custom notification ID.
     *
     * @return $this
     */
    public function setId(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get the notification type (defaults to class name).
     */
    public function getType(): string
    {
        return static::class;
    }

    /**
     * Get the data for a specific channel.
     *
     * Looks for a `to{Channel}()` method (e.g., `toMail()`, `toDatabase()`).
     * Falls back to `toArray()` if defined, otherwise returns an empty array.
     *
     * @param string $channel The channel name
     * @param object $notifiable The entity receiving the notification
     *
     * @return array<string, mixed>
     */
    public function getData(string $channel, object $notifiable): array
    {
        $method = 'to' . \ucfirst($channel);

        if (\method_exists($this, $method)) {
            return $this->{$method}($notifiable);
        }

        if (\method_exists($this, 'toArray')) {
            return $this->toArray($notifiable);
        }

        return [];
    }
}
