<?php

use Razy\Database\Migration;
use Razy\Database\SchemaBuilder;

return new class extends Migration {
    public function up(SchemaBuilder $schema): void
    {
        $schema->raw('CREATE TABLE IF NOT EXISTS bench_log_gate_ready (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            note VARCHAR(191) NOT NULL
        )');
    }

    public function down(SchemaBuilder $schema): void
    {
        $schema->raw('DROP TABLE IF EXISTS bench_log_gate_ready');
    }

    public function getDescription(): string
    {
        return 'benchmark gate log table';
    }
};
