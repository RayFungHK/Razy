<?php

/**
 * This file is part of Razy v1.1.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Setup;

use Razy\Cache;
use Razy\Database\MigrationManager;
use Razy\Database\ModuleDatabaseConnector;
use Razy\Distributor;
use Razy\Exception\DatabaseException;
use Razy\Exception\SetupException;
use Razy\Module;
use Razy\Security\Wizard\WizardTokenSigner;
use Razy\Util\PathUtil;
use Throwable;

/**
 * The framework-owned wizard runner (MODULE-LIFECYCLE.md L4, Q2-approved
 * exception to M3). Serves `/__setup/<vendor/module>` — the exact path the
 * L3 gate 302s to when a declared-`wizard` module is not ready.
 *
 * The rails, once, in one file:
 *  - ONLY modules declaring `provision => 'wizard'` are reachable here; the
 *    surface is the framework's, never a developer's (no registerInstall,
 *    no per-product wizard — the seeding 10% stays product code listening
 *    to `module.installed`);
 *  - migration execution requires a CLI-minted, dist+module-bound,
 *    single-use, 10-minute token (WizardTokenSigner, Q6 rails); the
 *    framework owns no user row and learns no accounts;
 *  - the ledger runs through the SAME doors as the CLI (ModuleDatabase-
 *    Connector + MigrationManager), so the wizard is a delivery channel,
 *    never a second migration engine;
 *  - `module.installed` fires HERE and only here (besides the CLI door) —
 *    where migrations actually ran (Q3);
 *  - every mint, spend, and refusal writes one audit line.
 */
final class WizardRunner
{
    /** reserved dist-root path segment — the L3 302 target */
    public const PATH_PREFIX = '__setup';

    private const ENV_SECRET = 'RAZY_WIZARD_TOKEN_SECRET';

    public function __construct(private readonly Distributor $distributor)
    {
    }

    /**
     * Build the signing surface from env + configured cache. Shared by the
     * CLI minting door and this runner — one secret, one nonce store.
     */
    public static function makeSigner(?int $ttl = null): WizardTokenSigner
    {
        $secret = (string) (self::envSecret() ?? '');

        $signer = $ttl === null
            ? new WizardTokenSigner($secret, Cache::getAdapter())
            : new WizardTokenSigner($secret, Cache::getAdapter(), $ttl);

        return $signer;
    }

    public static function envSecret(): ?string
    {
        $fromEnv = \getenv(self::ENV_SECRET);

        if (\is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $fromSuperglobal = $_ENV[self::ENV_SECRET] ?? $_SERVER[self::ENV_SECRET] ?? null;

        return \is_string($fromSuperglobal) && $fromSuperglobal !== '' ? $fromSuperglobal : null;
    }

    /**
     * Audit line, one per consequential event (Q6: "audit line in the
     * log"). error_log keeps the surface dependency-free: no module owns
     * the log, no module's logging config can silence the wizard.
     */
    public static function audit(string $dist, string $line): void
    {
        \error_log('[Razy][wizard][' . $dist . '] ' . $line);
    }

    /**
     * Answer a request whose path begins with the reserved prefix. Returns
     * false ONLY for non-setup paths (dispatch continues); a setup path is
     * owned outright from here — its 404 is this runner's, never the
     * framework's route-table miss.
     */
    public function handle(string $urlQuery): bool
    {
        $path = \trim($urlQuery, '/');

        if (!\str_starts_with($path, self::PATH_PREFIX . '/')) {
            return false;
        }

        // Belt and braces: the runner is a web-process exception and refuses
        // to exist under CLI no matter who wired what.
        if (CLI_MODE) {
            return false;
        }

        $segments = \explode('/', \substr($path, \strlen(self::PATH_PREFIX) + 1));
        $moduleCode = \rawurldecode(\trim($segments[0]));

        $this->respond($moduleCode);

        return true;
    }

    /**
     * Resolve the gated module: must exist and must have DECLARED the wizard
     * door. Anything else is not a negotiation — unknown codes 404 with no
     * existence hints beyond what the URL already claimed, and
     * deploy/none-provision modules get a named 403 pointing at the CLI.
     */
    private function resolveWizardModule(string $moduleCode): ?Module
    {
        if ($moduleCode === '') {
            $this->page(404, 'Not found', 'The setup path needs a module code: <code>/__setup/vendor/module</code>.');

            return null;
        }

        $module = $this->distributor->getRegistry()->get($moduleCode);

        if ($module === null) {
            $this->page(404, 'Not found', 'No module answers under this distributor.');

            return null;
        }

        if ($module->getModuleInfo()->getProvision() !== 'wizard') {
            $this->audit('provision-refusal', "'{$moduleCode}' is provisioned '" . $module->getModuleInfo()->getProvision() . "' — the wizard door is not open for it");

            $this->page(
                403,
                'Not a wizard-provisioned module',
                'Module <code>' . \htmlspecialchars($moduleCode, ENT_QUOTES, 'UTF-8') . '</code> does not declare <code>\'provision\' => \'wizard\'</code>. '
                . 'Its schema deploys through <code>php Razy.phar migrate &lt;dist&gt;</code> — that door never opens from the web.',
            );

            return null;
        }

        return $module;
    }

    private function respond(string $moduleCode): void
    {
        $method = \strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'GET' && $method !== 'POST') {
            \http_response_code(405);
            \header('Allow: GET, POST');

            return;
        }

        $module = $this->resolveWizardModule($moduleCode);

        if ($module === null) {
            return; // resolveWizardModule already answered
        }

        if ($method === 'GET') {
            $this->showForm($moduleCode);

            return;
        }

        $this->runMigration($module, $moduleCode);
    }

