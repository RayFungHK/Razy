<?php

/**
 * razymod/permissions — RBAC tables (dossier PERMISSION-MODULE.md §7.1).
 *
 * DDL is driver-branched raw SQL mirroring the framework's own table-creation
 * precedent (Queue/DatabaseStore.php:250-307): Table::getSyntax() emits
 * MySQL-only DDL (admitted at tests/MigrationTest.php:189-191), while this
 * module must migrate honestly on sqlite too — so up() speaks per-driver and
 * tests can actually run up/down end to end.
 *
 * Schema decisions (all dossier-pinned):
 * - NO dist_code column (§4.3: isolation = per-dist config + per-dist session
 *   cookie + app-chosen DB; every framework table is dist-column-free).
 * - actor_id is TEXT (§7.1: getAuthIdentifier() is string|int — int ids and
 *   UUID principals both fit; a pure text reference, NEVER an FK into some
 *   app-owned users table; Q1: framework/module never owns the user row).
 * - allow-list only, no deny rows (§7.1, consistent with every Razy gate).
 * - composite UNIQUEs stand in for the app-side idempotency of assign-role.
 * - actor_permission (Q5) is deliberately absent: zero code, zero schema
 *   until a named use case reopens it.
 */

use Razy\Database\Migration;
use Razy\Database\SchemaBuilder;

return new class() extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $db = $schema->getDatabase();
        $table = $db->getPrefix();
        $driver = $db->getDriverType() ?? 'sqlite';

        // permissions: the ability catalog — "define once" registry
        $sql = match ($driver) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$table}permissions` ("
                . '`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . '`code` VARCHAR(190) NOT NULL, '
                . '`label` VARCHAR(255) NULL, '
                . '`module_code` VARCHAR(190) NOT NULL, '
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'UNIQUE KEY `uq_permissions_code` (`code`), '
                . 'KEY `idx_permissions_module` (`module_code`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$table}permissions\" ("
                . '"id" BIGSERIAL PRIMARY KEY, '
                . '"code" VARCHAR(190) NOT NULL UNIQUE, '
                . '"label" VARCHAR(255) NULL, '
                . '"module_code" VARCHAR(190) NOT NULL, '
                . '"created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$table}permissions\" ("
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"code" TEXT NOT NULL UNIQUE, '
                . '"label" TEXT NULL, '
                . '"module_code" TEXT NOT NULL, '
                . '"created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')',
        };
        $schema->raw($sql);

        // roles: flat (no inheritance graphs — dossier do-NOT-build)
        $sql = match ($driver) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$table}roles` ("
                . '`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . '`code` VARCHAR(190) NOT NULL, '
                . '`label` VARCHAR(255) NULL, '
                . '`is_system` INT NOT NULL DEFAULT 0, '
                . 'UNIQUE KEY `uq_roles_code` (`code`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$table}roles\" ("
                . '"id" BIGSERIAL PRIMARY KEY, '
                . '"code" VARCHAR(190) NOT NULL UNIQUE, '
                . '"label" VARCHAR(255) NULL, '
                . '"is_system" INTEGER NOT NULL DEFAULT 0'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$table}roles\" ("
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"code" TEXT NOT NULL UNIQUE, '
                . '"label" TEXT NULL, '
                . '"is_system" INTEGER NOT NULL DEFAULT 0'
                . ')',
        };
        $schema->raw($sql);

        // permission_role: classic RBAC join, composite unique = idempotent grants
        $sql = match ($driver) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$table}permission_role` ("
                . '`role_id` INT NOT NULL, '
                . '`permission_id` INT NOT NULL, '
                . 'UNIQUE KEY `uq_permission_role` (`role_id`, `permission_id`), '
                . "CONSTRAINT `fk_pr_role` FOREIGN KEY (`role_id`) REFERENCES `{$table}roles` (`id`) ON DELETE CASCADE, "
                . "CONSTRAINT `fk_pr_permission` FOREIGN KEY (`permission_id`) REFERENCES `{$table}permissions` (`id`) ON DELETE CASCADE"
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$table}permission_role\" ("
                . "\"role_id\" BIGINT NOT NULL REFERENCES \"{$table}roles\" (\"id\") ON DELETE CASCADE, "
                . "\"permission_id\" BIGINT NOT NULL REFERENCES \"{$table}permissions\" (\"id\") ON DELETE CASCADE, "
                . 'UNIQUE ("role_id", "permission_id")'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$table}permission_role\" ("
                . "\"role_id\" INTEGER NOT NULL REFERENCES \"{$table}roles\" (\"id\") ON DELETE CASCADE, "
                . "\"permission_id\" INTEGER NOT NULL REFERENCES \"{$table}permissions\" (\"id\") ON DELETE CASCADE, "
                . 'UNIQUE ("role_id", "permission_id")'
                . ')',
        };
        $schema->raw($sql);

        // actor_role: actor_type reserves service/API actors (§7.5) without a
        // second table; actor_id TEXT on purpose (§7.1) — text reference only,
        // NO foreign key (the users table, if any, is app-owned — Q1).
        $sql = match ($driver) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$table}actor_role` ("
                . '`actor_type` VARCHAR(32) NOT NULL DEFAULT \'user\', '
                . '`actor_id` VARCHAR(190) NOT NULL, '
                . '`role_id` INT NOT NULL, '
                . 'UNIQUE KEY `uq_actor_role` (`actor_type`, `actor_id`, `role_id`), '
                . 'KEY `idx_actor` (`actor_type`, `actor_id`), '
                . "CONSTRAINT `fk_ar_role` FOREIGN KEY (`role_id`) REFERENCES `{$table}roles` (`id`) ON DELETE CASCADE"
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$table}actor_role\" ("
                . '"actor_type" VARCHAR(32) NOT NULL DEFAULT \'user\', '
                . '"actor_id" VARCHAR(190) NOT NULL, '
                . "\"role_id\" BIGINT NOT NULL REFERENCES \"{$table}roles\" (\"id\") ON DELETE CASCADE, "
                . 'UNIQUE ("actor_type", "actor_id", "role_id")'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$table}actor_role\" ("
                . '"actor_type" TEXT NOT NULL DEFAULT \'user\', '
                . '"actor_id" TEXT NOT NULL, '
                . "\"role_id\" INTEGER NOT NULL REFERENCES \"{$table}roles\" (\"id\") ON DELETE CASCADE, "
                . 'UNIQUE ("actor_type", "actor_id", "role_id")'
                . ')',
        };
        $schema->raw($sql);
    }

    public function down(SchemaBuilder $schema): void
    {
        // children first; dropIfExists quotes + prefixes per driver (SchemaBuilder.php:110-115)
        $schema->dropIfExists('actor_role');
        $schema->dropIfExists('permission_role');
        $schema->dropIfExists('roles');
        $schema->dropIfExists('permissions');
    }

    public function getDescription(): string
    {
        return 'Create the RBAC tables: permissions, roles, permission_role, actor_role (dossier §7.1)';
    }
};
