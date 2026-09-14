<?php

/**
 * razymod/permissions — main controller (S3: check surface wired onto the
 * S2 gates; S2 notes retained below).
 *
 * The two-tier API allow-list (Q4 decision) was LIVE from S2; S3 registers
 * the commands it describes — every registration maps 1:1 to a constant and
 * to a shipped handler (RZ-014: the matrix test in the repo suite already
 * pinned these names before they existed).
 *
 * Deviation from the house `return new class() extends Controller` shape
 * (queue-admin): the class is NAMED in support/controller.php so the api/
 * handler closures — bound to this controller at execution — can annotate
 * their $this honestly and phpstan can verify the module (deviation stated
 * in the dossier S3 row). The file still returns an instance, exactly like
 * the anonymous-class shape did.
 *
 * Config keys consumed (per-distributor, config/<dist>/permissions.php —
 * Module.php:1052-1056):
 *   - 'governor'      : module_code allowed to mutate roles (Q4). Absent =
 *                      every governance command denied. Never env-extendable
 *                      (config is the allow-list of record).
 *   - 'database'      : {type, connection} config-connect DB (§4.2 option 1,
 *                      support/database.php). NEVER the ambient-phantom
 *                      pattern (§4.4 lesson).
 *   - 'super_actors'  : actor keys allowed everything (§7.3), extended —
 *                      never replaced — by env RAZY_SUPER_ADMINS csv
 *   - 'system_actors' : CLI-only acting identities (§7.5), consulted ONLY
 *                      under CLI_MODE, first entry wins, never ambient on web
 *   - 'session_key'   : session slot holding the actor key "type:id"
 *                      (default '__auth_actor' — the module's own slot;
 *                      SessionGuard's '__auth_user_id' belongs to the app)
 *
 * Shape follows razymod/queue-admin; policy logic lives in support/Service.php.
 */

namespace Razy\Module\permissions;

require_once __DIR__ . '/support/controller.php';

return new PermissionController();