    /**
     * GET: a zero-DB posture page. Nothing about the ledger (not even that
     * a database exists) is revealed before the operator token is presented
     * — the form asks for proof of the shell first.
     */
    private function showForm(string $moduleCode): void
    {
        $safe = \htmlspecialchars($moduleCode, ENT_QUOTES, 'UTF-8');

        $this->page(
            200,
            'Setup — ' . $moduleCode,
            '<p>Module <code>' . $safe . '</code> declares the wizard provisioning door and is waiting for its schema.</p>'
            . '<p>An operator with shell access must first mint a single-use token:</p>'
            . '<pre>php Razy.phar module wizard-token ' . \htmlspecialchars($this->distributor->getCode(), ENT_QUOTES, 'UTF-8') . ' ' . $safe . '</pre>'
            // NO action attribute: a formless-action form posts to its own URL,
            // which is the only form target that survives every mount — a
            // hand-built '/__setup/...' absolute path lost the dist prefix on
            // subpath distributors and POSTed into a 404 (live web dogfood).
            . '<form method="post">'
            . '<label for="wizard_token">Setup token</label><br>'
            . '<input type="text" id="wizard_token" name="wizard_token" size="72" autocomplete="off">'
            . '<button type="submit">Run setup</button>'
            . '</form>'
            . '<p><small>The token is single-use, bound to this module and distributor, and expires after 10 minutes. '
            . 'Nothing here knows or creates accounts.</small></p>',
        );
    }

    /**
     * POST: verify, spend, migrate, announce, audit — in that order, with
     * no step reachable before its predecessor passed.
     */
    private function runMigration(Module $module, string $moduleCode): void
    {
        $token = (string) ($_POST['wizard_token'] ?? '');
        $dist = $this->distributor->getCode();

        try {
            $signer = self::makeSigner();
        } catch (SetupException $e) {
            $this->audit($dist, "token-unavailable for '{$moduleCode}': " . $e->getMessage());
            $this->page(500, 'Setup unavailable', \htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return;
        }

        if ($token === '') {
            $this->audit($dist, "empty-token attempt for '{$moduleCode}'");
            $this->page(403, 'Token required', 'The wizard door opens only with a minted token.');

            return;
        }

        try {
            $verified = $signer->verify($token, $dist, $moduleCode);
            $signer->redeem($verified['nonce']); // single-use: spent BEFORE any migration work
        } catch (SetupException $e) {
            $this->audit($dist, "token-refusal for '{$moduleCode}': " . $e->getMessage());
            $this->page(403, 'Token refused', \htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return;
        }

        $this->audit($dist, "token-spent for '{$moduleCode}' — migrations start");

        try {
            $db = ModuleDatabaseConnector::connect($module, $moduleCode, 'wizard_runner');
            $manager = new MigrationManager($db, $moduleCode);
            $manager->addPath(PathUtil::append($module->getModuleInfo()->getPath(), 'migration'));
            $applied = $manager->migrate();
        } catch (DatabaseException $e) {
            $this->audit($dist, "migration-db-failure for '{$moduleCode}': " . $e->getMessage());
            // The token is ALREADY spent by design: a retry needs a fresh
            // mint, so a leaked page can never re-run the door.
            $this->page(503, 'Setup failed', \htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return;
        } catch (Throwable $e) {
            $this->audit($dist, "migration-failure for '{$moduleCode}': " . $e->getMessage());
            $this->page(500, 'Setup failed', \htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));

            return;
        }

        $count = \count($applied);
        $this->audit($dist, "module '{$moduleCode}' migrated via wizard ({$count} applied)");

        // Q3: the event fires where migrations RAN — this door and the CLI
        // door, nowhere else. Peers seed their own data off this; schema
        // never moves in reaction to a peer.
        $module->createEmitter('module.installed')
            ->resolve(['module' => $moduleCode, 'version' => $module->getModuleInfo()->getVersion(), 'via' => 'wizard', 'applied' => $count]);

        $this->page(
            200,
            'Setup complete — ' . $moduleCode,
            '<p>Module <code>' . \htmlspecialchars($moduleCode, ENT_QUOTES, 'UTF-8') . '</code> is ready: '
            . $count . ' migration(s) applied. <code>module.installed</code> has fired.</p>'
            . '<p>Return to the module — its routes stop answering 503/302 from the next request onward.</p>',
        );
    }

    /**
     * Minimal framework page — deliberately not the module template system:
     * the wizard runs while half the platform may still be missing its
     * schema, and answers on its own feet.
     */
    private function page(int $status, string $title, string $bodyHtml): void
    {
        \http_response_code($status);
        \header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
            . \htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title></head><body style="font-family:system-ui,sans-serif;max-width:38rem;margin:4rem auto;padding:0 1rem">'
            . '<h1>' . \htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . $bodyHtml
            . '</body></html>';
    }
}
