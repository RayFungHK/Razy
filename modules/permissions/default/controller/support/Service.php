<?php

/**
 * razymod/permissions — policy service (S3 check surface).
 *
 * Own-module support class (queue-admin's QueueAdminService precedent): the
 * handlers are thin; ALL decision/mutation logic lives here, unit-testable
 * against a plain Database with no module runtime.
 *
 * Decision grammar (dossier §7.3/§7.4/§7.5, Q4/Q5 pinned):
 *   guest (no actor key)              -> deny, before anything else
 *   actor key in super_actors (config + env EXTENSION) -> allow
 *   else membership in the actor's DB-joined ability set -> allow/deny
 * There are no deny rows anywhere (allow-list-only, consistent with every
 * Razy gate) and no direct grants (Q5: schema not even created).
 *
 * can()/canAny() NEVER throw (hot path contract, §8): unreachable DB or any
 * internal failure collapses to deny (fail-closed), never to allow.
 *
 * ALL reads/writes go through the RZ-003 ORM surface (prepare()->select()
 * ->from('table')->where('a=:a,b=:b')->assign(), Database::insert/delete) —
 * from()/insert()/delete() auto-apply the Database prefix
 * (TableJoinSyntax.php:136, Statement.php:572/:835), so NO hand-prefixed
 * table names belong here. Raw prepare($sql)->assign() is deliberately NOT
 * used: assign() only feeds the ORM syntax builders (Statement.php:205-217),
 * it does not bind raw SQL placeholders — discovered the hard way the first
 * time these queries actually ran.
 *
 * No join DSL exists on Statement today, so the two governance-scale mapping
 * reads (permission_role, permissions) fetch small whole tables and filter
 * in PHP — bounded by the governance scale of these tables (tens-to-hundreds
 * of rows), memoized per instance. If that bound is ever challenged, the
 * join belongs in the framework, not in interpolated SQL (RZ-003).
 *
 * Memo (§7.6): per-actor ability sets live on THIS instance only — one
 * Service per call means memo = intra-call batching (can-any). Cross-request
 * caching is S5 (Razy\Cache + invalidation-on-write mandatory first); until
 * then no ambient state crosses a request boundary by construction.
 */

namespace Razy\Module\permissions;

use Razy\Database;
use Razy\Env;
use Throwable;

final class Service
{
    /** Per-actor ability sets: actor_key => [code => true] */
    private array $actorMemo = [];

    /** @var array<string, string>|null permission id-string => code */
    private ?array $permissionMemo = null;

    /** @var array<string, string>|null role code => id-string */
    private ?array $roleMemo = null;

    /** @var list<string>|null */
    private ?array $catalogMemo = null;

    /**
     * @param array<string, mixed> $config module config slice: super_actors, system_actors, session_key
     * @param bool $cli CLI_MODE posture (§7.5): no session exists; system_actors only ever consulted here
     */
    public function __construct(
        private readonly ?Database $db,
        private readonly array $config = [],
        private readonly bool $cli = false,
    ) {
    }

    // ── identity ──────────────────────────────────────────────────

    /**
     * Resolve the acting actor key ("type:id"), never ambiently on web:
     * explicit argument > declared session key > (CLI only) system_actors head.
     */
    public function actorKey(?string $explicit = null): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        if (!$this->cli) {
            $key = $this->config['session_key'] ?? '__auth_actor';
            $raw = isset($_SESSION) ? ($_SESSION[$key] ?? null) : null;

            if (\is_string($raw) && $raw !== '') {
                return $raw;
            }

            if (\is_int($raw)) {
                return 'user:' . $raw;
            }

            return null;
        }

        // §7.5: CLI has NO session; only an explicitly configured system
        // actor acts, and only the first entry (one service account).
        $system = $this->config['system_actors'] ?? [];

        if (\is_array($system)) {
            $first = \reset($system);

            if (\is_string($first) && $first !== '') {
                return $first;
            }
        }

