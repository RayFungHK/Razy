<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Abstract base class for database migrations.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Database;

/**
 * Abstract base class for database migrations.
 *
 * Each migration represents a discrete schema change. Extend this class and
 * implement the up() and down() methods. Migrations are discovered by the
 * MigrationManager from files that return instances of this class.
 *
 * Migration file naming convention:
 *   YYYY_MM_DD_HHMMSS_DescriptionName.php
 *
 * Example migration file:
 *   <?php
 *   use Razy\Database\Migration;
 *   use Razy\Database\SchemaBuilder;
 *
 *   return new class extends Migration {
 *       public function up(SchemaBuilder $schema): void {
 *           $schema->raw('CREATE TABLE users (
 *               id INTEGER PRIMARY KEY AUTOINCREMENT,
 *               name VARCHAR(100) NOT NULL,
 *               email VARCHAR(255) NOT NULL UNIQUE
 *           )');
 *       }
 *
 *       public function down(SchemaBuilder $schema): void {
 *           $schema->dropIfExists('users');
 *       }
 *
 *       public function getDescription(): string {
 *           return 'Create the users table';
 *       }
 *   };
 */
abstract class Migration
{
    /**
     * Run the migration (apply schema changes).
     *
     * @param SchemaBuilder $schema The schema builder for executing DDL
     */
    abstract public function up(SchemaBuilder $schema): void;

    /**
     * Reverse the migration (undo schema changes).
     *
     * @param SchemaBuilder $schema The schema builder for executing DDL
     */
    abstract public function down(SchemaBuilder $schema): void;

    /**
     * Get a human-readable description of this migration.
     *
     * Override this in concrete migrations to provide context about
     * what the migration does.
     *
     * @return string Migration description (empty string if not overridden)
     */
    public function getDescription(): string
    {
        return '';
    }
}
