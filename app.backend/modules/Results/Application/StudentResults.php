<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Classroom\Contracts\ContinuousAssessment;
use Acadly\Modules\Fees\Contracts\FeesStanding;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Results\Journeys\Sheet\State;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Results in the student portal (D-105): released results by semester with
 * the GPAs, one course's result with its parts, the supplementary exams to
 * sit, and attendance against the bar. Every view is the student's own.
 */
final class StudentResults
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly Standing $standing,
        private readonly CourseTeaching $teaching,
        private readonly ModuleActivation $modules,
        private readonly Container $container,
    ) {}

    /** @return array{learner_id: string, division_id: string} */
    public function student(User $who): array
    {
        return $this->teaching->studentOf($who->id) ?? throw new NotFoundHttpException;
    }

    /** @return array<string, mixed> */
    public function results(User $who): array
    {
        $s = $this->student($who);
        $history = $this->standing->history($s['learner_id'], true);
        $sitting = [];
        foreach (DB::table('results_sheets')->where('division_id', $s['division_id'])->where('sitting', 'supplementary')->where('state', '!=', State::Released->value)->get() as $sheet) {
            $course = $this->sheets->find($s['division_id'], Row::text($sheet, 'offering_id'));
            foreach ($this->sheets->lines($this->sheets->sheet(Row::text($sheet, 'offering_id'), 'supplementary'), $course, $s['learner_id']) as $l) {
                $sitting[] = ['code' => $course['offering']['code'], 'title' => $course['offering']['title'], 'kind' => $l['kind'],
                    'exam_on' => is_string($sheet->exam_on ?? null) ? $sheet->exam_on : null];
            }
        }

        return [...$history, 'supplementary' => $sitting, 'slip_held' => $this->slipHeld($s)];
    }

    /** @return array<string, mixed> */
    public function course(User $who, string $offeringId): array
    {
        $s = $this->student($who);
        $main = $this->sheets->sheet($offeringId, 'main');
        if ($main === null || $main->state !== State::Released->value || $main->division_id !== $s['division_id']) {
            throw new NotFoundHttpException;
        }
        $course = $this->sheets->find($s['division_id'], $offeringId);
        $line = $this->sheets->finalLine($course, $s['learner_id'], true) ?? throw new NotFoundHttpException;
        $first = $this->sheets->lines($main, $course, $s['learner_id'])[0] ?? null;
        $parts = [];
        if ($this->modules->isActive('classroom', ScopeRef::of(ScopeType::Division, $s['division_id'])) && $this->container->bound(ContinuousAssessment::class)) {
            $parts = $this->container->make(ContinuousAssessment::class)->parts($offeringId, $s['learner_id']);
        }
        $scale = $this->sheets->scale($s['division_id'], $course['period']['id']);

        return ['course' => ['id' => $offeringId, 'code' => $course['offering']['code'], 'title' => $course['offering']['title'], 'credits' => $course['offering']['credits'],
            'period' => $course['period']['name']], 'line' => $line, 'first' => $first, 'parts' => $parts, 'pass_mark' => $scale['pass_mark']];
    }

    /** @return array<string, mixed> */
    public function attendance(User $who): array
    {
        $s = $this->student($who);
        $period = $this->sheets->current($s['division_id']);
        $settings = Scales::settings($s['division_id']);
        $out = [];
        if ($period !== null) {
            $held = $this->teaching->offeringsOf($s['learner_id'], $period['id']);
            foreach ($this->sheets->offerings($s['division_id'], $period['id']) as $o) {
                if (! in_array($o['id'], $held, true)) {
                    continue;
                }
                $course = ['offering' => $o, 'period' => $period, 'division_id' => $s['division_id']];
                $l = $this->sheets->lines($this->sheets->sheet($o['id'], 'main'), $course, $s['learner_id'])[0] ?? null;
                if ($l === null || $l['rate'] === null) {
                    continue;
                }
                $state = $l['lifted'] !== null ? 'lifted' : ($l['barred'] ? 'below' : ($l['rate'] < $settings['bar_percent'] + 5 ? 'close' : 'clear'));
                $out[] = ['code' => $o['code'], 'title' => $o['title'], 'held' => $l['held'], 'attended' => $l['attended'], 'rate' => $l['rate'], 'state' => $state];
            }
        }

        return ['bar_percent' => $settings['bar_percent'], 'courses' => $out];
    }

    /** @param  array{learner_id: string, division_id: string}  $s */
    public function slipHeld(array $s): bool
    {
        if (! Scales::settings($s['division_id'])['hold_slip_for_fees'] || ! $this->modules->isActive('fees', ScopeRef::of(ScopeType::Division, $s['division_id']))
            || ! $this->container->bound(FeesStanding::class)) {
            return false;
        }
        $f = $this->container->make(FeesStanding::class)->owedAndPaid($s['learner_id']);

        return $f['paid'] < $f['owed'];
    }
}
