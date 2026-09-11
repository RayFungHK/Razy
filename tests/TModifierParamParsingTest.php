<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Template\Plugin\TModifier;

/**
 * Pins the ':param' wire format of TModifier::modify().
 *
 * Regression guard: the shipped implementation once iterated the
 * PATTERN-ORDER preg_match_all matrix as if it were match rows, so every
 * argument degraded to '' and parameterised modifiers silently ran on their
 * defaults (join, truncate:50, number:2 — all of them). These tests are the
 * contract Entity.php:635-641 relies on.
 */
#[CoversClass(TModifier::class)]
class TModifierParamParsingTest extends TestCase
{
    public function testNoParamsReceivesEmptyArgs(): void
    {
        $this->assertSame('["V",[]]', $this->capture()->modify('V', ''));
    }

    public function testWordArgument(): void
    {
        $this->assertSame('["V",["upper"]]', $this->capture()->modify('V', ':upper'));
    }

    public function testIntegerArgument(): void
    {
        $this->assertSame('["V",["20"]]', $this->capture()->modify('V', ':20'));
    }

    public function testNegativeNumberArgument(): void
    {
        $this->assertSame('["V",["-5"]]', $this->capture()->modify('V', ':-5'));
    }

    public function testQuotedArgumentKeepsSpacesAndLosesQuotes(): void
    {
        $this->assertSame('["V",["b i em a"]]', $this->capture()->modify('V', ":'b i em a'"));
        $this->assertSame('["V",["Y-m-d H:i"]]', $this->capture()->modify('V', ':"Y-m-d H:i"'));
    }

    public function testQuotedArgumentMayContainColonsAndPipes(): void
    {
        $this->assertSame('["V",["H:i"]]', $this->capture()->modify('V', ':"H:i"'));
    }

    public function testMultipleArgumentsKeepOrder(): void
    {
        $this->assertSame('["V",["120","...","1"]]', $this->capture()->modify('V', ':120:\'...\':1'));
    }

    public function testQuotedEmptyStringIsPreservedAsEmpty(): void
    {
        $this->assertSame('["V",[""]]', $this->capture()->modify('V', ":''"));
    }

    public function testMixedSequenceMatchesRealChainUsage(): void
    {
        // what Entity produces for {$x->number:2:'.'}
        $this->assertSame('["V",["2","."]]', $this->capture()->modify('V', ":2:'.'"));
    }

    public function testChainParsingEndToEndWithRealModifier(): void
    {
        // The shipped join modifier is the oldest argument-taking plugin — exercise it.
        $factory = require __DIR__ . '/../src/plugins/Template/modifier.join.php';
        $join = $factory();
        $join->setName('join');

        $this->assertSame('a, b, c', $join->modify(['a', 'b', 'c'], ":', '"));
    }

    /**
     * Capture every process() invocation as [value, args].
     */
    private function capture(): TModifier
    {
        return new class() extends TModifier {
            protected function process(mixed $value, string ...$args): string
            {
                return \json_encode([$value, $args], JSON_UNESCAPED_UNICODE);
            }
        };
    }
}
