<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Application\Scales;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\OverallResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps the grading scales (the approved Scales screen): adds a scale,
 * changes or retires one, and chooses the scale of a level. Scales are the
 * school's; changing them needs `assessment.plan` for the whole school. A
 * change applies to term grades worked out from then on.
 *
 * @phpstan-import-type Band from Scales
 */
final class ScaleActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Scales $scales,
        private readonly SchoolDirectory $school,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param array<array-key, mixed> $input */
    public function add(User $by, array $input): string
    {
        $this->access->check($by, AssessmentPermission::Plan, ScopeRef::institution());
        $clean = $this->validated($input);
        $id = Ids::next();
        $now = $this->clock->now();
        $position = DB::table('assessment_scales')->max('position');

        DB::transaction(function () use ($by, $clean, $id, $now, $position): void {
            DB::table('assessment_scales')->insert([
                'id' => $id, 'seed_key' => null, 'name' => $clean['name'], 'overall' => $clean['overall']->value, 'pass_from' => $clean['pass_from'],
                'bands' => json_encode($clean['bands'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'track' => $clean['track'],
                'position' => (is_numeric($position) ? (int) $position : 0) + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record(AssessmentAudit::ScaleSaved, actor: $this->audit->actorFor($by), meta: ['scale' => $id, 'name' => $clean['name'], 'added' => true]);
        });

        $this->scales->forget();

        return $id;
    }

    /** @param array<array-key, mixed> $input */
    public function save(User $by, string $id, array $input): void
    {
        $this->access->check($by, AssessmentPermission::Plan, ScopeRef::institution());
        $before = $this->scales->get($id) ?? throw new NotFoundHttpException;
        $clean = $this->validated($input);
        $retire = ($input['retired'] ?? false) === true;

        if ($retire && in_array($id, $this->scales->levelChoices(), true)) {
            throw ValidationException::withMessages(['retired' => ['A level uses this scale. Give the level another scale first.']]);
        }

        DB::transaction(function () use ($by, $id, $clean, $retire, $before): void {
            DB::table('assessment_scales')->where('id', $id)->update([
                'name' => $clean['name'], 'overall' => $clean['overall']->value, 'pass_from' => $clean['pass_from'],
                'bands' => json_encode($clean['bands'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'track' => $clean['track'],
                'retired_at' => $retire ? ($before['retired'] ? DB::raw('retired_at') : $this->clock->now()) : null, 'updated_at' => $this->clock->now(),
            ]);
            $this->audit->record(AssessmentAudit::ScaleSaved, actor: $this->audit->actorFor($by), meta: [
                'scale' => $id, 'name' => $clean['name'], 'retired' => $retire,
                'bands_before' => json_encode($before['bands'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'bands_after' => json_encode($clean['bands'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        });

        $this->scales->forget();
    }

    /** Chooses the scale a level of a division uses. */
    public function setLevel(User $by, string $divisionId, string $levelCode, string $scaleId): void
    {
        $division = Ids::isValid($divisionId) ? $this->school->division($divisionId) : null;

        if ($division === null) {
            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::Division, $division->id);
        $this->access->check($by, AssessmentPermission::Plan, $at);

        if (! $division->profile->hasLevel($levelCode)) {
            throw ValidationException::withMessages(['level_code' => ['Choose a level of this division.']]);
        }

        $scale = $this->scales->get($scaleId);

        if ($scale === null || $scale['retired']) {
            throw ValidationException::withMessages(['scale_id' => ['Choose a scale in use.']]);
        }

        DB::transaction(function () use ($by, $division, $levelCode, $scaleId, $at): void {
            DB::table('assessment_level_scales')->upsert(
                [['division_id' => $division->id, 'level_code' => $levelCode, 'scale_id' => $scaleId, 'set_by' => $by->id, 'set_at' => $this->clock->now()]],
                ['division_id', 'level_code'], ['scale_id', 'set_by', 'set_at'],
            );
            $this->audit->record(AssessmentAudit::LevelScaleSet, actor: $this->audit->actorFor($by), at: $at,
                meta: ['level' => $levelCode, 'scale' => $scaleId]);
        });

        $this->scales->forget();
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{name: string, overall: OverallResult, pass_from: string|null, track: string, bands: list<Band>}
     */
    private function validated(array $input): array
    {
        $errors = [];
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';

        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'] = ['Name the scale in up to 60 characters.'];
        }

        $track = is_string($input['track'] ?? null) ? $input['track'] : 'malawi';

        if (! in_array($track, ['malawi', 'british'], true)) {
            $errors['track'] = ['Choose Malawi or British.'];
        }

        $bands = [];
        $given = is_array($input['bands'] ?? null) && array_is_list($input['bands']) ? $input['bands'] : [];

        foreach ($given as $i => $band) {
            $band = is_array($band) ? $band : [];
            $grade = is_string($band['grade'] ?? null) ? trim($band['grade']) : '';
            $word = is_string($band['word'] ?? null) ? trim($band['word']) : '';
            $from = $band['from'] ?? null;
            $to = $band['to'] ?? null;

            if ($grade === '' || mb_strlen($grade) > 16 || mb_strlen($word) > 30 || ! is_int($from) || ! is_int($to) || $from < 0 || $to > 100 || $from > $to) {
                $errors["bands.{$i}"] = ['Each band needs a grade of up to 16 characters, a word of up to 30, and whole percentages from 0 to 100, the lower first.'];

                continue;
            }

            $bands[] = ['grade' => $grade, 'word' => $word, 'from' => $from, 'to' => $to];
        }

        if (count($given) < 2 || count($given) > 20) {
            $errors['bands'] = ['A scale has from 2 to 20 bands.'];
        } elseif (! isset($errors['bands']) && count($bands) === count($given)) {
            if (count(array_unique(array_column($bands, 'grade'))) !== count($bands)) {
                $errors['bands'] = ['Each band needs its own grade.'];
            } elseif (! Scales::covers($bands)) {
                $errors['bands'] = ['The bands must cover 0 to 100%, with no gap and no overlap.'];
            }
        }

        $pass = is_string($input['pass_from'] ?? null) && trim($input['pass_from']) !== '' ? trim($input['pass_from']) : null;

        if ($pass !== null && ! in_array($pass, array_column($bands, 'grade'), true)) {
            $errors['pass_from'] = ['A pass must be one of the grades of the scale.'];
        }

        $overall = is_string($input['overall'] ?? null) ? OverallResult::tryFrom($input['overall']) : OverallResult::AveragePercent;

        if ($overall === null) {
            $errors['overall'] = ['Choose how the overall result is worked out.'];
        } elseif ($overall->needsNumbers() && array_filter($bands, fn (array $b): bool => ! is_numeric($b['grade'])) !== []) {
            $errors['overall'] = ['An average grade or a best six needs every grade to be a number.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        usort($bands, fn (array $a, array $b): int => $b['from'] <=> $a['from']);

        return ['name' => $name, 'overall' => $overall, 'pass_from' => $pass, 'track' => $track, 'bands' => $bands];
    }
}
