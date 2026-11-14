<?php

declare(strict_types=1);

namespace Acadly\Kernel\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes a table append-only: rows can be added and read, never changed or
 * removed (docs/architecture/05-data-and-api.md §3, rule E2).
 *
 * Two protections, so one mistake does not open the table:
 *  - the application role loses UPDATE, DELETE and TRUNCATE on the table;
 *  - a trigger refuses the same statements for every role, the owner included.
 *
 * Call it in the migration that creates the table.
 */
final class AppendOnly
{
    public const string APP_ROLE = 'acadly_app';

    public static function protect(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("[{$table}] is not a valid table name.");
        }

        $role = self::APP_ROLE;

        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$table} FROM {$role}");
        DB::statement(
            "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
            .'FOR EACH ROW EXECUTE FUNCTION acadly_reject_change()'
        );
        DB::statement(
            "CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} "
            .'FOR EACH STATEMENT EXECUTE FUNCTION acadly_reject_change()'
        );
    }
}
