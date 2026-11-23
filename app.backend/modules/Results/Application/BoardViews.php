<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\StudentProgress;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What the console shows of the board's end-of-year work (D-106): the
 * progression lists of the year, one list, the graduation lists, one list,
 * the transcripts desk and the rules.
 */
final class BoardViews
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly Progression $progression,
        private readonly Awards $awards,
        private readonly Transcripts $transcripts,
        private readonly StudentProgress $progress,
        private readonly ResultsViews $views,
        private readonly JourneyEngine $engine,
    ) {}

    /** @return array<string, mixed> */
    public function progressions(User $who, string $divisionId): array
    {
        $this->sees($who, $divisionId);
        $year = $this->progression->year($divisionId);
        $out = [];
        foreach ($this->progress->programmes($divisionId) as $p) {
            for ($y = 1; $y <= $p['years']; $y++) {
                $students = count($this->progress->students($p['id'], $y));
                $list = $this->progression->find($divisionId, $p['id'], $y);
                if ($students === 0 && $list === null) {
                    continue;
                }
                $out[] = ['programme_id' => $p['id'], 'year' => $y, 'label' => "{$p['code']} · Year {$y}", 'name' => $p['name'], 'students' => $students,
                    'state' => $list === null ? 'worked_out' : $list->state, 'list_id' => $list?->id];
            }
        }

        return ['period' => ['id' => $year['last']['id'], 'name' => $year['last']['name']], 'lists' => $out, 'can' => $this->views->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function progression(User $who, string $divisionId, string $programmeId, int $year): array
    {
        $this->sees($who, $divisionId);
        $programme = $this->progression->programme($divisionId, $programmeId);
        $list = $this->progression->find($divisionId, $programmeId, $year);
        $rows = $this->progression->rowsFor($divisionId, $programmeId, $year, $list);
        $count = fn (array $d): int => count(array_filter($rows, fn (array $r): bool => in_array($r['decision'], $d, true)));

        return ['programme' => ['id' => $programme['id'], 'code' => $programme['code'], 'name' => $programme['name'], 'years' => $programme['years']], 'year' => $year,
            'period' => $this->progression->year($divisionId)['last']['name'],
            'list' => $list === null ? null : ['id' => $list->id, 'state' => $list->state],
            'rows' => array_map(fn (array $r): array => [...$r, 'failed' => array_column($r['failed'], 'code')], $rows),
            'summary' => ['proceed' => $count(['proceed', 'complete']), 'carry' => $count(['carry']), 'repeat' => $count(['repeat_year']), 'discontinue' => $count(['discontinue']),
                'open' => count(array_filter($rows, fn (array $r): bool => $r['decision'] === null))],
            'rules' => Rules::for($divisionId, $this->progression->year($divisionId)['last']['starts_on']),
            'steps' => $list === null ? [] : array_map(fn ($a): array => $a->toArray(), $this->engine->available($list, $who)),
            'history' => $list === null ? [] : $this->history($list->auditType(), $list->id),
            'can' => $this->views->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function graduations(User $who, string $divisionId): array
    {
        $this->sees($who, $divisionId);
        $out = [];
        foreach ($this->progress->programmes($divisionId) as $p) {
            $list = $this->awards->find($divisionId, $p['id']);
            $students = count($this->progress->students($p['id'], $p['years']));
            if ($students === 0 && $list === null) {
                continue;
            }
            $out[] = ['programme_id' => $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'students' => $students, 'state' => $list === null ? 'worked_out' : $list->state, 'list_id' => $list?->id,
                'graduation_on' => $list?->graduation_on?->toDateString()];
        }

        return ['period' => $this->progression->year($divisionId)['last']['name'], 'lists' => $out, 'can' => $this->views->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function graduation(User $who, string $divisionId, string $programmeId): array
    {
        $this->sees($who, $divisionId);
        $programme = $this->progression->programme($divisionId, $programmeId);
        $list = $this->awards->find($divisionId, $programmeId);
        $rows = $this->awards->rows($divisionId, $programmeId, $list);
        $classes = [];
        foreach ($rows as $r) {
            if ($r['complete'] && $r['class'] !== null) {
                $classes[$r['class']] = ($classes[$r['class']] ?? 0) + 1;
            }
        }
        $rules = Rules::for($divisionId, $this->progression->year($divisionId)['last']['starts_on']);

        return ['programme' => ['id' => $programme['id'], 'code' => $programme['code'], 'name' => $programme['name'], 'award' => $programme['award'], 'credits' => $programme['credits']],
            'list' => $list === null ? null : ['id' => $list->id, 'state' => $list->state, 'graduation_on' => $list->graduation_on?->toDateString()],
            'rows' => $rows, 'summary' => ['complete' => count(array_filter($rows, fn (array $r): bool => $r['complete'])), 'not_complete' => count(array_filter($rows, fn (array $r): bool => ! $r['complete'])),
                'classes' => array_map(fn (string $k, int $v): array => ['name' => $k, 'count' => $v], array_keys($classes), array_values($classes))],
            'classes' => $rules['classes'][Rules::scheme($programme['award'])],
            'steps' => $list === null ? [] : array_map(fn ($a): array => $a->toArray(), $this->engine->available($list, $who)),
            'history' => $list === null ? [] : $this->history($list->auditType(), $list->id),
            'can' => $this->views->can($who, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function desk(User $who, string $divisionId): array
    {
        $this->sheets->enter($who, $divisionId);
        if (! $this->sheets->holds($who, ResultsPermission::Transcripts, $divisionId) && ! $this->sheets->sees($who, $divisionId)) {
            throw new AccessDeniedHttpException;
        }

        return [...$this->transcripts->desk($divisionId), 'can_issue' => $this->sheets->holds($who, ResultsPermission::Transcripts, $divisionId)];
    }

    /** @return array<string, mixed> */
    public function ruleSet(User $who, string $divisionId): array
    {
        $this->sheets->enter($who, $divisionId);
        $current = $this->sheets->current($divisionId);

        return ['rules' => $current === null ? Rules::standard() : Rules::for($divisionId, $current['starts_on']), 'standard' => Rules::standard(),
            'periods' => array_map(fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']], $this->sheets->periods($divisionId)), 'can' => $this->views->can($who, $divisionId)];
    }

    /** @return list<array{step: string, outcome: string, by: string, at: string, reason: string|null}> */
    private function history(string $type, string $id): array
    {
        $out = [];
        foreach (DB::table('journey_step_completions as j')->leftJoin('users as u', 'u.id', '=', 'j.actor_user_id')->where('j.subject_type', $type)->where('j.subject_id', $id)
            ->orderByDesc('j.seq')->get(['j.step', 'j.outcome', 'j.occurred_at', 'j.reason', 'u.name']) as $h) {
            $out[] = ['step' => Row::text($h, 'step'), 'outcome' => Row::text($h, 'outcome'), 'by' => Row::maybeText($h, 'name') ?? '',
                'at' => CarbonImmutable::parse(Row::text($h, 'occurred_at'))->toIso8601String(), 'reason' => Row::maybeText($h, 'reason')];
        }

        return $out;
    }

    private function sees(User $who, string $divisionId): void
    {
        $this->sheets->enter($who, $divisionId);
        if (! $this->sheets->sees($who, $divisionId) && ! $this->sheets->holds($who, ResultsPermission::Award, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
    }
}
