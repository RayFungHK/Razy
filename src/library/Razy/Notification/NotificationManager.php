<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Notification;

use RuntimeException;
use Throwable;

/**
 * Notification dispatcher — sends notifications to entities.
 *
 * The NotificationManager maps channel names to NotificationChannelInterface
 * implementations and dispatches notifications by calling `via()` on each
 * notification to determine the channels, then delegating to each channel.
 *
 * Usage:
 * ```php
 * $manager = new NotificationManager();
 * $manager->registerChannel(new MailChannel($mailer));
 * $manager->registerChannel(new DatabaseChannel($db));
 *
 * $user = new User(email: 'user@example.com');
 * $notification = new WelcomeNotification('Alice');
 *
 * $manager->send($user, $notification);
 * ```
 */
class NotificationManager
{
    /**
     * @var array<string, NotificationChannelInterface> Registered channels
     */
    private array $channels = [];

    /**
     * @var list<callable(object, Notification, string): void> Before-send hooks
     */
    private array $beforeHooks = [];

    /**
     * @var list<callable(object, Notification, string): void> After-send hooks
     */
    private array $afterHooks = [];

    /**
     * @var list<callable(object, Notification, string, Throwable): void> Error hooks
     */
    private array $errorHooks = [];

    /**
     * @var list<array{notifiable: string, notification: string, channel: string, id: string}> Sent log
     */
    private array $sentLog = [];

    /**
     * @var bool Whether to log sent notifications
     */
    private bool $logging;

    /**
     * Create a new NotificationManager.
     *
     * @param bool $logging Whether to keep a log of sent notifications
     */
    public function __construct(bool $logging = false)
    {
        $this->logging = $logging;
    }

    /**
     * Register a notification channel.
     *
     * @return $this
     */
    public function registerChannel(NotificationChannelInterface $channel): static
    {
        $this->channels[$channel->getName()] = $channel;

        return $this;
    }

    /**
     * Get a registered channel by name.
     */
    public function getChannel(string $name): ?NotificationChannelInterface
    {
        return $this->channels[$name] ?? null;
    }

    /**
     * Get all registered channel names.
     *
     * @return list<string>
     */
    public function getChannelNames(): array
    {
        return \array_keys($this->channels);
    }

    /**
     * Check whether a channel is registered.
     */
    public function hasChannel(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    /**
     * Send a notification to a notifiable entity.
     *
     * @param object $notifiable The entity receiving the notification
     * @param Notification $notification The notification to send
     *
     * @throws RuntimeException If a required channel is not registered
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $channels = $notification->via($notifiable);

        foreach ($channels as $channelName) {
            $channel = $this->channels[$channelName] ?? null;

            if ($channel === null) {
                throw new RuntimeException(
                    "Notification channel '{$channelName}' is not registered.",
                );
            }

            try {
                // Before hooks
                foreach ($this->beforeHooks as $hook) {
                    $hook($notifiable, $notification, $channelName);
                }

                $channel->send($notifiable, $notification);

                // Log if enabled
                if ($this->logging) {
                    $this->sentLog[] = [
                        'notifiable' => \get_class($notifiable),
                        'notification' => $notification->getType(),
                        'channel' => $channelName,
                        'id' => $notification->getId(),
                    ];
                }

                // After hooks
                foreach ($this->afterHooks as $hook) {
                    $hook($notifiable, $notification, $channelName);
                }
            } catch (Throwable $e) {
                // Error hooks
                foreach ($this->errorHooks as $hook) {
                    $hook($notifiable, $notification, $channelName, $e);
                }

                // Re-throw unless error hooks consumed it
                if (empty($this->errorHooks)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Send a notification to multiple notifiable entities.
     *
     * @param iterable<object> $notifiables
     * @param Notification $notification
     */
    public function sendToMany(iterable $notifiables, Notification $notification): void
    {
        foreach ($notifiables as $notifiable) {
            $this->send($notifiable, $notification);
        }
    }

    /**
     * Register a before-send hook.
     *
     * @param callable(object, Notification, string): void $callback
     *
     * @return $this
     */
    public function beforeSend(callable $callback): static
    {
        $this->beforeHooks[] = $callback;

        return $this;
    }

    /**
     * Register an after-send hook.
     *
     * @param callable(object, Notification, string): void $callback
     *
     * @return $this
     */
    public function afterSend(callable $callback): static
    {
        $this->afterHooks[] = $callback;

        return $this;
    }

    /**
     * Register an error hook (called on channel send failure).
     *
     * When error hooks are present, exceptions from channels are caught
     * and passed to the hooks rather than re-thrown.
     *
     * @param callable(object, Notification, string, Throwable): void $callback
     *
     * @return $this
     */
    public function onError(callable $callback): static
    {
        $this->errorHooks[] = $callback;

        return $this;
    }

    /**
     * Get the sent notification log.
     *
     * @return list<array{notifiable: string, notification: string, channel: string, id: string}>
     */
    public function getSentLog(): array
    {
        return $this->sentLog;
    }

    /**
     * Clear the sent notification log.
     *
     * @return $this
     */
    public function clearSentLog(): static
    {
        $this->sentLog = [];

        return $this;
    }
}
