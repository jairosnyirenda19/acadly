<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    /**
     * The schema is built once per run, as the owner role, in the test
     * database. Tests then run as the application role, as production does.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (self::$migrated) {
            return;
        }

        $this->assertTestDatabase();

        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);

        self::$migrated = true;
    }

    /** Refuse to rebuild anything that is not the dedicated test database. */
    private function assertTestDatabase(): void
    {
        $test = env('DB_TEST_DATABASE');
        $working = env('DB_DATABASE');

        foreach (['pgsql', 'pgsql_owner'] as $connection) {
            $database = config("database.connections.{$connection}.database");

            if (! is_string($test) || $test === '' || $database !== $test || $database === $working) {
                throw new RuntimeException('Tests may run only against the test database named in DB_TEST_DATABASE.');
            }
        }
    }
}
