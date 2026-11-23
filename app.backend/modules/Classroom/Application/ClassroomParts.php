<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Application\Actions\CourseActions;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Coursework part of the learner record (D-104): each course's
 * continuous assessment so far, from released marks, and the work never
 * handed in. Work missing needs attention.
 *
 * @phpstan-type Coursework array{semester: string, courses: list<array{code: string, title: string, total: float, share: int, missing: int, tasks: list<array{title: string, kind: string, out_of: int, weight: float, mark: float|null, state: string, released: bool}>}>, missing: int}
 */
final class ClassroomParts implements LearnerRecordPart
{
    /** @var array<string, Coursework|null> */
    private array $seen = [];

    public function __construct(
        private readonly Access $access,
        private readonly EnrolmentDirectory $learners,
        private readonly Courses $courses,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'classroom';
    }

    public function title(): string
    {
        return 'Coursework';
    }

    public function band(User $viewer, string $learnerId): array
    {
        $w = $this->work($viewer, $learnerId);
        if ($w === null || $w['courses'] === []) {
            return [];
        }

        return [['label' => 'Coursework', 'value' => $w['missing'] === 0 ? 'Up to date' : $w['missing'].' missing',
            'note' => $w['missing'] === 0 ? 'Everything due is handed in.' : ($w['missing'] === 1 ? '1 piece of work not handed in.' : "{$w['missing']} pieces of work not handed in."),
            'tone' => $w['missing'] === 0 ? 'plain' : 'attention']];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        return $this->work($viewer, $learnerId)['missing'] ?? 0;
    }

    /** @return Coursework */
    public function tab(User $viewer, string $learnerId): array
    {
        return $this->work($viewer, $learnerId) ?? throw new NotFoundHttpException;
    }

    /** @return Coursework|null */
    private function work(User $viewer, string $learnerId): ?array
    {
        $key = $viewer->id.'|'.$learnerId;
        if (array_key_exists($key, $this->seen)) {
            return $this->seen[$key];
        }
        $division = $this->learners->placeOf($learnerId)['division_id'] ?? null;
        $period = $division === null ? null : $this->courses->current($division);
        if ($division === null || $period === null || ! $this->access->allows($viewer, ClassroomPermission::View, ScopeRef::of(ScopeType::Division, $division))) {
            return $this->seen[$key] = null;
        }
        $now = $this->clock->now();
        $courses = [];
        $missing = 0;
        foreach ($this->courses->offerings($division, $period['id']) as $o) {
            if (! $this->courses->holds($learnerId, $o['id'], $period['id'])) {
                continue;
            }
            $tasks = array_values(DB::table('classroom_tasks')->where('offering_id', $o['id'])->whereNull('removed_at')->orderBy('due_at')->get()->all());
            if ($tasks === []) {
                continue;
            }
            $row = ClassroomViews::grid($tasks, [['learner_id' => $learnerId, 'name' => '', 'number' => '', 'user_id' => null, 'phone' => null]], $now, $learnerId)[0];
            $list = [];
            foreach ($tasks as $i => $t) {
                $cell = $row['cells'][$i];
                $list[] = ['title' => Row::text($t, 'title'), 'kind' => Row::text($t, 'kind'), 'out_of' => Row::int($t, 'out_of'), 'weight' => (float) Row::text($t, 'weight'),
                    'mark' => $cell['released'] ? $cell['mark'] : null, 'state' => $cell['state'], 'released' => $cell['released']];
            }
            $missing += $row['missing'];
            $courses[] = ['code' => $o['code'], 'title' => $o['title'], 'total' => $row['total'], 'share' => CourseActions::share($o['id']), 'missing' => $row['missing'], 'tasks' => $list];
        }

        return $this->seen[$key] = ['semester' => $period['name'], 'courses' => $courses, 'missing' => $missing];
    }
}
