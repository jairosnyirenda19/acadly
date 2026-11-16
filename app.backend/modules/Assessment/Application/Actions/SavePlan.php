<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Plans;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\MissingRule;
use Acadly\Modules\Assessment\Contracts\PartRule;
use Acadly\Modules\Assessment\Contracts\PlanMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Saves a grading plan as a new version (the approved Grading plans screen).
 * The weights sum to 100; each kind is in at most one part; once the term
 * has begun, a new version needs a reason. Earlier versions are kept; term
 * grades are worked out from the newest from then on.
 *
 * @phpstan-import-type Part from Plans
 */
final class SavePlan
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly Teaching $teaching,
        private readonly Plans $plans,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input  {division_id, level_code, period_id, subject_id, mode, parts, reason}
     * @return string the new version's id
     */
    public function handle(User $by, array $input): string
    {
        $divisionId = is_string($input['division_id'] ?? null) ? $input['division_id'] : '';
        $division = Ids::isValid($divisionId) ? $this->school->division($divisionId) : null;

        if ($division === null) {
            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::Division, $division->id);
        $this->access->check($by, AssessmentPermission::Plan, $at);

        $level = is_string($input['level_code'] ?? null) ? $input['level_code'] : '';
        $period = $this->teaching->period(is_string($input['period_id'] ?? null) ? $input['period_id'] : '');
        $subjectId = is_string($input['subject_id'] ?? null) && $input['subject_id'] !== '' ? $input['subject_id'] : null;
        $errors = [];

        if (! $division->profile->hasLevel($level)) {
            $errors['level_code'] = ['Choose a level of this division.'];
        }

        if ($period === null || $period['division_id'] !== $division->id) {
            $errors['period_id'] = ['Choose a term of this division.'];
        }

        if ($subjectId !== null && ! in_array($subjectId, array_column($this->teaching->subjectsFor($division->id, $level), 'id'), true)) {
            $errors['subject_id'] = ['Choose a subject this level takes.'];
        }

        $mode = PlanMode::tryFrom(is_string($input['mode'] ?? null) ? $input['mode'] : '');

        if ($mode === null) {
            $errors['mode'] = ['Choose how the term grade is made: continuous or exam only.'];
        }

        $parts = $mode === PlanMode::ExamOnly ? [Plans::examPart(100)] : $this->parts($input['parts'] ?? null, $errors);
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        $begun = $period !== null && $period['starts_on'] <= $this->clock->today();

        if ($begun && $reason === '') {
            $errors['reason'] = ['The term has begun, so a new version needs a reason.'];
        } elseif (mb_strlen($reason) > 255) {
            $errors['reason'] = ['Give the reason in up to 255 characters.'];
        }

        if ($errors !== [] || $period === null || $mode === null) {
            throw ValidationException::withMessages($errors);
        }

        $id = Ids::next();

        DB::transaction(function () use ($by, $division, $level, $period, $subjectId, $mode, $parts, $reason, $id, $at): void {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['grading_plan:'.$division->id.':'.$level.':'.$period['id'].':'.($subjectId ?? '-')]);
            $version = DB::table('grading_plans')->where('division_id', $division->id)->where('level_code', $level)->where('period_id', $period['id'])
                ->when($subjectId === null, fn ($q) => $q->whereNull('subject_id'), fn ($q) => $q->where('subject_id', $subjectId))->max('version');
            $version = (is_numeric($version) ? (int) $version : 0) + 1;

            DB::table('grading_plans')->insert([
                'id' => $id, 'division_id' => $division->id, 'level_code' => $level, 'period_id' => $period['id'], 'subject_id' => $subjectId,
                'version' => $version, 'mode' => $mode->value, 'parts' => json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'reason' => $reason !== '' ? $reason : null, 'created_by' => $by->id, 'created_at' => $this->clock->now(),
            ]);
            $this->audit->record(AssessmentAudit::PlanSaved, reason: $reason !== '' ? $reason : null, actor: $this->audit->actorFor($by), at: $at, meta: [
                'plan' => $id, 'level' => $level, 'period' => $period['id'], 'subject' => $subjectId, 'version' => $version, 'mode' => $mode->value,
                'weights' => implode(' + ', array_map(fn (array $p): string => $p['key'].' '.$p['weight'], $parts)),
            ]);
        });

        $this->plans->forget();

        return $id;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<Part>
     */
    private function parts(mixed $given, array &$errors): array
    {
        $given = is_array($given) && array_is_list($given) ? $given : [];

        if ($given === [] || count($given) > 6) {
            $errors['parts'] = ['A plan has from 1 to 6 parts.'];

            return [];
        }

        $kinds = array_map(fn (AssessmentKind $k): string => $k->value, AssessmentKind::cases());
        $parts = [];
        $seenKinds = [];

        foreach ($given as $i => $p) {
            $p = is_array($p) ? $p : [];
            $key = is_string($p['key'] ?? null) ? $p['key'] : '';
            $label = is_string($p['label'] ?? null) ? trim($p['label']) : '';
            $weight = $p['weight'] ?? null;
            $chosen = is_array($p['kinds'] ?? null) ? array_values(array_unique(array_filter($p['kinds'], is_string(...)))) : [];
            $rule = PartRule::tryFrom(is_string($p['rule'] ?? null) ? $p['rule'] : '');
            $missing = MissingRule::tryFrom(is_string($p['missing'] ?? null) ? $p['missing'] : '');
            $best = $p['best_n'] ?? null;

            if (preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) !== 1 || $label === '' || mb_strlen($label) > 60) {
                $errors["parts.{$i}"] = ['Each part needs a key of lower-case letters and a label of up to 60 characters.'];
            } elseif (! is_int($weight) || $weight < 1 || $weight > 100) {
                $errors["parts.{$i}.weight"] = ['A weight is a whole percentage from 1 to 100.'];
            } elseif ($chosen === [] || array_diff($chosen, $kinds) !== []) {
                $errors["parts.{$i}.kinds"] = ['Choose the kinds of assessment the part takes.'];
            } elseif (array_intersect($chosen, $seenKinds) !== []) {
                $errors["parts.{$i}.kinds"] = ['Each kind of assessment can be in one part only.'];
            } elseif ($rule === null || $missing === null) {
                $errors["parts.{$i}.rule"] = ['Choose how the part combines its assessments, and what it does with missing work.'];
            } elseif ($rule === PartRule::BestN && (! is_int($best) || $best < 1 || $best > 20)) {
                $errors["parts.{$i}.best_n"] = ['Say how many of the best count, from 1 to 20.'];
            } else {
                $seenKinds = [...$seenKinds, ...$chosen];
                $parts[] = ['key' => $key, 'label' => $label, 'weight' => $weight, 'kinds' => $chosen, 'rule' => $rule->value,
                    'best_n' => $rule === PartRule::BestN ? $best : null, 'missing' => $missing->value];
            }
        }

        if (count(array_unique(array_column($parts, 'key'))) !== count($parts)) {
            $errors['parts'] = ['Each part needs its own key.'];
        } elseif (count($parts) === count($given) && array_sum(array_column($parts, 'weight')) !== 100) {
            $errors['parts'] = ['The weights must add up to 100%.'];
        }

        return $parts;
    }
}
