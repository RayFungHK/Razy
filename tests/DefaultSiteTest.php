<?php

// phpcs.ignore

/**
 * Default-site ('*') registration — behavioral regression (origin 2026-09).
 *
 * matchDomain() ends with a documented fallback: when no exact/alias/wildcard
 * key matches, the bare '*' site answers (the default tenant). But
 * updateSites() built its multisite table through an isFqdn() gate whose
 * grammar requires a 2+ character label — the bare '*' could never pass
 * validation, so the documented fallback was UNREACHABLE BY CONSTRUCTION.
 *
 * Proven live the day the benchmark gate site booted a multisite worker:
 * host('') (the no-request boot pass) died with "No domain matched for ''"
 * despite a '*' entry sitting in sites.inc.php.
 *
 * @license MIT
 */

use Razy\Application;

class DefaultSiteTest extends PHPUnit\Framework\TestCase
{
    private const FIXTURE_DIST = 'phpunit_fixture';

    private string $fixturePath;

    protected function setUp(): void
    {
        // updateSites() only registers a distributor whose dist.php exists on
        // disk (silent skip otherwise) — provide the minimal real file.
        $this->fixturePath = SITES_FOLDER . '/' . self::FIXTURE_DIST;

        if (!is_dir($this->fixturePath)) {
            mkdir($this->fixturePath, 0777, true);
        }
        if (!is_file($this->fixturePath . '/dist.php')) {
            file_put_contents(
                $this->fixturePath . '/dist.php',
                "<?php\n\nreturn ['dist' => '" . self::FIXTURE_DIST . "', 'greedy' => false];\n",
            );
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath . '/dist.php');
        @rmdir($this->fixturePath);
    }

    public function testBareStarRegistersAsDefaultSite(): void
    {
        $app = $this->appWithDomains(['*' => ['/' => self::FIXTURE_DIST]]);
        $multisite = $this->multisiteOf($app);

        $this->assertArrayHasKey(
            '*',
            $multisite,
            "the bare '*' domain must enter the multisite table — matchDomain's default-site "
            . 'fallback reads it there; isFqdn validation must not silently drop it',
        );
        $this->assertSame(self::FIXTURE_DIST, $multisite['*']['/']);
    }

    public function testNormalDomainsStillRegister(): void
    {
        $app = $this->appWithDomains(['good.example.com' => ['/' => self::FIXTURE_DIST]]);
        $multisite = $this->multisiteOf($app);

        $this->assertSame(
            self::FIXTURE_DIST,
            $multisite['good.example.com']['/'] ?? null,
            'ordinary FQDN registration must be untouched by the default-site allowance',
        );
    }

    public function testMalformedDomainsStillRejected(): void
    {
        $app = $this->appWithDomains(['bad_domain!' => ['/' => self::FIXTURE_DIST]]);
        $multisite = $this->multisiteOf($app);

        $this->assertArrayNotHasKey(
            'bad_domain!',
            $multisite,
            'opening the door for the bare "*" must not widen what ordinary garbage keys may register',
        );
    }

    private function appWithDomains(array $domains): Application
    {
        $app = new Application();

        $ref = new ReflectionClass($app);

        $config = $ref->getProperty('config');
        $config->setAccessible(true);
        $config->setValue($app, ['domains' => $domains, 'alias' => []]);

        $app->updateSites();

        $multisite = $ref->getProperty('multisite');
        $multisite->setAccessible(true);

        return $app;
    }

    private function multisiteOf(Application $app): array
    {
        $ref = new ReflectionProperty($app, 'multisite');
        $ref->setAccessible(true);

        return $ref->getValue($app);
    }
}
