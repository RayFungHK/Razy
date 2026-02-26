<?php

/**
 * Comprehensive tests for #20: Notification System.
 *
 * Covers Notification, NotificationManager, MailChannel, DatabaseChannel,
 * hooks, logging, getData dispatch, and full integration scenarios.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Notification\Channel\DatabaseChannel;
use Razy\Notification\Channel\MailChannel;
use Razy\Notification\Notification;
use Razy\Notification\NotificationChannelInterface;
use Razy\Notification\NotificationManager;
use RuntimeException;
use Throwable;

// ═══════════════════════════════════════════════════════
//  Test Doubles — prefixed NTTest_ to avoid collisions
// ═══════════════════════════════════════════════════════

/** @internal */
class NTTest_WelcomeNotification extends Notification
{
    public function __construct(private readonly string $name)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): array
    {
        return [
            'subject' => "Welcome, {$this->name}!",
            'body' => "Hello {$this->name}, welcome aboard.",
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'welcome', 'user' => $this->name];
    }
}

/** @internal */
class NTTest_MailOnlyNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): array
    {
        return ['subject' => 'Mail only', 'body' => 'Mail body'];
    }
}

/** @internal */
class NTTest_DbOnlyNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['event' => 'db_only'];
    }
}

/** @internal */
class NTTest_ArrayFallbackNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toArray(object $notifiable): array
    {
        return ['fallback' => true];
    }
}

/** @internal */
class NTTest_EmptyNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
    // No toMail, no toArray
}

/** @internal */
class NTTest_UnregisteredChannelNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }
}

/** @internal */
class NTTest_ThrowingNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): array
    {
        return ['subject' => 'boom', 'body' => 'error test'];
    }
}

/** @internal */
class NTTest_UserWithEmail
{
    public function __construct(
        private readonly int $id,
        private readonly string $emailAddr,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->emailAddr;
    }
}

/** @internal */
class NTTest_UserWithProperty
{
    public int $id;

    public string $email;

    public function __construct(int $id, string $email)
    {
        $this->id = $id;
        $this->email = $email;
    }
}

/** @internal */
class NTTest_UserNoEmail
{
    public function __construct(public readonly int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}

/** @internal */
class NTTest_UserNoId
{
    public string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }
}

