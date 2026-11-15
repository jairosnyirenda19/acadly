<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Journey;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Guards the journey columns of a subject table (02 §3.5, rule C2).
 *
 * A trigger refuses any UPDATE that changes the state column or the pinned
 * journey ref, unless the journey engine has set the transaction flag
 * `acadly.journey_commit`. There is one path to change a state.
 *
 * Call it in the migration that creates the table.
 */
final class JourneyColumns
{
    public const string FLAG = 'acadly.journey_commit';

    public static function protect(string $table, string $stateColumn = 'state', string $refColumn = 'journey_ref'): void
    {
        foreach ([$table, $stateColumn, $refColumn] as $name) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw new InvalidArgumentException("[{$name}] is not a valid table or column name.");
            }
        }

        DB::statement(
            "CREATE TRIGGER {$table}_journey_columns BEFORE UPDATE ON {$table} "
            ."FOR EACH ROW EXECUTE FUNCTION acadly_guard_journey_columns('{$stateColumn}', '{$refColumn}')"
        );
    }
}
