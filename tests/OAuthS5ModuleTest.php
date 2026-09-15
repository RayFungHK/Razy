<?php

declare(strict_types=1);

namespace Razy\Tests;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * S5 module tests (razymod/oauth, OAuth dossier): module metadata, the
 * allow-list shape, the flow factory's config/env discipline, and the Q1
 * no-storage vow â€” all without booting a distributor.
 */
/**
 * Minimal PSR-16 double for flow assembly (defined in-file: this suite must
 * survive single-file --filter runs, so it borrows nothing from sibling
 * test files).
 */
#[\CoversNothing]
final class S5FlowCache implements \Razy\Cache\CacheInterface
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }
}

#[CoversNothing]
final class OAuthS5ModuleTest extends TestCase
{
    /** env save/restore around anything that reads it (house hygiene) */
    private const TOUCHED_ENV = ['RAZY_OAUTH_STATE_SECRET', 'OAUTH_TEST_ID', 'OAUTH_TEST_SECRET'];

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (self::TOUCHED_ENV as $name) {
            $this->envBackup[$name] = \getenv($name);
        }

        \putenv('RAZY_OAUTH_STATE_SECRET=s5-test-secret');
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            \putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testModuleMetadataIsSignedDiscipline(): void
    {
        $meta = require $this->moduleRoot() . '/module.php';

        self::assertSame('razymod/oauth', $meta['module_code']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $meta['version'], 'semver (RZ-012)');

        $package = require $this->moduleRoot() . '/default/package.php';
        self::assertSame($meta['version'], $package['version'], 'manifest versions agree');
        self::assertSame('oauth_api', $package['api_name']);
        // Q1 law, pinned: this module owns NO storage â€” a migration key here
        // would mean a users table snuck in through the back door
        self::assertArrayNotHasKey('migration', $package);
    }

    public function testControllerPublishesOnlyReadOnlyProviders(): void
    {
        $src = (string) \file_get_contents($this->moduleRoot() . '/default/controller/oauth.php');

        // queue-admin :56-59 shape: an implemented allow-list, not the
        // framework default that allows everything
        self::assertStringContainsString('return isset(self::API_ALLOW[$method]);', $src);
        self::assertStringContainsString("'providers' => true,", $src);
        self::assertStringContainsString("addLazyRoute(['authorize' => 'authorize'])", $src);
        self::assertStringContainsString("addLazyRoute(['callback' => 'callback'])", $src);
    }

    public function testHandlersUse302NeverThe301Goto(): void
    {
        // the dossier's own trap: Controller::goto is 301-cached
        foreach (['oauth.authorize.php', 'oauth.callback.php'] as $file) {
            $src = (string) \file_get_contents($this->moduleRoot() . '/default/controller/' . $file);

            self::assertStringContainsString('true, 302', $src);
            self::assertStringNotContainsString('$this->goto(', $src, 'a 301 authorization redirect would be a cross-account bug factory');
        }
    }

    public function testFlowFactoryAssemblesConfigAndEnvSeparately(): void
    {
        $factory = require $this->moduleRoot() . '/default/controller/support/flow.php';

        \putenv('OAUTH_TEST_ID=client-id-value');
        \putenv('OAUTH_TEST_SECRET=client-secret-value');

        $cache = new S5FlowCache(); // OAuth2CoreTest's double (suite-wide namespace)

        $bundle = $factory([
            'post_login_redirect' => 'https://shop.example/account',
            'providers' => [
                'github' => [
                    'enabled' => true,
                    'client_id_env' => 'OAUTH_TEST_ID',
                    'client_secret_env' => 'OAUTH_TEST_SECRET',
                    'redirect_uri' => 'https://shop.example/razymod/oauth/callback',
                ],
                'disabled-one' => ['enabled' => false],
            ],
        ], $cache);

        self::assertSame(['github'], $bundle['registry']->names(), 'only enabled providers register');
        self::assertNull(($bundle['configFor'])('disabled-one'));

        $config = ($bundle['configFor'])('github');
        self::assertNotNull($config);
        self::assertSame('client-id-value', $config->clientId, 'config carried the NAME, env carried the value (Q5)');
        self::assertSame('client-secret-value', $config->clientSecret);
        self::assertSame('https://shop.example/account', $bundle['postLoginRedirect']);
    }

    public function testUnknownProviderNameDiesLoud(): void
    {
        $factory = require $this->moduleRoot() . '/default/controller/support/flow.php';
        $cache = new S5FlowCache();

        $this->expectException(\Razy\Exception\OAuthException::class);
        $this->expectExceptionMessage('Unknown OAuth provider in config');

        $factory(['providers' => ['facebook' => ['enabled' => true]]], $cache);
    }

    public function testMissingStateSecretFailsLoudAtAssembly(): void
    {
        \putenv('RAZY_OAUTH_STATE_SECRET='); // Q5: no default, no silent-weak mode
        $factory = require $this->moduleRoot() . '/default/controller/support/flow.php';

        $this->expectException(\Razy\Exception\OAuthException::class);
        $this->expectExceptionMessage('signing secret');

        $factory(['providers' => []], new S5FlowCache());
    }

    public function testManualChapterShipsWithTheModule(): void
    {
        $manual = SYSTEM_ROOT . '/manual/09-social-login.md';
        self::assertFileExists($manual);
        $text = (string) \file_get_contents($manual);

        self::assertStringContainsString('social.user_resolved', $text);
        self::assertStringContainsString('RAZY_OAUTH_STATE_SECRET', $text);
        self::assertStringContainsString('client_id_env', $text, 'the env-reference discipline is documented, not folklore');
    }

    private function moduleRoot(): string
    {
        return SYSTEM_ROOT . '/modules/oauth';
    }
}
