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
 * Contract for notification delivery channels.
 *
 * Each channel handles delivery via a specific transport (e.g., email,
 * database, SMS). The NotificationManager dispatches notifications
 * to channels returned by the notification's via() method.
 *
 * @package Razy\Notification
 */
interface NotificationChannelInterface
{
    /**
     * Send the given notification.
     *
     * @param object $notifiable The entity receiving the notification
     * @param Notification $notification The notification instance
     */
    public function send(object $notifiable, Notification $notification): void;

    /**
     * Get the channel identifier.
     *
     * @return string A unique channel name (e.g. 'mail', 'database', 'sms')
     */
    public function getName(): string;
}
