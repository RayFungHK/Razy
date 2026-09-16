<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The dropped-persistent-link recovery contract (live-caught by the benchmark
 * worker's "MySQL server has gone away", 2026-09 — a worker outlives the idle
 * link, the pool hands back the dead handle, and nothing used to recover).
 *
 * The full path is proven by fault-injection against real MySQL (KILL the link,
 * next query must recover on a fresh one); CI's matrix has no MySQL, so the
 * wiring is pinned here at source — positive form, per house convention.
 */
final class DatabaseReconnectTest extends TestCase
{
    public function testExecuteRetriesOnceOnConnectionLoss(): void
    {
        $source = (string) \str_replace("\r\n", "\n", (string) \file_get_contents(
            \dirname(__DIR__) . '/src/library/Razy/Database.php'
        ));

        $this->assertStringContainsString(
            'if ($this->isConnectionLoss($e) && $this->recoverConnection()) {',
            $source,
            'execute() must distinguish link death from query errors and attempt one recovery+retry'
        );

        $this->assertStringContainsString(
            "'gone away'",
            $source,
            'the loss detector must recognize MySQL gone-away language'
        );

        // The recovery must resynchronize everything caching the OLD link:
        // adapter, pool, transaction — a purged-but-referenced stale object
        // reproduces the bug exactly (fault-injection proved it).
        $this->assertMatchesRegularExpression(
            '/private function recoverConnection.*?\$this->adapter = \$this->driver->getAdapter\(\);.*?new StatementPool\(\$this->adapter\).*?new Transaction\(\$this->adapter\).*?\}/s',
            $source,
            'recoverConnection must rebuild adapter reference, statement pool, and transaction off the fresh link'
        );
    }

    public function testMysqlDriverReconnectsNonPersistent(): void
    {
        $source = (string) \str_replace("\r\n", "\n", (string) \file_get_contents(
            \dirname(__DIR__) . '/src/library/Razy/Database/Driver/MySQL.php'
        ));

        // Forcing persistence OFF on the retry is the whole trick: asking the
        // pool for another persistent link may hand back another dead one.
        $this->assertMatchesRegularExpression(
            '/public function reconnect.*?PDO::ATTR_PERSISTENT\] = false.*?\}/s',
            $source,
            'MySQL reconnect must force a fresh non-persistent link (the pool may hold more dead ones)'
        );

        $this->assertStringContainsString(
            '$this->lastConnectConfig = $config;',
            $source,
            'connect() must record its config as fuel for reconnect()'
        );
    }
}
