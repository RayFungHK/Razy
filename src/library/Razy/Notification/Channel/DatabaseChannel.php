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

namespace Razy\Notification\Channel;

use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;

/**
 * Database notification channel.
 *
 * Stores notifications in an in-memory array (or any persistence backend
 * via the `$storeFn` callable). The notification's `toDatabase()` or
 * `toArray()` method is used to obtain the data payload.
 *
 * The notifiable entity should expose a unique identifier via `getId()`
 * method or a public `$id` property.
 *
 * Usage:
 * ```php
 * // Simple in-memory storage
 * $channel = new DatabaseChannel();
 *
 * // With custom persistence
 * $channel = new DatabaseChannel(function (array $record) use ($pdo) {
 *     $stmt = $pdo->prepare('INSERT INTO notifications ...');
 *     $stmt->execute($record);
 * });
 * ```
 */
class DatabaseChannel implements NotificationChannelInterface
{
    /**
     * @var callable|null Custom store function
     */
    private $storeFn;

    /**
     * @var list<array{id: string, type: string, notifiable_type: string, notifiable_id: mixed, data: mixed, created_at: string}> Stored records
     */
    private array $records = [];

    /**
     * Create a new DatabaseChannel.
     *
     * @param callable|null $storeFn Optional callable that receives the notification
     *                               record array for custom persistence
     */
    public function __construct(?callable $storeFn = null)
    {
        $this->storeFn = $storeFn;
    }

    /**
     * @inheritDoc
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $data = $notification->getData('database', $notifiable);

        if (empty($data)) {
            $data = $notification->getData('array', $notifiable);
        }

        $record = [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'notifiable_type' => \get_class($notifiable),
            'notifiable_id' => $this->resolveNotifiableId($notifiable),
            'data' => $data,
            'created_at' => \date('Y-m-d H:i:s'),
        ];

        $this->records[] = $record;

        if ($this->storeFn !== null) {
            ($this->storeFn)($record);
        }
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'database';
    }

    /**
     * Get all stored notification records.
     *
     * @return list<array{id: string, type: string, notifiable_type: string, notifiable_id: mixed, data: mixed, created_at: string}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Get records for a specific notifiable entity.
     *
     * @param object $notifiable The entity to filter records for
     *
     * @return list<array>
     */
    public function getRecordsFor(object $notifiable): array
    {
        $id = $this->resolveNotifiableId($notifiable);
        $type = \get_class($notifiable);

        return \array_values(\array_filter(
            $this->records,
            fn (array $record) => $record['notifiable_type'] === $type
                && $record['notifiable_id'] === $id,
        ));
    }

    /**
     * Clear all stored records.
     *
     * @return $this
     */
    public function clearRecords(): static
    {
        $this->records = [];

        return $this;
    }

    /**
     * Get the total number of stored records.
     */
    public function count(): int
    {
        return \count($this->records);
    }

    /**
     * Resolve the notifiable entity's unique identifier.
     */
    private function resolveNotifiableId(object $notifiable): mixed
    {
        if (\method_exists($notifiable, 'getId')) {
            return $notifiable->getId();
        }

        if (\property_exists($notifiable, 'id')) {
            return $notifiable->id;
        }

        return \spl_object_id($notifiable);
    }
}
