<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Razy\Cache;
use Razy\Cache\CacheInterface;
use Razy\Exception\SetupException;
use Razy\Security\Wizard\WizardTokenSigner;
use Razy\Setup\WizardRunner;

/**
 * MODULE-LIFECYCLE.md L4: the wizard door — CLI-minted, dist+module-bound,
 * single-use, ten-minute tokens (Q6 rails), and a runner whose every rail is
 * either behaviour-tested at the signer or source-pinned at the doors the
 * CLI_MODE test bootstrap cannot enter.
 */
#[CoversNothing]
final class ModuleLifecycleL4Test extends TestCase
{
    private const SECRET = 'test-secret-not-for-production';

    protected function tearDown(): void
    {
        \putenv('RAZY_WIZARD_TOKEN_SECRET');
        unset($_ENV['RAZY_WIZARD_TOKEN_SECRET'], $_SERVER['RAZY_WIZARD_TOKEN_SECRET']);
        parent::tearDown();
    }

    // ── the token: mint, verify, spend ─────────────────────────────────────

    public function testMintedTokenVerifiesAgainstItsOwnDistAndModule(): void
    {
        $signer = new WizardTokenSigner(self::SECRET, $this->cacheFake());

        $token = $signer->issue('appdemo', 'erp/holiday');
        $verified = $signer->verify($token, 'appdemo', 'erp/holiday');

        self::assertArrayHasKey('nonce', $verified);
        $signer->redeem($verified['nonce']); // first spend passes — the assertion IS the pass
    }

    public function testTokenIsBoundToDistributorAndModule(): void
    {
        $signer = new WizardTokenSigner(self::SECRET, $this->cacheFake());
        $token = $signer->issue('appdemo', 'erp/holiday');

        try {
            $signer->verify($token, 'othersite', 'erp/holiday');
            self::fail('a token must not cross distributors');
        } catch (SetupException $e) {
            self::assertStringContainsString('different distributor', $e->getMessage());
        }

        try {
            $signer->verify($token, 'appdemo', 'erp/other');
            self::fail('a token must not cross modules');
        } catch (SetupException $e) {
            self::assertStringContainsString('different module', $e->getMessage());
        }
    }

    public function testTamperedTokenDiesOnConstantTimeHmacBeforeParse(): void
    {
        $signer = new WizardTokenSigner(self::SECRET, $this->cacheFake());
        $token = $signer->issue('appdemo', 'erp/holiday');

        [$seg, $sig] = \explode('.', $token);
        // flip one bit inside the payload, keep the signature — parse order
        // (HMAC before json_decode) makes this a signature failure, never a
        // payload-shape failure.
        $tamperedSeg = \substr($seg, 0, -2) . ((\substr($seg, -2) === 'AA') ? 'BB' : 'AA');

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('signature mismatch');

        $signer->verify($tamperedSeg . '.' . $sig, 'appdemo', 'erp/holiday');
    }

    public function testSingleUseNonceIsSpentForeverAfterFirstRedeem(): void
    {
        $signer = new WizardTokenSigner(self::SECRET, $this->cacheFake());
        $token = $signer->issue('appdemo', 'erp/holiday');
        $nonce = $signer->verify($token, 'appdemo', 'erp/holiday')['nonce'];

        $signer->redeem($nonce);

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('already spent');

        $signer->redeem($nonce); // the replay the whole scheme exists to kill
    }

    public function testTokenOutsideItsWindowIsRefusedFreshlySignedOrNot(): void
    {
        $signer = new WizardTokenSigner(self::SECRET, $this->cacheFake(), 60);

        // a genuine token cannot be aged without sleeping, but the verify
        // path is time arithmetic — replay it as a correctly-signed stale
        // payload, which is exactly what a captured-then-replayed token is.
        $payload = \json_encode(['n' => \str_repeat('a', 32), 'd' => 'appdemo', 'm' => 'erp/holiday', 't' => \time() - 3600]);
        $seg = \rtrim(\strtr(\base64_encode((string) $payload), '+/', '-_'), '=');
        $sig = \rtrim(\strtr(\base64_encode(\hash_hmac('sha256', $seg, self::SECRET, true)), '+/', '-_'), '=');

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('older than the allowed window');

        $signer->verify($seg . '.' . $sig, 'appdemo', 'erp/holiday');
    }

    public function testEmptySecretOrTinyTtlNeverReachProduction(): void
    {
        try {
            new WizardTokenSigner('', $this->cacheFake());
            self::fail('a blank signing secret must be refused at construction');
        } catch (SetupException $e) {
            self::assertStringContainsString('RAZY_WIZARD_TOKEN_SECRET', $e->getMessage());
        }

        $this->expectException(SetupException::class);
        new WizardTokenSigner(self::SECRET, $this->cacheFake(), 0);
    }

    public function testNullAdapterCannotHostSingleUseTokens(): void
    {
        // An unconfigured Cache hands out NullAdapter whose set() is a silent
        // no-op — minting against it would hand the operator a token that
        // can NEVER be spent. Refuse at the mint, not at the browser.
        $signer = new WizardTokenSigner(self::SECRET, new Cache\NullAdapter());

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('NullAdapter');

        $signer->issue('appdemo', 'erp/holiday');
    }

    // ── the shared signing surface (CLI mint and web spend agree) ──────────

