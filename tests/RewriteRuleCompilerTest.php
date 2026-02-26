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
        $this->assertSame('example\.com(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternConvertsWildcard(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('*.example.com');
        $this->assertSame('.+\.example\.com(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternSimpleDomain(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('localhost');
        $this->assertSame('localhost(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternMultipleSubdomains(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('sub.domain.example.com');
        $this->assertSame('sub\.domain\.example\.com(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternWildcardOnly(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('*');
        $this->assertSame('.+(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternMultipleWildcards(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('*.*.example.com');
        $this->assertSame('.+\..+\.example\.com(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternIpAddress(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('192.168.1.1');
        $this->assertSame('192\.168\.1\.1(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternEmptyString(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('');
        $this->assertSame('(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternTrailingDot(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('example.com.');
        $this->assertSame('example\.com\.(:\\d+)?', $pattern);
    }

    #[Test]
    public function domainToPatternWithExplicitPort(): void
    {
        // When domain already contains a port, no optional suffix is added
        $pattern = RewriteRuleCompiler::domainToPattern('localhost:8888');
        $this->assertSame('localhost:8888', $pattern);
    }

    #[Test]
    public function domainToPatternWithPortMatchesHttpHost(): void
    {
        // The generated pattern for 'localhost' must match both
        // 'localhost' and 'localhost:8888' (Apache %{HTTP_HOST})
        $pattern = RewriteRuleCompiler::domainToPattern('localhost');
        $this->assertMatchesRegularExpression('/^' . $pattern . '$/', 'localhost');
        $this->assertMatchesRegularExpression('/^' . $pattern . '$/', 'localhost:8888');
        $this->assertMatchesRegularExpression('/^' . $pattern . '$/', 'localhost:80');
    }

    #[Test]
    public function domainToPatternFqdnWithPortMatchesHttpHost(): void
    {
        $pattern = RewriteRuleCompiler::domainToPattern('example.com');
        $this->assertMatchesRegularExpression('/^' . $pattern . '$/', 'example.com');
        $this->assertMatchesRegularExpression('/^' . $pattern . '$/', 'example.com:8080');
        $this->assertDoesNotMatchRegularExpression('/^' . $pattern . '$/', 'other.com');
    }
}
