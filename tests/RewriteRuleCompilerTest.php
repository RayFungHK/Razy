<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Routing\RewriteRuleCompiler;

#[CoversClass(RewriteRuleCompiler::class)]
class RewriteRuleCompilerTest extends TestCase
{
    #[Test]
    public function domainToPatternEscapesDots(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('example.com');
        $this->assertSame('example\.com', $pattern);
    }

    #[Test]
    public function domainToPatternConvertsWildcard(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('*.example.com');
        $this->assertSame('.+\.example\.com', $pattern);
    }

    #[Test]
    public function domainToPatternSimpleDomain(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('localhost');
        $this->assertSame('localhost', $pattern);
    }

    #[Test]
    public function domainToPatternMultipleSubdomains(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('sub.domain.example.com');
        $this->assertSame('sub\.domain\.example\.com', $pattern);
    }

    #[Test]
    public function domainToPatternWildcardOnly(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('*');
        $this->assertSame('.+', $pattern);
    }

    #[Test]
    public function domainToPatternMultipleWildcards(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('*.*.example.com');
        $this->assertSame('.+\..+\.example\.com', $pattern);
    }

    #[Test]
    public function domainToPatternIpAddress(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('192.168.1.1');
        $this->assertSame('192\.168\.1\.1', $pattern);
    }

    #[Test]
    public function domainToPatternEmptyString(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('');
        $this->assertSame('', $pattern);
    }

    #[Test]
    public function domainToPatternTrailingDot(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('example.com.');
        $this->assertSame('example\.com\.', $pattern);
    }
}
