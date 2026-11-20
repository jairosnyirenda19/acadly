<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Kernel\Identity\StudentAccounts;
use Acadly\Kernel\Support\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The student accounts of the college divisions (D-101), for the kernel's
 * socket and for the student portal: whose account it is, and the student
 * number a student signs in with (the admission number, or the learner code).
 */
final class StudentAccountDirectory implements StudentAccounts
{
    public function isStudent(string $userId): bool
    {
        return DB::table('programme_student_accounts')->where('user_id', $userId)->exists();
    }

    public function accountForNumber(string $number): ?string
    {
        $learner = self::learnerForNumber($number);
        $user = $learner === null ? null : DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id');

        return is_string($user) ? $user : null;
    }

    /** The learner a student number names, or null. */
    public static function learnerForNumber(string $number): ?string
    {
        $number = mb_strtoupper(trim($number));
        if ($number === '') {
            return null;
        }
        $id = DB::table('learners')->whereRaw('upper(acadly_id) = ?', [$number])->value('id')
            ?? DB::table('learner_identifiers')->where('kind', 'admission_number')->whereNull('retired_at')->whereRaw('upper(value) = ?', [$number])->value('learner_id');

        return is_string($id) ? $id : null;
    }

    /** The number a student signs in with: the admission number, or the learner code. */
    public static function numberOf(string $learnerId): string
    {
        $admission = DB::table('learner_identifiers')->where('learner_id', $learnerId)->where('kind', 'admission_number')->whereNull('retired_at')->value('value');

        $code = DB::table('learners')->where('id', $learnerId)->value('acadly_id');

        return is_string($admission) ? $admission : (is_string($code) ? $code : '');
    }

    /**
     * The student an account belongs to: the learner and their programme now.
     *
     * @return array{learner_id: string, student_id: string, division_id: string, programme_id: string, department_id: string, faculty_id: string, year_of_study: int}|null
     */
    public static function studentOf(string $userId): ?array
    {
        $row = DB::table('programme_student_accounts as a')
            ->join('programme_students as s', fn (JoinClause $j): JoinClause => $j->on('s.learner_id', '=', 'a.learner_id')->whereNull('s.ended_at'))
            ->join('programmes as p', 'p.id', '=', 's.programme_id')->join('programme_departments as d', 'd.id', '=', 'p.department_id')
            ->where('a.user_id', $userId)->whereNull('a.suspended_at')->orderByDesc('s.created_at')
            ->first(['a.learner_id', 's.id', 's.division_id', 's.programme_id', 'p.department_id', 'd.faculty_id', 's.year_of_study']);

        return $row === null ? null : ['learner_id' => Row::text($row, 'learner_id'), 'student_id' => Row::text($row, 'id'), 'division_id' => Row::text($row, 'division_id'),
            'programme_id' => Row::text($row, 'programme_id'), 'department_id' => Row::text($row, 'department_id'), 'faculty_id' => Row::text($row, 'faculty_id'), 'year_of_study' => Row::int($row, 'year_of_study')];
    }
}
