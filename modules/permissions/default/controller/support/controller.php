<?php

/**
 * razymod/permissions — named controller class (extracted from the house
 * `return new class() extends Controller` shape for one reason: api/ handler
 * closures are bound to this controller at execution (ClosureLoader), and a
 * NAMED class is what lets those handlers annotate their $this honestly and
 * let phpstan verify the whole module — the anonymous-class shape (the house
 * default, queue-admin included) cannot be referenced from a handler docblock.
 *
 * The controller file still returns an INSTANCE of this class exactly like
 * the anonymous shape returned one.
 */

namespace Razy\Module\permissions;

use Razy\Agent;
use Razy\Auth\AuthManager;
use Razy\Auth\CallbackGuard;
use Razy\Auth\Gate;
use Razy\Auth\GateFactory;
use Razy\Auth\GenericUser;
use Razy\Cache;
use Razy\Contract\AuthenticatableInterface;
use Razy\Controller;
use Razy\ModuleInfo;

class PermissionController extends Controller
{
    /**
     * Read/check commands: any module in the distributor may call them
     * (the razit loop consumer pattern — fail-closed happens at the
     * *decision*, not at the door).
     */
    private const API_ALLOW = [
        'can' => true,
        'can-any' => true,
        'abilities' => true,
        'define-ability' => true,
    ];

    /**
     * Governance commands: ONLY the config-named governor module (Q4).
     * audit-actor joined in S5 once its read side legitimately existed
     * (opt-in table via the second migration; the EVENT remains primary).
     */
    private const GOVERN_ONLY = [
        'roles-of' => true,
        'assign-role' => true,
        'revoke-role' => true,
        'audit-actor' => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // Public commands (RZ-010). Paths relative to controller/ (ClosureLoader.php:130).
        $agent->addAPICommand('can', 'api/can');
        $agent->addAPICommand('can-any', 'api/can_any');
        $agent->addAPICommand('abilities', 'api/abilities');
        $agent->addAPICommand('define-ability', 'api/define_ability');
        $agent->addAPICommand('roles-of', 'api/roles_of');
        $agent->addAPICommand('assign-role', 'api/assign_role');
        $agent->addAPICommand('revoke-role', 'api/revoke_role');
        $agent->addAPICommand('audit-actor', 'api/audit_actor');

        // S4: the module's Template plugins (plugins/Template/function.can.php)
        // — one registration, bound to THIS controller; consuming templates get
        // {can …} without copy-paste (production proof: razit-multilang ships
        // function.ml.php the same way, dossier §Templates row).
        $this->registerPluginLoader(self::PLUGIN_TEMPLATE);

        // S5 optional audit trail: the module listens to ITS OWN denial event
        // (the emitter-qualified name it fires, §8) and persists rows when
        // the `audit` config says so. Default off = event-only doctrine
        // intact, table stays empty. Registration here is inert (RZ-009).
        if (!empty($this->getModuleConfig()['audit'])) {
            $agent->listen('razymod/permissions:permission.denied', function (array $payload = []): array {
                $this->service()->auditLog(
                    (string) ($payload['actor_key'] ?? ''),
                    (string) ($payload['ability'] ?? ''),
                    (string) ($payload['source'] ?? 'gate'),
                );

                return ['audited' => true];
            });
        }

        return true;
    }

