<?php

/**
 * razymod/permissions — optional audit log (S5).
 *
 * The dossier's §8 doctrine stays: audit PRIMARILY is the
 * `permission.denied` EVENT (auditors subscribe, zero storage). This table
 * exists for the one thing an event cannot do — answer "what did this
 * actor recently get denied?" AFTER the fact — and only for distributors
 * that opt in (`audit` config, default off). When off, nothing ever writes
 * here and the table sits empty.
 *
 * Rows are inserted by the module's own listener on its own denial event
 * (gated denials only — the razit-loop's plain can() reads stay silent, §8);
 * retention is an ops concern (a cron `DELETE ... WHERE created_at < …`
 * through the app's own tooling), not this migration's.
 */

use Razy\Database\Migration;
use Razy\Database\SchemaBuilder;

return new class() extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $db = $schema->getDatabase();
        $table = $db->getPrefix();
        $driver = $db->getDriverType() ?? 'sqlite';

        $sql = match ($driver) {
            'mysql', 'mariadb' => "CREATE TABLE IF NOT EXISTS `{$table}permission_audit_log` ("
                . '`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . '`actor_key` VARCHAR(190) NOT NULL, '
                . '`ability` VARCHAR(190) NOT NULL, '
                . '`source` VARCHAR(32) NOT NULL DEFAULT \'gate\', '
                . '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'KEY `idx_audit_actor` (`actor_key`, `id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS \"{$table}permission_audit_log\" ("
                . '"id" BIGSERIAL PRIMARY KEY, '
                . '"actor_key" VARCHAR(190) NOT NULL, '
                . '"ability" VARCHAR(190) NOT NULL, '
                . '"source" VARCHAR(32) NOT NULL DEFAULT \'gate\', '
                . '"created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS \"{$table}permission_audit_log\" ("
                . '"id" INTEGER PRIMARY KEY AUTOINCREMENT, '
                . '"actor_key" VARCHAR(190) NOT NULL, '
                . '"ability" VARCHAR(190) NOT NULL, '
                . '"source" TEXT NOT NULL DEFAULT \'gate\', '
                . '"created_at" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')',
        };
        $schema->raw($sql);

        if ('mysql' !== $driver && 'mariadb' !== $driver) {
            // the read pattern is "one actor, newest first" (audit-actor API)
            $schema->raw("CREATE INDEX IF NOT EXISTS \"idx_{$table}permission_audit_log_actor\" ON \"{$table}permission_audit_log\" (\"actor_key\", \"id\")");
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->dropIfExists('permission_audit_log');
    }

    public function getDescription(): string
    {
        return 'Create the opt-in denial audit log: permission_audit_log (S5; the audit EVENT remains primary)';
    }
};