        return null;
    }

    /**
     * Super actors: config list, EXTENDED (never replaced) by
     * RAZY_SUPER_ADMINS csv (BridgeSignature env-gate precedent, §7.3).
     */
    public function isSuper(string $actorKey): bool
    {
        $list = \is_array($this->config['super_actors'] ?? null) ? $this->config['super_actors'] : [];
        $env = Env::get('RAZY_SUPER_ADMINS');

        if (\is_string($env) && $env !== '') {
            $list = \array_merge($list, \array_map('trim', \explode(',', $env)));
        }

        return \in_array($actorKey, $list, true);
    }

    // ── checks (never throw) ──────────────────────────────────────

    public function can(string $ability, ?string $actorId = null): bool
    {
        try {
            $key = $this->actorKey($actorId);

            if ($key === null || $ability === '') {
                return false; // guest pre-deny (§7.3: super never applies to guests)
            }

            if ($this->isSuper($key)) {
                return true;
            }

            return isset($this->abilitiesFor($key)[$ability]);
        } catch (Throwable) {
            return false; // hot-path contract: DB unreachable = deny, never throw, never allow
        }
    }

    /**
     * @param list<string> $abilities
     */
    public function canAny(array $abilities, ?string $actorId = null): bool
    {
        foreach ($abilities as $ability) {
            if ($this->can((string) $ability, $actorId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decision as data (for the Gate before-hook and tests): distinguishes
     * WHY a check resolved, so audit can fire on gated db-denials only.
     *
     * @return array{allow: bool, actor: ?string, via: 'guest'|'super'|'db'|'error'}
     */
    public function decide(string $ability, ?string $actorId = null): array
    {
        try {
            $key = $this->actorKey($actorId);

            if ($key === null || $ability === '') {
                return ['allow' => false, 'actor' => null, 'via' => 'guest'];
            }

            if ($this->isSuper($key)) {
                return ['allow' => true, 'actor' => $key, 'via' => 'super'];
            }

            return ['allow' => isset($this->abilitiesFor($key)[$ability]), 'actor' => $key, 'via' => 'db'];
        } catch (Throwable) {
            return ['allow' => false, 'actor' => null, 'via' => 'error'];
        }
    }

    // ── catalog & governance reads ────────────────────────────────

    /**
     * All catalog codes (advisory registry for UI/menus; the DECISION answers
     * from the actor-role-permission join, so an undefined-but-granted code
     * still grants — the catalog is the "define once" surface, §7.1).
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        if ($this->catalogMemo !== null) {
            return $this->catalogMemo;
        }

        $codes = \array_map(
            static fn (array $r): string => (string) $r['code'],
            $this->rows('code', 'permissions', '', [], '<code'),
        );

        $this->catalogMemo = $codes;

        return $codes;
    }

    /**
     * @return list<string> role codes granted to one actor
     */
    public function rolesOf(string $actorType, string $actorId): array
    {
        if ($this->db === null) {
            return [];
        }

        $roles = \array_flip($this->roleMemo()); // roleMemo is code=>id; lookup wants id=>code
        $out = [];

        foreach ($this->grantRows($actorType, $actorId) as $grant) {
            $code = $roles[(string) $grant['role_id']] ?? null;

            if ($code !== null) {
                $out[$code] = true;
            }
        }

        return \array_keys($out);
    }

    // ── mutations (allow-listed upstream to the governor, Q4) ─────

    /**
     * Idempotent catalog insert. Code grammar §7.4: flat dotted
     * '<ns>.<action>' (collision-auditable).
     *
     * @return array{ok: bool, created?: bool, error?: string}
     */
    public function defineAbility(string $code, string $label = '', string $moduleCode = 'razymod/permissions'): array
    {
        if (\preg_match('/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+$/', $code) !== 1) {
            return ['ok' => false, 'error' => "invalid ability code '{$code}' (expected flat dotted '<ns>.<action>', §7.4)"];
        }

        if ($this->db === null) {
            return ['ok' => false, 'error' => 'no database connection available'];
        }

        $existing = $this->rows('id', 'permissions', 'code=:c', ['c' => $code]);

        if ($existing !== []) {
            return ['ok' => true, 'created' => false];
        }

        $this->db->execute($this->db->insert('permissions', ['code', 'label', 'module_code'])->assign([
            'code' => $code,
            'label' => $label === '' ? null : $label,
            'module_code' => $moduleCode,
        ]));
        $this->catalogMemo = null;
        $this->permissionMemo = null;

        return ['ok' => true, 'created' => true];
    }

    /**
     * @return array{ok: bool, created?: bool, error?: string}
     */
    public function assignRole(string $actorType, string $actorId, string $roleCode): array
    {
        if ($this->db === null) {
            return ['ok' => false, 'error' => 'no database connection available'];
        }

        $roleId = $this->roleMemo()[$roleCode] ?? null; // code=>id whole-table memo

        if ($roleId === null) {
            // no silent role creation: codes are governance vocabulary, invented
            // by explicit UI/governor action, never as a side effect of a grant
            return ['ok' => false, 'error' => "unknown role '{$roleCode}'"];
        }

        $dup = $this->rows('role_id', 'actor_role', 'actor_type=:t,actor_id=:a,role_id=:r', ['t' => $actorType, 'a' => $actorId, 'r' => (int) $roleId]);

        if ($dup === []) {
            $this->db->execute($this->db->insert('actor_role', ['actor_type', 'actor_id', 'role_id'])->assign([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'role_id' => (int) $roleId,
            ]));
            $this->actorMemo = []; // write-time invalidation (§7.6)
        }

        return ['ok' => true, 'created' => $dup === []];
    }

    /**
     * @return array{ok: bool, revoked?: int, error?: string}
     */
    public function revokeRole(string $actorType, string $actorId, string $roleCode): array
    {
        if ($this->db === null) {
            return ['ok' => false, 'error' => 'no database connection available'];
        }

        $roleId = $this->roleMemo()[$roleCode] ?? null;

        if ($roleId === null) {
            return ['ok' => false, 'error' => "unknown role '{$roleCode}'"];
        }

        $query = $this->db->execute(
            // Statement::delete carries parameters + where syntax in its own
            // signature (Statement.php:822); a chained ->where() after
            // delete() does not build the syntax the DeleteSyntaxBuilder reads.
            $this->db->delete('actor_role', ['t' => $actorType, 'a' => $actorId, 'r' => (int) $roleId], 'actor_type=:t,actor_id=:a,role_id=:r'),
        );
        $this->actorMemo = [];

        return ['ok' => true, 'revoked' => $query->affected()]; // Query exposes affected(), no rowCount()
    }

    // ── internals ─────────────────────────────────────────────────

    /**
     * @return array<string, true> ability code => true, memoized per actor
     */
    private function abilitiesFor(string $actorKey): array
    {
        if (isset($this->actorMemo[$actorKey])) {
            return $this->actorMemo[$actorKey];
        }

        $set = [];

        if ($this->db !== null) {
            $split = \explode(':', $actorKey, 2);

            if (\count($split) === 2 && $split[0] !== '' && $split[1] !== '') {
                $grantedRoleIds = [];

                foreach ($this->grantRows($split[0], $split[1]) as $grant) {
                    $grantedRoleIds[(string) $grant['role_id']] = true;
                }

                if ($grantedRoleIds !== []) {
                    $permissions = $this->permissionMemo();

                    foreach ($this->rows('role_id,permission_id', 'permission_role') as $link) {
                        if (isset($grantedRoleIds[(string) $link['role_id']])) {
                            $code = $permissions[(string) $link['permission_id']] ?? null;

                            if ($code !== null) {
                                $set[$code] = true;
                            }
                        }
                    }
                }
            }
        }

        $this->actorMemo[$actorKey] = $set;

        return $set;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grantRows(string $actorType, string $actorId): array
    {
        return $this->rows('role_id', 'actor_role', 'actor_type=:t,actor_id=:a', ['t' => $actorType, 'a' => $actorId]);
    }

    /**
     * @return array<string, string> permission id => code (whole-table memo; governance scale)
     */
    private function permissionMemo(): array
    {
        if ($this->permissionMemo === null) {
            $map = [];

            foreach ($this->rows('id,code', 'permissions') as $row) {
                $map[(string) $row['id']] = (string) $row['code'];
            }

            $this->permissionMemo = $map;
        }

        /** @var array<string, string> */
        return $this->permissionMemo;
    }

    /**
     * @return array<string, string> role code => id-string (whole-table memo; governance scale)
     */
    private function roleMemo(): array
    {
        if ($this->roleMemo === null) {
            $map = [];

            foreach ($this->rows('id,code', 'roles') as $row) {
                $map[(string) $row['code']] = (string) $row['id'];
            }

            $this->roleMemo = $map;
        }

        /** @var array<string, string> */
        return $this->roleMemo;
    }

    /**
     * The ONLY SQL read path: ORM builder, auto-prefixed, named params only
     * (RZ-003). $where '' omits the clause.
     *
     * @param array<string, scalar|null> $assign
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $projection, string $table, string $where = '', array $assign = [], string $order = ''): array
    {
        if ($this->db === null) {
            return [];
        }

        $statement = $this->db->prepare()->select($projection)->from($table);

        if ($where !== '') {
            $statement = $statement->where($where);
        }

        if ($assign !== []) {
            $statement = $statement->assign($assign);
        }

        if ($order !== '') {
            $statement = $statement->order($order);
        }

        return $this->db->execute($statement)->fetchAll();
    }
}