#[CoversClass(Notification::class)]
#[CoversClass(NotificationManager::class)]
#[CoversClass(MailChannel::class)]
#[CoversClass(DatabaseChannel::class)]
class NotificationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    //  1. Notification Base — ID
    // ═══════════════════════════════════════════════════════

    public function testGetIdAutoGenerates(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $id = $n->getId();
        $this->assertNotEmpty($id);
        $this->assertSame(32, \strlen($id)); // 16 bytes → 32 hex
    }

    public function testGetIdIsStable(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $this->assertSame($n->getId(), $n->getId());
    }

    public function testSetIdOverrides(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $n->setId('custom-id-123');
        $this->assertSame('custom-id-123', $n->getId());
    }

    public function testSetIdReturnsThis(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $this->assertSame($n, $n->setId('x'));
    }

    public function testDifferentNotificationsGetUniqueIds(): void
    {
        $n1 = new NTTest_WelcomeNotification('A');
        $n2 = new NTTest_WelcomeNotification('B');
        $this->assertNotSame($n1->getId(), $n2->getId());
    }

    // ═══════════════════════════════════════════════════════
    //  2. Notification Base — Type
    // ═══════════════════════════════════════════════════════

    public function testGetTypeReturnsClassName(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $this->assertSame(NTTest_WelcomeNotification::class, $n->getType());
    }

    // ═══════════════════════════════════════════════════════
    //  3. Notification Base — via()
    // ═══════════════════════════════════════════════════════

    public function testViaReturnsChannelNames(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $user = new NTTest_UserWithEmail(1, 'alice@example.com');

        $this->assertSame(['mail', 'database'], $n->via($user));
    }

    public function testViaMailOnly(): void
    {
        $n = new NTTest_MailOnlyNotification();
        $user = new NTTest_UserWithEmail(1, 'a@b.com');
        $this->assertSame(['mail'], $n->via($user));
    }

    // ═══════════════════════════════════════════════════════
    //  4. Notification Base — getData()
    // ═══════════════════════════════════════════════════════

    public function testGetDataDispatchesToToMailMethod(): void
    {
        $n = new NTTest_WelcomeNotification('Alice');
        $user = new NTTest_UserWithEmail(1, 'alice@example.com');

        $data = $n->getData('mail', $user);
        $this->assertSame('Welcome, Alice!', $data['subject']);
        $this->assertStringContainsString('Alice', $data['body']);
    }

    public function testGetDataDispatchesToToDatabaseMethod(): void
    {
        $n = new NTTest_WelcomeNotification('Bob');
        $user = new NTTest_UserWithEmail(2, 'bob@example.com');

        $data = $n->getData('database', $user);
        $this->assertSame('welcome', $data['type']);
        $this->assertSame('Bob', $data['user']);
    }

    public function testGetDataFallsBackToToArray(): void
    {
        $n = new NTTest_ArrayFallbackNotification();
        $user = new NTTest_UserWithEmail(1, 'a@b.com');

        $data = $n->getData('mail', $user);
        $this->assertSame(['fallback' => true], $data);
    }

    public function testGetDataReturnsEmptyIfNoMethod(): void
    {
        $n = new NTTest_EmptyNotification();
        $user = new NTTest_UserWithEmail(1, 'a@b.com');

        $data = $n->getData('mail', $user);
        $this->assertSame([], $data);
    }

    public function testGetDataForUnknownChannel(): void
    {
        $n = new NTTest_WelcomeNotification('X');
        $user = new NTTest_UserWithEmail(1, 'x@y.com');

        // No toSms() method — falls back to toArray() if exists, else []
        $data = $n->getData('sms', $user);
        $this->assertSame([], $data);
    }

    // ═══════════════════════════════════════════════════════
    //  5. MailChannel — Basics
    // ═══════════════════════════════════════════════════════

    public function testMailChannelName(): void
    {
        $ch = new MailChannel(fn () => null);
        $this->assertSame('mail', $ch->getName());
    }

    public function testMailChannelImplementsInterface(): void
    {
        $ch = new MailChannel(fn () => null);
        $this->assertInstanceOf(NotificationChannelInterface::class, $ch);
    }

    // ═══════════════════════════════════════════════════════
    //  6. MailChannel — Sending
    // ═══════════════════════════════════════════════════════

    public function testMailChannelSendsViaCallable(): void
    {
        $sent = [];
        $mailer = function (string $to, array $data) use (&$sent): void {
            $sent[] = ['to' => $to, 'data' => $data];
        };

        $ch = new MailChannel($mailer);
        $user = new NTTest_UserWithEmail(1, 'alice@example.com');
        $n = new NTTest_MailOnlyNotification();

        $ch->send($user, $n);

        $this->assertCount(1, $sent);
        $this->assertSame('alice@example.com', $sent[0]['to']);
        $this->assertSame('Mail only', $sent[0]['data']['subject']);
    }

    public function testMailChannelRecordingDisabledByDefault(): void
    {
        $ch = new MailChannel(fn () => null);
        $user = new NTTest_UserWithEmail(1, 'a@b.com');
        $ch->send($user, new NTTest_MailOnlyNotification());

        $this->assertSame([], $ch->getSent());
    }

    public function testMailChannelRecordingEnabled(): void
    {
        $ch = new MailChannel(fn () => null, recording: true);
        $user = new NTTest_UserWithEmail(1, 'a@b.com');
        $ch->send($user, new NTTest_MailOnlyNotification());

        $sent = $ch->getSent();
        $this->assertCount(1, $sent);
        $this->assertSame('a@b.com', $sent[0]['to']);
    }

    public function testMailChannelClearSent(): void
    {
        $ch = new MailChannel(fn () => null, recording: true);
        $user = new NTTest_UserWithEmail(1, 'a@b.com');
        $ch->send($user, new NTTest_MailOnlyNotification());

        $this->assertCount(1, $ch->getSent());
        $ch->clearSent();
        $this->assertSame([], $ch->getSent());
    }

    public function testMailChannelClearSentReturnsThis(): void
    {
        $ch = new MailChannel(fn () => null);
        $this->assertSame($ch, $ch->clearSent());
    }

    // ═══════════════════════════════════════════════════════
    //  7. MailChannel — Recipient Resolution
    // ═══════════════════════════════════════════════════════

    public function testMailChannelResolvesRecipientViaMethod(): void
    {
        $to = null;
        $ch = new MailChannel(function (string $recipient) use (&$to): void {
            $to = $recipient;
        });

        $ch->send(new NTTest_UserWithEmail(1, 'via-method@test.com'), new NTTest_MailOnlyNotification());
        $this->assertSame('via-method@test.com', $to);
    }

    public function testMailChannelResolvesRecipientViaProperty(): void
    {
        $to = null;
        $ch = new MailChannel(function (string $recipient) use (&$to): void {
            $to = $recipient;
        });

        $ch->send(new NTTest_UserWithProperty(2, 'via-prop@test.com'), new NTTest_MailOnlyNotification());
        $this->assertSame('via-prop@test.com', $to);
    }

    public function testMailChannelThrowsWhenNoEmail(): void
    {
        $ch = new MailChannel(fn () => null);
        $user = new NTTest_UserNoEmail(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('getEmail');
        $ch->send($user, new NTTest_MailOnlyNotification());
    }

    // ═══════════════════════════════════════════════════════
    //  8. DatabaseChannel — Basics
    // ═══════════════════════════════════════════════════════

    public function testDatabaseChannelName(): void
    {
        $ch = new DatabaseChannel();
        $this->assertSame('database', $ch->getName());
    }

    public function testDatabaseChannelImplementsInterface(): void
    {
        $ch = new DatabaseChannel();
        $this->assertInstanceOf(NotificationChannelInterface::class, $ch);
    }

    // ═══════════════════════════════════════════════════════
    //  9. DatabaseChannel — Storing
    // ═══════════════════════════════════════════════════════

    public function testDatabaseChannelStoresRecord(): void
    {
        $ch = new DatabaseChannel();
        $user = new NTTest_UserWithEmail(1, 'a@b.com');
        $n = new NTTest_DbOnlyNotification();

        $ch->send($user, $n);

        $records = $ch->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame($n->getId(), $records[0]['id']);
        $this->assertSame(NTTest_DbOnlyNotification::class, $records[0]['type']);
        $this->assertSame(NTTest_UserWithEmail::class, $records[0]['notifiable_type']);
        $this->assertSame(1, $records[0]['notifiable_id']);
        $this->assertSame(['event' => 'db_only'], $records[0]['data']);
        $this->assertArrayHasKey('created_at', $records[0]);
    }

    public function testDatabaseChannelCount(): void
    {
        $ch = new DatabaseChannel();
        $this->assertSame(0, $ch->count());

        $ch->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_DbOnlyNotification());
        $this->assertSame(1, $ch->count());

        $ch->send(new NTTest_UserWithEmail(2, 'b@b.com'), new NTTest_DbOnlyNotification());
        $this->assertSame(2, $ch->count());
    }

    public function testDatabaseChannelGetRecordsFor(): void
    {
        $ch = new DatabaseChannel();
        $user1 = new NTTest_UserWithEmail(1, 'a@b.com');
        $user2 = new NTTest_UserWithEmail(2, 'c@d.com');

        $ch->send($user1, new NTTest_DbOnlyNotification());
        $ch->send($user2, new NTTest_DbOnlyNotification());
        $ch->send($user1, new NTTest_DbOnlyNotification());

        $forUser1 = $ch->getRecordsFor($user1);
        $this->assertCount(2, $forUser1);

        $forUser2 = $ch->getRecordsFor($user2);
        $this->assertCount(1, $forUser2);
    }

    public function testDatabaseChannelClearRecords(): void
    {
        $ch = new DatabaseChannel();
        $ch->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_DbOnlyNotification());
        $this->assertSame(1, $ch->count());

        $ch->clearRecords();
        $this->assertSame(0, $ch->count());
        $this->assertSame([], $ch->getRecords());
    }

    public function testDatabaseChannelClearRecordsReturnsThis(): void
    {
        $ch = new DatabaseChannel();
        $this->assertSame($ch, $ch->clearRecords());
    }

    // ═══════════════════════════════════════════════════════
    //  10. DatabaseChannel — Custom storeFn
    // ═══════════════════════════════════════════════════════

    public function testDatabaseChannelStoreFnCalled(): void
    {
        $stored = [];
        $ch = new DatabaseChannel(function (array $record) use (&$stored): void {
            $stored[] = $record;
        });

        $ch->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_DbOnlyNotification());

        $this->assertCount(1, $stored);
        $this->assertSame(['event' => 'db_only'], $stored[0]['data']);
    }

    // ═══════════════════════════════════════════════════════
    //  11. DatabaseChannel — ID Resolution
    // ═══════════════════════════════════════════════════════

    public function testDatabaseChannelResolvesIdViaMethod(): void
    {
        $ch = new DatabaseChannel();
        $user = new NTTest_UserWithEmail(42, 'a@b.com');
        $ch->send($user, new NTTest_DbOnlyNotification());

        $this->assertSame(42, $ch->getRecords()[0]['notifiable_id']);
    }

    public function testDatabaseChannelResolvesIdViaProperty(): void
    {
        $ch = new DatabaseChannel();
        $user = new NTTest_UserWithProperty(99, 'a@b.com');
        $ch->send($user, new NTTest_DbOnlyNotification());

        $this->assertSame(99, $ch->getRecords()[0]['notifiable_id']);
    }

    public function testDatabaseChannelFallsBackToSplObjectId(): void
    {
        $ch = new DatabaseChannel();
        $user = new NTTest_UserNoId('a@b.com');
        $ch->send($user, new NTTest_DbOnlyNotification());

        $id = $ch->getRecords()[0]['notifiable_id'];
        $this->assertSame(\spl_object_id($user), $id);
    }

    // ═══════════════════════════════════════════════════════
    //  12. NotificationManager — Registration
    // ═══════════════════════════════════════════════════════

    public function testRegisterChannelReturnsThis(): void
    {
        $m = new NotificationManager();
        $this->assertSame($m, $m->registerChannel(new MailChannel(fn () => null)));
    }

    public function testGetChannelReturnsRegistered(): void
    {
        $m = new NotificationManager();
        $mail = new MailChannel(fn () => null);
        $m->registerChannel($mail);

        $this->assertSame($mail, $m->getChannel('mail'));
    }

    public function testGetChannelReturnsNullForUnknown(): void
    {
        $m = new NotificationManager();
        $this->assertNull($m->getChannel('sms'));
    }

    public function testHasChannel(): void
    {
        $m = new NotificationManager();
        $this->assertFalse($m->hasChannel('mail'));
        $m->registerChannel(new MailChannel(fn () => null));
        $this->assertTrue($m->hasChannel('mail'));
    }

    public function testGetChannelNames(): void
    {
        $m = new NotificationManager();
        $this->assertSame([], $m->getChannelNames());

        $m->registerChannel(new MailChannel(fn () => null));
        $m->registerChannel(new DatabaseChannel());

        $names = $m->getChannelNames();
        $this->assertContains('mail', $names);
        $this->assertContains('database', $names);
    }

    // ═══════════════════════════════════════════════════════
    //  13. NotificationManager — send()
    // ═══════════════════════════════════════════════════════

    public function testSendDispatchesToAllChannels(): void
    {
        $mailSent = [];
        $mailer = function (string $to, array $data) use (&$mailSent): void {
            $mailSent[] = ['to' => $to, 'data' => $data];
        };

        $m = new NotificationManager();
        $db = new DatabaseChannel();
        $m->registerChannel(new MailChannel($mailer));
        $m->registerChannel($db);

        $user = new NTTest_UserWithEmail(1, 'alice@example.com');
        $m->send($user, new NTTest_WelcomeNotification('Alice'));

        $this->assertCount(1, $mailSent);
        $this->assertSame('alice@example.com', $mailSent[0]['to']);
        $this->assertSame(1, $db->count());
    }

    public function testSendThrowsForUnregisteredChannel(): void
    {
        $m = new NotificationManager();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sms');

        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_UnregisteredChannelNotification());
    }

    // ═══════════════════════════════════════════════════════
    //  14. NotificationManager — sendToMany()
    // ═══════════════════════════════════════════════════════

    public function testSendToManyDispatchesToAll(): void
    {
        $db = new DatabaseChannel();
        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(fn () => null));
        $m->registerChannel($db);

        $users = [
            new NTTest_UserWithEmail(1, 'a@x.com'),
            new NTTest_UserWithEmail(2, 'b@x.com'),
            new NTTest_UserWithEmail(3, 'c@x.com'),
        ];

        $m->sendToMany($users, new NTTest_WelcomeNotification('Team'));

        // Each user → mail + database = 3 database records
        $this->assertSame(3, $db->count());
    }

    // ═══════════════════════════════════════════════════════
    //  15. NotificationManager — Before Hook
    // ═══════════════════════════════════════════════════════

    public function testBeforeHookCalledBeforeSend(): void
    {
        $log = [];

        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(function () use (&$log): void {
            $log[] = 'mailer';
        }));

        $m->beforeSend(function () use (&$log): void {
            $log[] = 'before';
        });

        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_MailOnlyNotification());

        $this->assertSame(['before', 'mailer'], $log);
    }

    public function testBeforeHookReturnsThis(): void
    {
        $m = new NotificationManager();
        $this->assertSame($m, $m->beforeSend(fn () => null));
    }

    // ═══════════════════════════════════════════════════════
    //  16. NotificationManager — After Hook
    // ═══════════════════════════════════════════════════════

    public function testAfterHookCalledAfterSend(): void
    {
        $log = [];

        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(function () use (&$log): void {
            $log[] = 'mailer';
        }));

        $m->afterSend(function () use (&$log): void {
            $log[] = 'after';
        });

        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_MailOnlyNotification());

        $this->assertSame(['mailer', 'after'], $log);
    }

    public function testAfterHookReturnsThis(): void
    {
        $m = new NotificationManager();
        $this->assertSame($m, $m->afterSend(fn () => null));
    }

    // ═══════════════════════════════════════════════════════
    //  17. NotificationManager — Error Hook
    // ═══════════════════════════════════════════════════════

    public function testErrorHookSuppressesException(): void
    {
        $captured = null;

        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(function (): void {
            throw new RuntimeException('Mail failed');
        }));

        $m->onError(function ($n, $notif, $ch, Throwable $e) use (&$captured): void {
            $captured = $e;
        });

        // Should NOT throw
        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_MailOnlyNotification());

        $this->assertInstanceOf(RuntimeException::class, $captured);
        $this->assertSame('Mail failed', $captured->getMessage());
    }

    public function testNoErrorHookRethrowsException(): void
    {
        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(function (): void {
            throw new RuntimeException('Mail failed');
        }));

        $this->expectException(RuntimeException::class);
        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_MailOnlyNotification());
    }

    public function testOnErrorReturnsThis(): void
    {
        $m = new NotificationManager();
        $this->assertSame($m, $m->onError(fn () => null));
    }

    // ═══════════════════════════════════════════════════════
    //  18. NotificationManager — Sent Logging
    // ═══════════════════════════════════════════════════════

    public function testSentLogDisabledByDefault(): void
    {
        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(fn () => null));
        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_MailOnlyNotification());

        $this->assertSame([], $m->getSentLog());
    }

    public function testSentLogEnabled(): void
    {
        $m = new NotificationManager(logging: true);
        $m->registerChannel(new MailChannel(fn () => null));

        $n = new NTTest_MailOnlyNotification();
        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), $n);

        $log = $m->getSentLog();
        $this->assertCount(1, $log);
        $this->assertSame(NTTest_UserWithEmail::class, $log[0]['notifiable']);
        $this->assertSame(NTTest_MailOnlyNotification::class, $log[0]['notification']);
        $this->assertSame('mail', $log[0]['channel']);
        $this->assertSame($n->getId(), $log[0]['id']);
    }

    public function testSentLogMultipleChannels(): void
    {
        $m = new NotificationManager(logging: true);
        $m->registerChannel(new MailChannel(fn () => null));
        $m->registerChannel(new DatabaseChannel());

        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_WelcomeNotification('X'));

        $log = $m->getSentLog();
        $this->assertCount(2, $log);
        $this->assertSame('mail', $log[0]['channel']);
        $this->assertSame('database', $log[1]['channel']);
    }

    public function testClearSentLog(): void
    {
        $m = new NotificationManager(logging: true);
        $m->registerChannel(new MailChannel(fn () => null));
        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), new NTTest_MailOnlyNotification());

        $this->assertCount(1, $m->getSentLog());
        $m->clearSentLog();
        $this->assertSame([], $m->getSentLog());
    }

    public function testClearSentLogReturnsThis(): void
    {
        $m = new NotificationManager();
        $this->assertSame($m, $m->clearSentLog());
    }

    // ═══════════════════════════════════════════════════════
    //  19. Integration — Full Pipeline
    // ═══════════════════════════════════════════════════════

    public function testFullPipeline(): void
    {
        $mailSent = [];
        $hookOrder = [];

        $mailer = function (string $to, array $data) use (&$mailSent): void {
            $mailSent[] = ['to' => $to, 'data' => $data];
        };

        $db = new DatabaseChannel();
        $m = new NotificationManager(logging: true);
        $m->registerChannel(new MailChannel($mailer, recording: true));
        $m->registerChannel($db);

        $m->beforeSend(function () use (&$hookOrder): void {
            $hookOrder[] = 'before';
        });
        $m->afterSend(function () use (&$hookOrder): void {
            $hookOrder[] = 'after';
        });

        $user = new NTTest_UserWithEmail(1, 'alice@example.com');
        $n = new NTTest_WelcomeNotification('Alice');
        $m->send($user, $n);

        // Mail sent
        $this->assertCount(1, $mailSent);
        $this->assertSame('alice@example.com', $mailSent[0]['to']);
        $this->assertSame('Welcome, Alice!', $mailSent[0]['data']['subject']);

        // Database stored
        $this->assertSame(1, $db->count());
        $this->assertSame(['type' => 'welcome', 'user' => 'Alice'], $db->getRecords()[0]['data']);

        // Hooks called in order: before-mail, after-mail, before-database, after-database
        $this->assertSame(['before', 'after', 'before', 'after'], $hookOrder);

        // Sent log
        $log = $m->getSentLog();
        $this->assertCount(2, $log);
    }

    public function testSendToManyWithPropertyBasedUsers(): void
    {
        $db = new DatabaseChannel();
        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(fn () => null));
        $m->registerChannel($db);

        $users = [
            new NTTest_UserWithProperty(10, 'x@y.com'),
            new NTTest_UserWithProperty(20, 'a@b.com'),
        ];

        $m->sendToMany($users, new NTTest_WelcomeNotification('Group'));

        $this->assertSame(2, $db->count());
        $this->assertSame(10, $db->getRecords()[0]['notifiable_id']);
        $this->assertSame(20, $db->getRecords()[1]['notifiable_id']);
    }

    // ═══════════════════════════════════════════════════════
    //  20. Edge Cases
    // ═══════════════════════════════════════════════════════

    public function testMailChannelMultipleSends(): void
    {
        $ch = new MailChannel(fn () => null, recording: true);

        for ($i = 0; $i < 5; $i++) {
            $ch->send(new NTTest_UserWithEmail($i, "u{$i}@t.com"), new NTTest_MailOnlyNotification());
        }

        $this->assertCount(5, $ch->getSent());
    }

    public function testDatabaseChannelMultipleNotifications(): void
    {
        $ch = new DatabaseChannel();
        $user = new NTTest_UserWithEmail(1, 'a@b.com');

        $ch->send($user, new NTTest_DbOnlyNotification());
        $ch->send($user, new NTTest_DbOnlyNotification());
        $ch->send($user, new NTTest_DbOnlyNotification());

        $this->assertSame(3, $ch->count());
        $forUser = $ch->getRecordsFor($user);
        $this->assertCount(3, $forUser);
    }

    public function testErrorHookReceivesCorrectParams(): void
    {
        $receivedChannel = null;
        $receivedNotif = null;

        $m = new NotificationManager();
        $m->registerChannel(new MailChannel(function (): void {
            throw new LogicException('test');
        }));

        $m->onError(function ($notifiable, $notification, $channel, Throwable $e) use (&$receivedChannel, &$receivedNotif): void {
            $receivedChannel = $channel;
            $receivedNotif = $notification;
        });

        $n = new NTTest_MailOnlyNotification();
        $m->send(new NTTest_UserWithEmail(1, 'a@b.com'), $n);

        $this->assertSame('mail', $receivedChannel);
        $this->assertSame($n, $receivedNotif);
    }
}
