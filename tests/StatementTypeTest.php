<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Database\Statement\StatementType;

#[CoversClass(StatementType::class)]
class StatementTypeTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $cases = StatementType::cases();
        $this->assertCount(6, $cases);
    }

    #[Test]
    public function selectHasCorrectValue(): void
    {
        $this->assertSame('select', StatementType::Select->value);
    }

    #[Test]
    public function insertHasCorrectValue(): void
    {
        $this->assertSame('insert', StatementType::Insert->value);
    }

    #[Test]
    public function updateHasCorrectValue(): void
    {
        $this->assertSame('update', StatementType::Update->value);
    }

    #[Test]
    public function deleteHasCorrectValue(): void
    {
        $this->assertSame('delete', StatementType::Delete->value);
    }

    #[Test]
    public function rawHasCorrectValue(): void
    {
        $this->assertSame('sql', StatementType::Raw->value);
    }

    #[Test]
    public function replaceHasCorrectValue(): void
    {
        $this->assertSame('replace', StatementType::Replace->value);
    }

    #[Test]
    public function fromStringSelect(): void
    {
        $this->assertSame(StatementType::Select, StatementType::from('select'));
    }

    #[Test]
    public function tryFromInvalidReturnsNull(): void
    {
        $this->assertNull(StatementType::tryFrom('invalid'));
    }

    #[Test]
    public function tryFromRawReturnsSql(): void
    {
        $this->assertSame(StatementType::Raw, StatementType::tryFrom('sql'));
    }
}
