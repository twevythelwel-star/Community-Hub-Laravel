<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL rejects identifiers longer than 64 characters, while the SQLite test
 * database accepts them, so an over-long auto-generated index name passes
 * every other test and only fails on the production migrate. These tests run
 * the full migration history and fail on any name MySQL would refuse.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    private const MYSQL_MAX_IDENTIFIER_LENGTH = 64;

    public function test_no_migration_statement_uses_an_identifier_longer_than_mysql_allows(): void
    {
        $longIdentifiers = [];

        DB::listen(function (QueryExecuted $query) use (&$longIdentifiers): void {
            preg_match_all('/"([A-Za-z0-9_]+)"/', $query->sql, $matches);

            foreach ($matches[1] as $identifier) {
                if (strlen($identifier) > self::MYSQL_MAX_IDENTIFIER_LENGTH) {
                    $longIdentifiers[$identifier] = strlen($identifier);
                }
            }
        });

        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertSame([], $longIdentifiers, 'Give these indexes an explicit shorter name in their migration.');
    }

    public function test_no_foreign_key_name_is_longer_than_mysql_allows(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $longForeignKeys = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $name = strtolower($table.'_'.implode('_', $foreignKey['columns']).'_foreign');

                if (strlen($name) > self::MYSQL_MAX_IDENTIFIER_LENGTH) {
                    $longForeignKeys[] = $name;
                }
            }
        }

        $this->assertSame([], $longForeignKeys, 'Give these foreign keys an explicit shorter name in their migration.');
    }
}