    public function testMakeSignerFailsLoudWithoutEnvSecret(): void
    {
        \putenv('RAZY_WIZARD_TOKEN_SECRET');
        unset($_ENV['RAZY_WIZARD_TOKEN_SECRET'], $_SERVER['RAZY_WIZARD_TOKEN_SECRET']);

        $this->expectException(SetupException::class);
        $this->expectExceptionMessage('RAZY_WIZARD_TOKEN_SECRET');

        WizardRunner::makeSigner();
    }

    public function testMakeSignerReadsTheEnvSecretOnceSet(): void
    {
        \putenv('RAZY_WIZARD_TOKEN_SECRET=' . self::SECRET);
        $_ENV['RAZY_WIZARD_TOKEN_SECRET'] = self::SECRET;

        try {
            WizardRunner::makeSigner();
            // reaching here means the secret resolved and the configured
            // cache accepted the nonce store — both halves of the contract
            self::assertTrue(true);
        } catch (SetupException $e) {
            // the ONLY acceptable failure is the cache half, never the secret
            self::assertStringNotContainsString('RAZY_WIZARD_TOKEN_SECRET', $e->getMessage());
        }
    }

    // ── the runner's CLI refuse (the only live-callable door under tests) ──

    public function testRunnerRefusesToExistUnderCli(): void
    {
        $runner = new WizardRunner($this->createMock(\Razy\Distributor::class));

        // non-setup paths fall through...
        self::assertFalse($runner->handle('/dashboard'));
        // ...and setup paths are refused outright: the runner is the declared
        // web-process exception and must never answer a CLI-context call.
        self::assertFalse($runner->handle('/__setup/erp%2Fholiday'));
    }

    // ── source-pinned rails (doors CLI_MODE cannot enter) ──────────────────

    public function testRunnerRailsArePinned(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Setup/WizardRunner.php');

        self::assertStringContainsString("public const PATH_PREFIX = '__setup'", $source, 'the L3 302 target and the runner agree on ONE path');
        self::assertStringContainsString('if (CLI_MODE) {', $source);
        self::assertStringContainsString("!== 'wizard'", $source, 'only declared-wizard modules are reachable');
        self::assertStringContainsString("\$signer->redeem(\$verified['nonce']); // single-use: spent BEFORE any migration work", $source, 'no migration step is reachable with an unspent token');
        self::assertStringContainsString("ModuleDatabaseConnector::connect(\$module, \$moduleCode, 'wizard_runner')", $source, 'the SAME config-connect door as CLI — never a second engine');
        self::assertStringContainsString('$manager->migrate()', $source);
        self::assertStringContainsString("createEmitter('module.installed')", $source, 'Q3: the event fires where migrations ran');
        self::assertStringContainsString("'via' => 'wizard'", $source);
        self::assertStringContainsString('[Razy][wizard]', $source, 'every mint, spend, and refusal is audited');
    }

    public function testCliMintingDoorRailsArePinned(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/module.inc.php');

        self::assertStringContainsString("\$sub === 'wizard-token'", $source);
        self::assertStringContainsString('the wizard door is not open for it', $source, 'non-wizard provisions cannot receive tokens');
        self::assertStringContainsString('Ledger unreachable, refusing to mint', $source, 'fail-loud before handing out proof');
        self::assertStringContainsString('nothing pending — schema already deployed, no token minted', $source);
        self::assertStringContainsString('WizardRunner::audit($distCode', $source, 'the mint itself is audited');
        self::assertStringContainsString('WizardTokenSigner::DEFAULT_TTL', $source, 'the CLI prints the SAME window the signer enforces');
    }

    public function testInstalledEventFiresOnlyWhereMigrationsRan(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/migrate.inc.php');

        self::assertStringContainsString('if ($executed !== []) {', $source, 'an up-to-date pass fires NOTHING — installed means migrations ran');
        self::assertStringContainsString("createEmitter('module.installed')", $source);
        self::assertStringContainsString("'via' => 'cli'", $source);
    }

    public function testSetupPathIsInterceptedBeforeEveryDispatchChannel(): void
    {
        $source = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');

        self::assertStringContainsString('handleWizardRequest', $source);
        self::assertStringContainsString('WizardRunner::PATH_PREFIX', $source);

        // standard channel: intercepted BEFORE session and lifecycle
        $interceptAt = \strpos($source, '$this->handleWizardRequest($this->urlQuery)');
        $sessionAt = \strpos($source, '$this->setSession();');
        self::assertNotFalse($interceptAt);
        self::assertLessThan($sessionAt, $interceptAt, 'the setup door pre-dates session and route table');

        // worker channel carries its own intercept (dispatch fast path)
        self::assertSame(2, \substr_count($source, '$this->handleWizardRequest($this->urlQuery)'), 'matchRoute AND dispatch are both gated — one door per channel, no bypass');
    }

    public function testHelpSurfaceListsTheNewVerb(): void
    {
        $help = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/help.inc.php');
        self::assertStringContainsString('wizard-token', $help);

        $usage = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/module.inc.php');
        self::assertStringContainsString('module wizard-token <dist> <vendor/module>', $usage);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    private function cacheFake(): CacheInterface
    {
        /** @var array<string, string> $store */
        $store = [];

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static function (string $key) use (&$store) {
                return $store[$key] ?? null;
            },
        );
        $cache->method('set')->willReturnCallback(
            static function (string $key, $value) use (&$store): bool {
                $store[$key] = (string) $value;

                return true;
            },
        );
        $cache->method('delete')->willReturnCallback(
            static function (string $key) use (&$store): bool {
                unset($store[$key]);

                return true;
            },
        );

        return $cache;
    }
}
