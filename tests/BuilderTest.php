<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Database\Statement\Builder;
use Razy\Database;
use Razy\Database\Statement;

#[CoversClass(Builder::class)]
class BuilderTest extends TestCase
{
    #[Test]
    public function initBindsStatementOnce(): void
    {
        $builder = new Builder();
        $db = new Database('test');
        $stmt = new Statement($db);

        $result = $builder->init($stmt);
        $this->assertSame($builder, $result);
    }

    #[Test]
    public function initReturnsSelfForChaining(): void
    {
        $builder = new Builder();
        $this->assertInstanceOf(Builder::class, $builder->init());
    }

    #[Test]
    public function initDoesNotRebind(): void
    {
        $builder = new Builder();
        $db1 = new Database('test1');
        $db2 = new Database('test2');
        $stmt1 = new Statement($db1);
        $stmt2 = new Statement($db2);

        $builder->init($stmt1);
        $builder->init($stmt2); // Should NOT overwrite

        // Use reflection to verify
        $ref = new \ReflectionProperty(Builder::class, 'statement');
        $ref->setAccessible(true);
        $this->assertSame($stmt1, $ref->getValue($builder));
    }

    #[Test]
    public function buildDefaultImplementationDoesNothing(): void
    {
        $builder = new Builder();
        $builder->build('users');
        $this->assertTrue(true); // No exception = success
    }

    #[Test]
    public function postProcessDefaultIsNull(): void
    {
        $builder = new Builder();
        $ref = new \ReflectionProperty(Builder::class, 'postProcess');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($builder));
    }

    #[Test]
    public function statementDefaultIsNull(): void
    {
        $builder = new Builder();
        $ref = new \ReflectionProperty(Builder::class, 'statement');
        $ref->setAccessible(true);
        $this->assertNull($ref->getValue($builder));
    }

    #[Test]
    public function initWithNullKeepsNull(): void
    {
        $builder = new Builder();
        $builder->init(null);

        $ref = new \ReflectionProperty(Builder::class, 'statement');
        $ref->setAccessible(true);
        // init(null) sets null, which is falsy, so subsequent init should bind
        $this->assertNull($ref->getValue($builder));
    }

    #[Test]
    public function initWithNullThenRealBindsCorrectly(): void
    {
        $builder = new Builder();
        $builder->init(null);

        $db = new Database('test');
        $stmt = new Statement($db);
        $builder->init($stmt);

        $ref = new \ReflectionProperty(Builder::class, 'statement');
        $ref->setAccessible(true);
        $this->assertSame($stmt, $ref->getValue($builder));
    }
}
