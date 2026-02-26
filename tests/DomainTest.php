<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Application;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Domain;
use Razy\Exception\ConfigurationException;
use ReflectionClass;
use ReflectionProperty;

/**
 * Comprehensive unit tests for the Domain class.
 *
 * Domain maps a hostname/alias to one or more Distributor instances based
 * on URL path prefixes. Tests cover construction, getters, matchQuery logic,
 * autoload delegation, dispose lifecycle, and error/edge cases.
 *
 * Strategy: Mock Application (constructor dependency) and use Reflection to
 * inspect/inject private state where needed. matchQuery() instantiates a real
 * Distributor which requires filesystem setup, so we test its flow indirectly
 * or verify pre-match state.
 */
#[CoversClass(Domain::class)]
class DomainTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Data provider tests — various alias formats
    // ──────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function aliasProvider(): array
    {
        return [
            'empty alias' => [''],
            'simple alias' => ['myalias'],
            'fqdn alias' => ['alias.example.com'],
            'alias with port' => ['alias.example.com:9090'],
            'wildcard alias' => ['*.alias.com'],
        ];
    }

    // ──────────────────────────────────────────────
    // Construction
    // ──────────────────────────────────────────────

    #[Test]
    public function constructorStoresPropertiesCorrectly(): void
    {
        $app = $this->mockApp();
        $mapping = ['/' => 'site_main', '/admin/' => 'site_admin'];

        $domain = new Domain($app, 'my.example.org', 'myalias', $mapping);

        $this->assertSame('my.example.org', $domain->getDomainName());
        $this->assertSame('myalias', $domain->getAlias());
        $this->assertSame($app, $domain->getApplication());
        $this->assertNull($domain->getDistributor(), 'No distributor matched yet');
    }

    #[Test]
    public function constructorThrowsWhenMappingIsEmpty(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No distributor is found.');

        new Domain($this->mockApp(), 'example.com', '', []);
    }

    #[Test]
    public function constructorSortsMappingByPathDepth(): void
    {
        $mapping = [
            '/' => 'root_dist',
            '/admin/' => 'admin_dist',
            '/admin/users/' => 'users_dist',
            '/api/' => 'api_dist',
        ];

        $domain = new Domain($this->mockApp(), 'example.com', '', $mapping);

        $sorted = $this->getPrivate($domain, 'mapping');
        $keys = \array_keys($sorted);

        // Deepest paths should come first after sortPathLevel
        // /admin/users/ (depth 2) before /admin/ (depth 1) and /api/ (depth 1) before / (depth 0)
        $this->assertSame('/admin/users/', $keys[0], 'Deepest path should be first');
        $this->assertSame('/', \end($keys), 'Root path should be last');
    }

    // ──────────────────────────────────────────────
    // Getters
    // ──────────────────────────────────────────────

    #[Test]
    public function getAliasReturnsEmptyStringWhenNotSet(): void
    {
        $domain = $this->makeDomain(alias: '');
        $this->assertSame('', $domain->getAlias());
    }

    #[Test]
    public function getAliasReturnsSetValue(): void
    {
        $domain = $this->makeDomain(alias: 'cdn.example.com');
        $this->assertSame('cdn.example.com', $domain->getAlias());
    }

    #[Test]
    public function getDomainNameReturnsConstructedValue(): void
    {
        $domain = $this->makeDomain(domain: 'sub.domain.example.co.uk');
        $this->assertSame('sub.domain.example.co.uk', $domain->getDomainName());
    }

    #[Test]
    public function getApplicationReturnsSameInstance(): void
    {
        $app = $this->mockApp();
        $domain = $this->makeDomain(app: $app);
        $this->assertSame($app, $domain->getApplication());
    }

    #[Test]
    public function getDistributorReturnsNullBeforeMatch(): void
    {
        $domain = $this->makeDomain();
        $this->assertNull($domain->getDistributor());
    }

    // ──────────────────────────────────────────────
    // matchQuery — pre-match state / edge cases
    // ──────────────────────────────────────────────

    #[Test]
    public function matchQueryReturnsNullWhenMappingIsEmptyViaReflection(): void
    {
        // Bypass constructor to create a Domain with empty mapping
        $domain = $this->makeDomainWithoutConstructor(mapping: []);

        $result = $domain->matchQuery('/anything');
        $this->assertNull($result);
        $this->assertNull($domain->getDistributor());
    }

    // ──────────────────────────────────────────────
    // autoload delegation
    // ──────────────────────────────────────────────

    #[Test]
    public function autoloadReturnsFalseWhenNoDistributor(): void
    {
        $domain = $this->makeDomain();
        // No matchQuery called → distributor is null
        $this->assertFalse($domain->autoload('SomeClass'));
    }

    #[Test]
    public function autoloadDelegatesToDistributor(): void
    {
        $domain = $this->makeDomainWithoutConstructor(mapping: ['/' => 'test']);

        $mockDist = $this->createMock(Distributor::class);
        $mockDist->expects($this->once())
            ->method('autoload')
            ->with('App\Models\User')
            ->willReturn(true);

        $this->setPrivate($domain, 'distributor', $mockDist);

        $this->assertTrue($domain->autoload('App\Models\User'));
    }

    #[Test]
    public function autoloadReturnsFalseWhenDistributorCannotResolve(): void
    {
        $domain = $this->makeDomainWithoutConstructor(mapping: ['/' => 'test']);

        $mockDist = $this->createMock(Distributor::class);
        $mockDist->method('autoload')->willReturn(false);

        $this->setPrivate($domain, 'distributor', $mockDist);

        $this->assertFalse($domain->autoload('NonExistent\Class'));
    }

    // ──────────────────────────────────────────────
    // dispose lifecycle
    // ──────────────────────────────────────────────

    #[Test]
    public function disposeReturnsFluentSelf(): void
    {
        $domain = $this->makeDomain();
        $result = $domain->dispose();
        $this->assertSame($domain, $result, 'dispose() should return $this for fluent calls');
    }

    #[Test]
    public function disposeIsNoOpWhenNoDistributor(): void
    {
        $domain = $this->makeDomain();
        // Should not throw — simply a no-op
        $result = $domain->dispose();
        $this->assertSame($domain, $result);
    }

    #[Test]
    public function disposeCallsRegistryDisposeOnDistributor(): void
    {
        $domain = $this->makeDomainWithoutConstructor(mapping: ['/' => 'test']);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        $mockRegistry->expects($this->once())->method('dispose');

        $mockDist = $this->createMock(Distributor::class);
        $mockDist->method('getRegistry')->willReturn($mockRegistry);

        $this->setPrivate($domain, 'distributor', $mockDist);

        $domain->dispose();
    }

    // ──────────────────────────────────────────────
    // Edge cases — domain names
    // ──────────────────────────────────────────────

    #[Test]
    public function domainWithPortInName(): void
    {
        $domain = $this->makeDomain(domain: 'localhost:8080');
        $this->assertSame('localhost:8080', $domain->getDomainName());
    }

    #[Test]
    public function domainWithIPAddress(): void
    {
        $domain = $this->makeDomain(domain: '192.168.1.100');
        $this->assertSame('192.168.1.100', $domain->getDomainName());
    }

    #[Test]
    public function domainWithIPv6Address(): void
    {
        $domain = $this->makeDomain(domain: '[::1]');
        $this->assertSame('[::1]', $domain->getDomainName());
    }

    #[Test]
    public function domainWithWildcard(): void
    {
        $domain = $this->makeDomain(domain: '*.example.com');
        $this->assertSame('*.example.com', $domain->getDomainName());
    }

    // ──────────────────────────────────────────────
    // Edge cases — mapping entries
    // ──────────────────────────────────────────────

    #[Test]
    public function singleMappingEntryIsAccepted(): void
    {
        $domain = $this->makeDomain(mapping: ['/app/' => 'myapp']);
        $sorted = $this->getPrivate($domain, 'mapping');
        $this->assertCount(1, $sorted);
        $this->assertSame('myapp', \reset($sorted));
    }

    #[Test]
    public function multipleMappingsPreserveAllEntries(): void
    {
        $mapping = [
            '/' => 'root',
            '/api/' => 'api',
            '/admin/' => 'admin',
        ];
        $domain = $this->makeDomain(mapping: $mapping);
        $sorted = $this->getPrivate($domain, 'mapping');
        $this->assertCount(3, $sorted);

        // All values must be present
        $values = \array_values($sorted);
        $this->assertContains('root', $values);
        $this->assertContains('api', $values);
        $this->assertContains('admin', $values);
    }

    #[Test]
    public function mappingWithTaggedDistributorIdentifier(): void
    {
        // Distributor identifiers can include a tag: "mysite@dev"
        $domain = $this->makeDomain(mapping: ['/' => 'mysite@dev']);
        $sorted = $this->getPrivate($domain, 'mapping');
        $this->assertSame('mysite@dev', \reset($sorted));
    }

    #[Test]
    #[DataProvider('aliasProvider')]
    public function aliasIsStoredAndRetrievedCorrectly(string $alias): void
    {
        $domain = $this->makeDomain(alias: $alias);
        $this->assertSame($alias, $domain->getAlias());
    }

    // ──────────────────────────────────────────────
    // Multiple autoload calls
    // ──────────────────────────────────────────────

    #[Test]
    public function autoloadCalledMultipleTimesWithDifferentClasses(): void
    {
        $domain = $this->makeDomainWithoutConstructor(mapping: ['/' => 'test']);

        $mockDist = $this->createMock(Distributor::class);
        $mockDist->method('autoload')
            ->willReturnCallback(fn (string $class) => $class === 'Found\Class');

        $this->setPrivate($domain, 'distributor', $mockDist);

        $this->assertTrue($domain->autoload('Found\Class'));
        $this->assertFalse($domain->autoload('Missing\Class'));
    }

    // ──────────────────────────────────────────────
    // dispose idempotency
    // ──────────────────────────────────────────────

    #[Test]
    public function disposeCanBeCalledMultipleTimes(): void
    {
        $domain = $this->makeDomainWithoutConstructor(mapping: ['/' => 'test']);

        $mockRegistry = $this->createMock(ModuleRegistry::class);
        // dispose on registry should be called each time Domain::dispose() is called
        $mockRegistry->expects($this->exactly(2))->method('dispose');

        $mockDist = $this->createMock(Distributor::class);
        $mockDist->method('getRegistry')->willReturn($mockRegistry);

        $this->setPrivate($domain, 'distributor', $mockDist);

        $domain->dispose();
        $domain->dispose();
    }
    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Create a mock Application (avoids its heavy constructor side-effects).
     */
    private function mockApp(): Application
    {
        return $this->createMock(Application::class);
    }

    /**
     * Build a Domain instance with sensible defaults.
     *
     * @param string $domain Domain name
     * @param string $alias Domain alias
     * @param array $mapping URL-path => distributor-identifier mapping
     * @param Application|null $app Optional mock Application
     */
    private function makeDomain(
        string $domain = 'example.com',
        string $alias = '',
        array $mapping = ['/' => 'default_dist'],
        ?Application $app = null,
    ): Domain {
        return new Domain($app ?? $this->mockApp(), $domain, $alias, $mapping);
    }

    /**
     * Get a private/protected property value via Reflection.
     */
    private function getPrivate(Domain $domain, string $property): mixed
    {
        $ref = new ReflectionProperty(Domain::class, $property);
        $ref->setAccessible(true);
        return $ref->getValue($domain);
    }

    /**
     * Set a private/protected property value via Reflection.
     */
    private function setPrivate(Domain $domain, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(Domain::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($domain, $value);
    }

    /**
     * Create a Domain via Reflection without invoking the constructor,
     * useful for testing methods in isolation without mapping validation.
     */
    private function makeDomainWithoutConstructor(
        string $domain = 'example.com',
        string $alias = '',
        array $mapping = [],
        ?Application $app = null,
    ): Domain {
        $ref = new ReflectionClass(Domain::class);
        /** @var Domain $instance */
        $instance = $ref->newInstanceWithoutConstructor();

        $this->setPrivate($instance, 'app', $app ?? $this->mockApp());
        $this->setPrivate($instance, 'domain', $domain);
        $this->setPrivate($instance, 'alias', $alias);
        $this->setPrivate($instance, 'mapping', $mapping);
        // distributor defaults to null in the class definition

        return $instance;
    }
}
