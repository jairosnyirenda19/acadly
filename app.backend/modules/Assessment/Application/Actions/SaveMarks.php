<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Marks;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\Sheets;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\MissingReason;
use Acadly\Modules\Assessment\Journeys\Marks\State;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves marks on the marks sheet: many in one request. A row is added only
 * for a mark that differs from the one in force, so saving again changes
 * nothing. Marks are entered while the assessment is being marked or was
 * returned, from the day it is held, by the teacher whose assessment it is.
 */
final class SaveMarks
{
    public const int MOST = 500;

    public function __construct(
        private readonly Access $access,
        private readonly Sheets $sheets,
        private readonly Reach $reach,
        private readonly EnrolmentDirectory $learners,
        private readonly Marks $marks,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<array{learner_id: string, value: mixed, reason: string|null}>  $given
     * @return int the marks added
     */
    public function handle(User $by, string $assessmentId, array $given): int
    {
        $a = $this->sheets->find($by, $assessmentId);
        $at = ScopeRef::of(ScopeType::ClassGroup, $a->class_id);
        $this->access->check($by, AssessmentPermission::Enter, $at);

        if (! $this->reach->owns($by, $a)) {
            throw new AuthorizationException('Access is refused.');
        }

        if (! (State::tryFrom($a->state)?->open() ?? false)) {
            throw ValidationException::withMessages(['marks' => ['These marks are submitted. A change now is made by returning them, or as a correction once locked.']]);
        }

        if ($a->heldOn() > $this->clock->today()) {
            throw ValidationException::withMessages(['marks' => ['Marks can be entered from the day the assessment is held.']]);
        }

        if ($given === [] || count($given) > self::MOST) {
            throw ValidationException::withMessages(['marks' => ['Send from 1 to '.self::MOST.' marks.']]);
        }

        $names = array_column($this->learners->learnersIn($a->class_id, $a->heldOn()), 'name', 'id');
        $clean = [];
        $errors = [];

        foreach ($given as $i => $m) {
            $parsed = self::parse($m, $a->out_of, isset($names[$m['learner_id']]));

            if (is_string($parsed)) {
                $errors["marks.{$i}"] = [$parsed];

                continue;
            }

            if ($parsed !== null) {
                $clean[$m['learner_id']] = $parsed;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $a, $clean, $at): int {
            DB::table('assessments')->where('id', $a->id)->lockForUpdate()->value('id');
            $now = $this->marks->inForce([$a->id])[$a->id] ?? [];
            $rows = [];
            $time = $this->clock->now();

            foreach ($clean as $learner => $mark) {
                $current = $now[$learner] ?? null;

                if ($current !== null && $current['value'] === $mark['value'] && $current['reason'] === $mark['reason']) {
                    continue;
                }

                $rows[] = ['id' => Ids::next(), 'assessment_id' => $a->id, 'learner_id' => $learner, 'value' => $mark['value'], 'reason' => $mark['reason'],
                    'entered_by' => $by->id, 'entered_at' => $time, 'replaces_id' => null, 'note' => null];
            }

            if ($rows === []) {
                return 0;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('assessment_marks')->insert($chunk);
            }

            $this->audit->record(AssessmentAudit::MarksSaved, subject: $a, actor: $this->audit->actorFor($by), at: $at, meta: ['saved' => count($rows)]);

            return count($rows);
        });
    }

    /**
     * A mark as sent: a value within the scale, or a reason; null when it gives neither.
     *
     * @param  array{learner_id: string, value: mixed, reason: string|null}  $m
     * @return array{value: float|null, reason: string|null}|string|null a message when it is wrong
     */
    public static function parse(array $m, int $outOf, bool $inClass): array|string|null
    {
        if (! $inClass) {
            return 'This learner was not in the class on the day.';
        }

        $value = $m['value'];
        $reason = $m['reason'] !== null && $m['reason'] !== '' ? MissingReason::tryFrom($m['reason']) : null;

        if ($m['reason'] !== null && $m['reason'] !== '' && $reason === null) {
            return 'Give the reason as absent, excused or exempt.';
        }

        if ($value === null || $value === '') {
            return $reason !== null ? ['value' => null, 'reason' => $reason->value] : null;
        }

        if ($reason !== null) {
            return 'Give a mark or a reason, not both.';
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > $outOf || round((float) $value, 2) !== (float) $value) {
            return "A mark is a number from 0 to {$outOf}, with up to two decimal places.";
        }

        return ['value' => round((float) $value, 2), 'reason' => null];
    }
}
