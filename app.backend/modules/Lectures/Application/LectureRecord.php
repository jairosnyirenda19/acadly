<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Lecture attendance part of the learner record (D-102): the student's
 * rate this semester, course by course, the lectures missed or marked by
 * hand, and their attendance phone. A course below 80% needs attention.
 *
 * @phpstan-type Record array{semester: string, rate: int|null, held: int, missed: int, courses: list<array{code: string, title: string, held: int, missed: int, late: int, rate: int|null}>, marks: list<array{day: string, code: string, kind: string, mark: string, source: string, reason: string|null, by: string|null, was: string|null}>}
 */
final class LectureRecord implements LearnerRecordPart
{
    /** A course attended less than this, in per cent, is marked. */
    public const int BELOW = 80;

    /** @var array<string, Record|null> */
    private array $seen = [];

    public function __construct(
        private readonly Access $access,
        private readonly EnrolmentDirectory $learners,
        private readonly LectureDays $days,
        private readonly CourseTeaching $teaching,
        private readonly Clock $clock,
    ) {}

    public function key(): string
    {
        return 'lectures';
    }

    public function title(): string
    {
        return 'Lecture attendance';
    }

    public function band(User $viewer, string $learnerId): array
    {
        $s = $this->summary($viewer, $learnerId);
        if ($s === null || $s['held'] === 0) {
            return [];
        }
        $below = $this->below($s);

        return [['label' => 'Lectures', 'value' => $s['rate'].'%', 'note' => $below === 0 ? $s['held'].' held this semester.' : ($below === 1 ? 'Below 80% in 1 course.' : "Below 80% in {$below} courses."),
            'tone' => $below === 0 ? 'plain' : 'attention']];
    }

    public function attention(User $viewer, string $learnerId): int
    {
        $s = $this->summary($viewer, $learnerId);

        return $s === null ? 0 : $this->below($s);
    }

    /**
     * The tab: the semester's attendance, and the phone.
     *
     * @return array<string, mixed>
     */
    public function tab(User $viewer, string $learnerId): array
    {
        $s = $this->summary($viewer, $learnerId) ?? throw new NotFoundHttpException;
        $phone = DB::table('lecture_phones')->where('learner_id', $learnerId)->whereNull('reset_at')->value('created_at');
        $division = $this->learners->placeOf($learnerId)['division_id'] ?? '';

        return [...$s, 'phone' => ['set' => is_string($phone), 'set_on' => is_string($phone) ? substr($phone, 0, 10) : null],
            'can_reset' => $this->access->allows($viewer, LecturesPermission::Phones, ScopeRef::of(ScopeType::Division, $division))];
    }

    /** @return Record|null */
    private function summary(User $viewer, string $learnerId): ?array
    {
        $key = $viewer->id.'|'.$learnerId;
        if (array_key_exists($key, $this->seen)) {
            return $this->seen[$key];
        }
        $division = $this->learners->placeOf($learnerId)['division_id'] ?? null;
        $period = $division === null ? null : $this->days->period($division, $this->clock->today());
        if ($division === null || $period === null || ! $this->access->allows($viewer, LecturesPermission::View, ScopeRef::of(ScopeType::Division, $division))
            || ! DB::table('lecture_slots')->where('period_id', $period['id'])->exists()) {
            return $this->seen[$key] = null;
        }

        return $this->seen[$key] = ['semester' => $period['name'], ...StudentLectures::summary($learnerId, $division, $period['id'], $this->teaching)];
    }

    /** @param  Record  $s */
    private function below(array $s): int
    {
        $n = 0;
        foreach ($s['courses'] as $c) {
            if ($c['rate'] !== null && $c['rate'] < self::BELOW) {
                $n++;
            }
        }

        return $n;
    }
}
