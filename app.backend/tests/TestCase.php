<?php

namespace Tests;

use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Access\ScopeType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private static bool $migrated = false;

    /** @var array<string, string> account id => the place it works in, for this test */
    private array $places = [];

    private ?string $staffPlace = null;

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

        // The toy module's tables exist only in the test database.
        Artisan::call('migrate', ['--database' => 'pgsql_owner', '--path' => 'tests/Fixtures/Toy/Database/migrations', '--force' => true]);

        self::$migrated = true;
    }

    /**
     * Works in a place, as the console does (D-099): every request after this
     * names it in the place header. 'division', an id; or 'institution' for the school view.
     */
    public function inPlace(string $type, ?string $id = null, ?Authenticatable $for = null): static
    {
        $key = $type.':'.($id ?? '');

        if ($for === null) {
            return $this->withHeader(Boundary::HEADER, $key);
        }

        $id = $for->getAuthIdentifier();
        $this->places[is_scalar($id) ? (string) $id : ''] = $key;

        return $this;
    }

    /**
     * The place every person with a role for the whole school works in, in this
     * test, unless a place was chosen for them by name.
     */
    public function staffWorkIn(string $type, string $id): static
    {
        $this->staffPlace = $type.':'.$id;

        return $this;
    }

    /** Forgets the place chosen for a person: their requests name none (the browser's cookie, or the narrowest place of their roles). */
    public function noPlace(Authenticatable $for): static
    {
        $id = $for->getAuthIdentifier();
        unset($this->places[is_scalar($id) ? (string) $id : '']);
        unset($this->defaultHeaders[Boundary::HEADER]);

        return $this;
    }

    /** Acting as a person sends the place chosen for them, if any. */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($this->places !== [] || $this->staffPlace !== null) {
            $id = $user->getAuthIdentifier();
            $key = $this->places[is_scalar($id) ? (string) $id : ''] ?? null;
            if ($key === null && $this->staffPlace !== null
                && RoleAssignment::query()->where('user_id', $id)->where('scope_type', ScopeType::Institution->value)->exists()) {
                $key = $this->staffPlace;
            }
            if ($key !== null) {
                $this->withHeader(Boundary::HEADER, $key);
            } else {
                unset($this->defaultHeaders[Boundary::HEADER]);
            }
        }

        return parent::actingAs($user, $guard);
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
