<?php

declare(strict_types=1);

namespace Acadly\Kernel\Operations;

use Illuminate\Support\Facades\DB;

/**
 * The application must run as a role that cannot change the schema (rule 7).
 * It is safe when it may not create in the schema and owns none of its tables.
 */
final class DatabaseRole
{
    public static function safe(): bool
    {
        $row = DB::selectOne(<<<'SQL'
            SELECT has_schema_privilege(current_user, current_schema(), 'CREATE') AS can_create,
                   EXISTS (SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tableowner = current_user) AS owns_tables
            SQL);

        $answer = is_object($row) ? get_object_vars($row) : [];

        return ($answer['can_create'] ?? true) === false && ($answer['owns_tables'] ?? true) === false;
    }
}
