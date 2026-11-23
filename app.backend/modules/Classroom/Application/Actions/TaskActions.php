<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Actions\StoreDocument;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Courses;
use Acadly\Modules\Classroom\Contracts\ClassroomAudit;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Setting assignments and exam papers (D-104). An assignment takes late
 * hand-ins until its late day (7 days after the deadline unless the lecturer
 * changes it); an exam paper shows at its start, closes at its end and takes
 * nothing late. The weights of a course add up to at most its share for
 * continuous assessment. A task whose marks are released is not changed.
 *
 * @phpstan-type TaskInput array{id: string|null, kind: string, title: string, instructions: string, shows_at: string, due_at: string, late_until: string|null, out_of: int, weight: float, accepts: list<string>, max_files: int, max_mb: int}
 */
final class TaskActions
{
    /** The kinds of file a lecturer may choose, by the name the screen gives. */
    public const array FILE_TYPES = [
        'pdf' => ['pdf'],
        'word' => ['word', 'word_old'],
        'excel' => ['excel', 'excel_old'],
        'powerpoint' => ['powerpoint', 'powerpoint_old'],
        'images' => ['jpg', 'png'],
    ];

    public const int LATE_DAYS = 7;

    public const int MAX_FILES = 5;

    public function __construct(
        private readonly Courses $courses,
        private readonly StoreDocument $store,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  TaskInput  $input */
    public function save(User $by, string $divisionId, string $offeringId, array $input): string
    {
        $course = $this->courses->run($by, $divisionId, $offeringId);
        $old = null;
        if ($input['id'] !== null) {
            $old = DB::table('classroom_tasks')->where('id', $input['id'])->where('offering_id', $offeringId)->whereNull('removed_at')->first() ?? throw new NotFoundHttpException;
            if (($old->released_at ?? null) !== null) {
                throw ValidationException::withMessages(['title' => ['The marks are released; this can no longer be changed.']]);
            }
        }
        $errors = [];
        $kind = $input['kind'] === 'exam' ? 'exam' : 'assignment';
        $title = trim($input['title']);
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = ['Give a title of up to 160 characters.'];
        }
        if (mb_strlen($input['instructions']) > 20000) {
            $errors['instructions'] = ['The instructions are too long.'];
        }
        $zone = config()->string('acadly.timezone', 'UTC');
        $shows = self::moment($input['shows_at'], $zone);
        $due = self::moment($input['due_at'], $zone);
        if ($shows === null || $due === null || $due->lessThanOrEqualTo($shows)) {
            $errors['due_at'] = ['Give when it shows and the deadline, the deadline after it shows.'];
        }
        $lateUntil = null;
        if ($kind === 'assignment' && $due !== null) {
            $lateUntil = $input['late_until'] !== null ? self::moment($input['late_until'], $zone) : $due->addDays(self::LATE_DAYS);
            if ($lateUntil === null || $lateUntil->lessThan($due)) {
                $errors['late_until'] = ['Late hand-ins end on or after the deadline.'];
            }
        }
        if ($input['out_of'] < 1 || $input['out_of'] > 1000) {
            $errors['out_of'] = ['Give the mark it is out of, from 1 to 1,000.'];
        }
        $share = CourseActions::share($offeringId);
        $others = (float) DB::table('classroom_tasks')->where('offering_id', $offeringId)->whereNull('removed_at')->where('id', '<>', $input['id'] ?? '00000000-0000-0000-0000-000000000000')->sum('weight');
        if ($input['weight'] < 0 || $others + $input['weight'] > $share + 0.001) {
            $errors['weight'] = ['The weights of this course would add up to more than its '.$share.'% for continuous assessment.'];
        }
        $types = [];
        foreach ($input['accepts'] as $t) {
            foreach (self::FILE_TYPES[$t] ?? [] as $key) {
                $types[] = ClassroomDocument::TYPES[$key];
            }
        }
        if ($types === []) {
            $errors['accepts'] = ['Choose at least one kind of file.'];
        }
        if ($input['max_files'] < 1 || $input['max_files'] > self::MAX_FILES) {
            $errors['max_files'] = ['From 1 to '.self::MAX_FILES.' files.'];
        }
        if ($input['max_mb'] < 1 || $input['max_mb'] > ClassroomDocument::MAX_MB) {
            $errors['max_mb'] = ['From 1 to '.ClassroomDocument::MAX_MB.' MB.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $id = $input['id'] ?? Ids::next();
        $now = $this->clock->now();
        $row = ['kind' => $kind, 'title' => $title, 'instructions' => trim($input['instructions']), 'shows_at' => $shows, 'due_at' => $due, 'late_until' => $lateUntil,
            'out_of' => $input['out_of'], 'weight' => round($input['weight'], 2), 'accepts' => json_encode(array_values(array_unique($types))), 'max_files' => $input['max_files'],
            'max_mb' => $input['max_mb'], 'updated_at' => $now];

        DB::transaction(function () use ($by, $course, $id, $row, $old, $now): void {
            if ($old === null) {
                DB::table('classroom_tasks')->insert([...$row, 'id' => $id, 'division_id' => $course['division_id'], 'period_id' => $course['period']['id'],
                    'offering_id' => $course['offering']['id'], 'created_by' => $by->id, 'created_at' => $now]);
            } else {
                DB::table('classroom_tasks')->where('id', $id)->update($row);
            }
            $this->audit->record(ClassroomAudit::TaskSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $course['division_id']), meta: ['task' => $id]);
        });

        return $id;
    }

    /** Adds a file for the students to a task. */
    public function addFile(User $by, string $divisionId, string $taskId, UploadedFile $file): string
    {
        $task = DB::table('classroom_tasks')->where('id', $taskId)->where('division_id', $divisionId)->whereNull('removed_at')->first() ?? throw new NotFoundHttpException;
        $this->courses->run($by, $divisionId, Row::text($task, 'offering_id'));

        return $this->store->handle($by, ClassroomDocument::Brief, $taskId, $file)->id;
    }

    private static function moment(?string $value, string $zone): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, $zone)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