    /**
     * Gate for api('razymod/permissions')->… (RZ-010). Framework default
     * allows ALL — implemented allow-list denies the unknown (AGENTS.md trap).
     * $module is the REQUESTING module's ModuleInfo (Controller.php:173).
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        if (isset(self::API_ALLOW[$method])) {
            return true;
        }

        if (isset(self::GOVERN_ONLY[$method])) {
            // Absent/empty governor config must NEVER match: a bare null
            // comparison against the caller code would let a module literally
            // named '' through. Read API is ArrayAccess — Configuration
            // extends Collection which extends ArrayObject (Collection.php:31);
            // there is NO ->get() method despite manual/04:50 advertising one
            // (ledger P8 — the same phantom class as getSharedInstance).
            $governor = $this->getModuleConfig()['governor'] ?? null;

            return \is_string($governor) && $governor !== '' && $module->getCode() === $governor;
        }

        return false;
    }

    /**
     * Fresh policy service per call — by construction NO state crosses a
     * request boundary (the §7.6 worker-mode problem declined rather than
     * half-cached; cross-request memo is S5 with invalidation-on-write).
     * Handlers ($this = this controller) build their service through here.
     */
    public function service(): Service
    {
        $config = $this->getModuleConfig();
        // sibling file — this controller LIVES in support/ (S3 shipped this
        // require with a support/support/ path; never executed until the S4
        // template tests drove controller->service() for the first time)
        $resolver = require __DIR__ . '/database.php';

        // S5: Cache::getAdapter() is NullAdapter before initialize()
        // (Cache.php:117-118) — a no-op store, so cache_ttl simply does
        // nothing until the distributor really has a cache (fail-closed).
        return new Service(
            $resolver($config['database'] ?? null),
            $config->array(),
            \defined('CLI_MODE') && CLI_MODE,
            Cache::getAdapter(),
        );
    }

    /**
     * The one Gate per distributor on the S1 seam: DB-answering, LAZY on
     * first use — deliberately NOT built in __onReady, because boot-time DB
     * IO per request is exactly the cost pattern this platform was called
     * out for (dossier wording "registration at boot" deviation, stated).
     *
     * The before hook is DECISIVE (always bool), making the DB the single
     * policy source behind Gate's guest pre-deny (Gate.php:173); "no answer"
     * would fall through to ability closures that don't exist. Super-actor
     * semantics ride Service::decide (config-first, guest never super).
     *
     * Worker mode: the Gate memoizes process-wide (GateFactory), but the
     * hook late-binds service() — a fresh Database + fresh config per
     * decision — so no stale connection is ever captured. Denials reached
     * THROUGH this gate fire permission.denied (§8: audit on the gated path
     * only; the razit-loop's plain api()->can() reads stay silent).
     */
    public function gate(): Gate
    {
        return GateFactory::make(
            'razymod/permissions@' . $this->getModuleInfo()->getPath(),
            new AuthManager(
                ['actor' => new CallbackGuard(fn (): ?AuthenticatableInterface => $this->sessionActor())],
                'actor',
            ),
            function (Gate $gate): void {
                $gate->addBefore(function (AuthenticatableInterface $user, string $ability): bool {
                    $decision = $this->service()->decide($ability, (string) $user->getAuthIdentifier());

                    if (!$decision['allow'] && $decision['via'] === 'db' && $decision['actor'] !== null) {
                        // §8 audit posture: event, not a table — auditors subscribe
                        // via listen (Agent.php:166); never on the direct can() hot path.
                        $this->trigger('permission.denied')->resolve([
                            'actor_key' => $decision['actor'],
                            'ability' => $ability,
                            'source' => 'gate',
                        ]);
                    }

                    return $decision['allow'];
                });
            },
        );
    }

    /**
     * Session-actor check for the {can} template plugin (S4): same decision
     * grammar as api()->can() (guest > super > db), same never-throw
     * hot-path contract (Service.php:146) — a template render can therefore
     * never be broken by policy lookups, and every failure denies.
     *
     * @param list<string> $abilities
     */
    public function canAbilities(array $abilities): bool
    {
        return $this->service()->canAny($abilities);
    }

    /**
     * Session actor as an Authenticatable (identifier IS the actor key
     * "type:id"); null = guest (Gate pre-denies; super never applies to
     * unauthenticated requests, §7.3).
     */
    public function sessionActor(): ?AuthenticatableInterface
    {
        $key = $this->service()->actorKey();

        return $key === null ? null : new GenericUser(['id' => $key]);
    }
}
