<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Contracts\AssessmentKind;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Acadly\Modules\Assessment\Contracts\MissingRule;
use Acadly\Modules\Assessment\Contracts\PartRule;
use Acadly\Modules\Assessment\Contracts\PlanMode;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Assessment settings (the approved Scales and Grading plans screens):
 * how marks become grades.
 *
 * @phpstan-import-type Plan from Plans
 */
final class Settings
{
    public function __construct(
        private readonly Access $access,
        private readonly SchoolDirectory $school,
        private readonly Teaching $teaching,
        private readonly Scales $scales,
        private readonly Plans $plans,
        private readonly Reach $reach,
        private readonly Clock $clock,
    ) {}

    /**
     * Every scale with the levels that use it, and every level with its scale.
     *
     * @return array<string, mixed>
     */
    public function scales(User $viewer): array
    {
        $this->access->check($viewer, AssessmentPermission::Plan, ScopeRef::institution());
        $levels = [];

        foreach ($this->school->divisionViews() as $division) {
            foreach ($division->profile->levels as $level) {
                $scale = $this->scales->forLevel($division->id, $level['code']);
                $levels[] = [
                    'division_id' => $division->id, 'division' => $division->name, 'level_code' => $level['code'], 'level' => $level['label'],
                    'scale_id' => $scale['id'] ?? null, 'takes_marks' => $this->reach->takesMarks($division->id),
                ];
            }
        }

        $scales = array_values(array_map(fn (array $s): array => [...$s, 'used_by' => array_values(array_map(
            fn (array $l): array => ['division_id' => $l['division_id'], 'division' => $l['division'], 'level_code' => $l['level_code'], 'level' => $l['level']],
            array_filter($levels, fn (array $l): bool => $l['scale_id'] === $s['id'] && $l['takes_marks']),
        ))], $this->scales->all()));

        return ['scales' => $scales, 'levels' => $levels, 'can_edit' => true];
    }

    /**
     * The grading plan of a level in a term, or of one subject: the version in
     * force, every version, and the subjects with their own plan.
     *
     * @return array<string, mixed>
     */
    public function plans(User $viewer, string $divisionId, string $levelCode, string $periodId, ?string $subjectId): array
    {
        $division = Ids::isValid($divisionId) ? $this->school->division($divisionId) : null;

        if ($division === null) {
            throw new NotFoundHttpException;
        }

        $at = ScopeRef::of(ScopeType::Division, $division->id);

        if (! $this->access->allows($viewer, AssessmentPermission::View, $at)) {
            $this->access->check($viewer, AssessmentPermission::Plan, $at);
        }

        $period = $this->teaching->period($periodId);

        if (! $division->profile->hasLevel($levelCode) || $period === null || $period['division_id'] !== $division->id) {
            throw new NotFoundHttpException;
        }

        $subjects = $this->teaching->subjectsFor($division->id, $levelCode);

        if ($subjectId !== null && ! in_array($subjectId, array_column($subjects, 'id'), true)) {
            throw new NotFoundHttpException;
        }

        $own = $this->plans->subjectPlans($division->id, $levelCode, $period['id']);
        $plan = $this->plans->inForce($division->id, $levelCode, $period['id'], $subjectId);
        $versions = $this->plans->versions($division->id, $levelCode, $period['id'], $subjectId);
        $names = Sheets::userNames(array_values(array_filter(array_map(fn (array $p): ?string => $p['created_by'], [$plan, ...$versions]), is_string(...))));
        $scale = $this->scales->forLevel($division->id, $levelCode);

        return [
            'division' => ['id' => $division->id, 'name' => $division->name],
            'level' => ['code' => $levelCode, 'label' => $division->profile->levelLabel($levelCode)],
            'period' => $period,
            'begun' => $period['starts_on'] <= $this->clock->today(),
            'subject_id' => $subjectId,
            'plan' => self::shown($plan, $names),
            'versions' => array_map(fn (array $p): array => self::shown($p, $names), $versions),
            'subjects' => array_map(fn (array $s): array => ['id' => $s['id'], 'name' => $s['name'], 'short' => $s['short'],
                'own' => isset($own[$s['id']]) ? ['version' => $own[$s['id']]['version'], 'summary' => Plans::summary($own[$s['id']])] : null], $subjects),
            'scale' => $scale !== null ? ['id' => $scale['id'], 'name' => $scale['name']] : null,
            'kinds' => AssessmentKind::choices(),
            'modes' => array_map(fn (PlanMode $m): array => ['value' => $m->value, 'label' => $m->label()], PlanMode::cases()),
            'rules' => array_map(fn (PartRule $r): array => ['value' => $r->value, 'label' => $r->label()], PartRule::cases()),
            'missing' => array_map(fn (MissingRule $r): array => ['value' => $r->value, 'label' => $r->label()], MissingRule::cases()),
            'can_edit' => $this->access->allows($viewer, AssessmentPermission::Plan, $at),
        ];
    }

    /**
     * A plan as the screen shows it: who made the version, and the weights in words.
     *
     * @param  Plan  $plan
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private static function shown(array $plan, array $names): array
    {
        return [...$plan, 'created_by' => $plan['created_by'] !== null ? ($names[$plan['created_by']] ?? '') : null, 'summary' => Plans::summary($plan)];
    }
}
