<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Contracts\ResultsAudit;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The grading scale and the results settings of a division (D-105). A saved
 * scale never changes: a new scale applies from the semester named, and
 * semesters before it keep the scale they were graded on.
 */
final class SetupActions
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Saves a new scale from a semester on.
     *
     * @param  list<array{grade: string, min: int, max: int, points: float}>  $bands
     */
    public function saveScale(User $by, string $divisionId, string $periodId, string $label, int $passMark, array $bands): string
    {
        $this->allowed($by, $divisionId);
        $period = $this->sheets->period($divisionId, $periodId);
        usort($bands, fn (array $a, array $b): int => $b['min'] <=> $a['min']);
        $errors = [];
        $top = 100;
        $grades = [];
        foreach ($bands as $i => $b) {
            if (trim($b['grade']) === '' || mb_strlen(trim($b['grade'])) > 4 || in_array(trim($b['grade']), $grades, true)) {
                $errors["bands.{$i}.grade"] = ['Give each band its own grade, up to 4 characters.'];
            }
            $grades[] = trim($b['grade']);
            if ($b['max'] !== $top || $b['min'] > $b['max']) {
                $errors["bands.{$i}.min"] = ['The bands must cover every mark from 100 down to 0, with no gap and no overlap.'];
            }
            if ($b['points'] < 0 || $b['points'] > 5) {
                $errors["bands.{$i}.points"] = ['Grade points go from 0 to 5.'];
            }
            $top = $b['min'] - 1;
        }
        if ($bands === [] || $top !== -1) {
            $errors['bands'] = ['The bands must cover every mark from 100 down to 0, with no gap and no overlap.'];
        }
        if ($passMark < 1 || $passMark > 100) {
            $errors['pass_mark'] = ['Give a pass mark from 1 to 100.'];
        }
        if (DB::table('results_sheets')->where('division_id', $divisionId)->where('period_id', $period['id'])->whereIn('state', ['approved', 'released'])->exists()) {
            $errors['period_id'] = ['Results of this semester are approved. A new scale starts from a later semester.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $id = Ids::next();
        DB::transaction(function () use ($by, $divisionId, $period, $label, $passMark, $bands, $id): void {
            DB::table('results_scales')->insert(['id' => $id, 'division_id' => $divisionId, 'from_period_id' => $period['id'], 'from_on' => $period['starts_on'],
                'label' => mb_substr(trim($label) !== '' ? trim($label) : 'Grading scale', 0, 80), 'pass_mark' => $passMark,
                'bands' => json_encode(array_map(fn (array $b): array => ['grade' => trim($b['grade']), 'min' => $b['min'], 'max' => $b['max'], 'points' => round($b['points'], 2)], $bands)),
                'created_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::ScaleSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId), meta: ['scale' => $id, 'from' => $period['id']]);
        });

        return $id;
    }

    public function saveSettings(User $by, string $divisionId, int $barPercent, int $barDays, bool $holdSlip): void
    {
        $this->allowed($by, $divisionId);
        if ($barPercent < 0 || $barPercent > 100) {
            throw ValidationException::withMessages(['bar_percent' => ['Give a share of lectures from 0 to 100. 0 means no bar.']]);
        }
        if ($barDays < 0 || $barDays > 60) {
            throw ValidationException::withMessages(['bar_days' => ['Give from 0 to 60 days.']]);
        }
        DB::transaction(function () use ($by, $divisionId, $barPercent, $barDays, $holdSlip): void {
            DB::table('results_settings')->updateOrInsert(['division_id' => $divisionId], ['bar_percent' => $barPercent, 'bar_days' => $barDays, 'hold_slip_for_fees' => $holdSlip,
                'updated_by' => $by->id, 'updated_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::SettingsSaved, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                meta: ['bar_percent' => $barPercent, 'bar_days' => $barDays, 'hold_slip' => $holdSlip]);
        });
    }

    private function allowed(User $by, string $divisionId): void
    {
        $this->sheets->enter($by, $divisionId);
        if (! $this->sheets->holds($by, ResultsPermission::Setup, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
    }
}
