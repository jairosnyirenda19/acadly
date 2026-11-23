<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Results\Application\Actions\SetupActions;
use Acadly\Modules\Results\Application\Actions\SheetActions;
use Acadly\Modules\Results\Application\ResultsParts;
use Acadly\Modules\Results\Application\ResultsSlip;
use Acadly\Modules\Results\Application\ResultsViews;
use Acadly\Modules\Results\Application\Sheets;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Results in the console (D-105): the semester's courses and their sheets,
 * exam marks, moderation, the attendance bar, the examiners' board,
 * release, the supplementary sitting and the setup. Each route checks the division first.
 */
final class ResultsController
{
    public function __construct(private readonly Sheets $sheets) {}

    /**
     * The courses of a semester with their result sheets.
     *
     * @response array{period: array{id: string, name: string}, periods: list<array{id: string, name: string}>, courses: list<array{id: string, code: string, title: string, group: string, credits: int, department: string, lecturers: list<string>, students: int, teaches: bool, main: array{id: string, state: string}|null, supplementary: array{id: string, state: string}|null}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool}}
     */
    public function courses(Request $request, string $division, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period' => ['nullable', 'uuid']]);

        return response()->json($views->courses(self::user($request), $division, self::text($request, 'period')));
    }

    /**
     * One course's result sheet for a sitting.
     *
     * @response array{course: array{id: string, code: string, title: string, group: string, credits: int, department: string, period: string, period_id: string, lecturers: list<string>}, sheet: array{id: string, state: string, sitting: string, exam_on: string|null, released_at: string|null}|null, sitting: string, share: int, unreleased: int, bar_percent: int, scale: array{label: string, pass_mark: int, bands: list<array{grade: string, min: int, max: int, points: float}>}, lines: list<array{learner_id: string, name: string, number: string, ca: float, share: int, held: int, attended: int, rate: int|null, barred: bool, lifted: string|null, exam: float|null, absence: string|null, mark: int|null, grade: string|null, points: float|null, result: string, kind: string, changed: bool, change: array{stage: string, was: float|null, reason: string|null, by: string}|null}>, spread: array{bands: list<array{grade: string, count: int}>, average: float|null, passed: int, sat: int}, teaches: bool, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool}, steps: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: string|null, remedy: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>}
     */
    public function sheet(Request $request, string $division, string $id, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['sitting' => ['nullable', 'in:main,supplementary']]);

        return response()->json($views->sheet(self::user($request), $division, $id, self::sitting($request)));
    }

    /** @response array{saved: int} */
    public function enter(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['sitting' => ['nullable', 'in:main,supplementary'], 'exam_on' => ['nullable', 'date_format:Y-m-d'], 'marks' => ['present', 'array', 'max:2000'],
            'marks.*.learner_id' => ['required', 'uuid'], 'marks.*.exam' => ['nullable', 'numeric'], 'marks.*.absent' => ['nullable', 'boolean']]);
        $marks = [];
        foreach ((array) $request->input('marks', []) as $m) {
            $m = (array) $m;
            $marks[] = ['learner_id' => self::str($m['learner_id'] ?? null), 'exam' => self::num($m['exam'] ?? null), 'absent' => (bool) ($m['absent'] ?? false)];
        }

        return response()->json(['saved' => $actions->enter(self::user($request), $division, $id, self::sitting($request), $marks, self::text($request, 'exam_on'))]);
    }

    /** @response array{saved: bool} */
    public function submit(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['sitting' => ['nullable', 'in:main,supplementary']]);
        $actions->submit(self::user($request), $division, $id, self::sitting($request));

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function moderate(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['changes' => ['required', 'array', 'min:1', 'max:500'], 'changes.*.learner_id' => ['required', 'uuid'], 'changes.*.exam' => ['nullable', 'numeric'],
            'changes.*.reason' => ['required', 'string', 'max:255']]);
        $actions->moderate(self::user($request), $division, $id, self::changes($request));

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function decide(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['pass' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $actions->decideModeration(self::user($request), $division, $id, $request->boolean('pass'), self::text($request, 'reason'));

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function liftBar(Request $request, string $division, string $id, string $learner, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $actions->liftBar(self::user($request), $division, $id, $learner, $request->string('reason')->trim()->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function defer(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['learner_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:255']]);
        $actions->defer(self::user($request), $division, $id, $request->string('learner_id')->toString(), $request->string('reason')->trim()->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function returnSheet(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $actions->returnToModeration(self::user($request), $division, $id, $request->string('reason')->trim()->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function correct(Request $request, string $division, string $id, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['learner_id' => ['required', 'uuid'], 'exam' => ['nullable', 'numeric'], 'reason' => ['required', 'string', 'max:255']]);
        $exam = $request->input('exam');
        $actions->correct(self::user($request), $division, $id, $request->string('learner_id')->toString(), is_numeric($exam) ? (float) $exam : null,
            $request->string('reason')->trim()->toString());

        return response()->json(['saved' => true]);
    }

    /**
     * The attendance bar of a semester.
     *
     * @response array{period: array{id: string, name: string}, bar_percent: int, bar_days: int, checked_on: string, rows: list<array{offering_id: string, code: string, title: string, learner_id: string, name: string, number: string, held: int, attended: int, rate: int, lifted: string|null}>, close: int, can_lift: bool}
     */
    public function bar(Request $request, string $division, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period' => ['nullable', 'uuid']]);

        return response()->json($views->bar(self::user($request), $division, self::text($request, 'period')));
    }

    /**
     * The examiners' board: each programme and year.
     *
     * @response array{period: array{id: string, name: string}, cohorts: list<array{id: string, label: string, courses: int, states: array<string, int>}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool}}
     */
    public function board(Request $request, string $division, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period' => ['nullable', 'uuid']]);

        return response()->json($views->board(self::user($request), $division, self::text($request, 'period')));
    }

    /**
     * One programme and year at the board: every student's grades, GPAs and decision.
     *
     * @response array{period: array{id: string, name: string}, cohort: array{id: string, label: string}, courses: list<array{offering_id: string, code: string, title: string, credits: int, sheet_id: string|null, state: string}>, students: list<array{learner_id: string, name: string, number: string, courses: array<string, array{mark: int|null, grade: string|null, result: string}>, gpa: float|null, cumulative: float|null, decision: string, supplementary: int, deferred: int}>, summary: array{students: int, passed: int, supplementary: int, deferred: int, average_gpa: float|null}, waiting: int, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool}}
     */
    public function cohort(Request $request, string $division, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period' => ['nullable', 'uuid'], 'cohort' => ['required', 'string', 'max:80']]);

        return response()->json($views->cohort(self::user($request), $division, self::text($request, 'period'), $request->string('cohort')->toString()));
    }

    /** @response array{approved: int} */
    public function approve(Request $request, string $division, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period_id' => ['required', 'uuid'], 'cohort' => ['required', 'string', 'max:80']]);

        return response()->json(['approved' => $actions->approveCohort(self::user($request), $division, $request->string('period_id')->toString(), $request->string('cohort')->toString())]);
    }

    /**
     * Release: each sitting's progress and the releases made.
     *
     * @response array{period: array{id: string, name: string}, sittings: list<array{sitting: string, states: array<string, int>, waiting: list<array{code: string, title: string, state: string}>}>, releases: list<array{sitting: string, sheets: int, sms: bool, by: string, at: string}>, supplementary: array{sheets: int, candidates: int, exam_on: string|null}, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool}}
     */
    public function releasePage(Request $request, string $division, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period' => ['nullable', 'uuid']]);

        return response()->json($views->release(self::user($request), $division, self::text($request, 'period')));
    }

    /** @response array{released: int} */
    public function release(Request $request, string $division, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period_id' => ['required', 'uuid'], 'sitting' => ['required', 'in:main,supplementary'], 'sms' => ['nullable', 'boolean']]);

        return response()->json(['released' => $actions->release(self::user($request), $division, $request->string('period_id')->toString(), $request->string('sitting')->toString(),
            $request->boolean('sms'))]);
    }

    /** @response array{opened: int} */
    public function supplementary(Request $request, string $division, SheetActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period_id' => ['required', 'uuid'], 'exam_on' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['opened' => $actions->prepareSupplementary(self::user($request), $division, $request->string('period_id')->toString(), self::text($request, 'exam_on'))]);
    }

    /**
     * The grading scale and settings.
     *
     * @response array{scale: array{id: string|null, label: string, pass_mark: int, bands: list<array{grade: string, min: int, max: int, points: float}>, from_on: string|null}, saved: list<array{id: string|null, label: string, pass_mark: int, bands: list<array{grade: string, min: int, max: int, points: float}>, from_on: string|null}>, standard: array{id: string|null, label: string, pass_mark: int, bands: list<array{grade: string, min: int, max: int, points: float}>, from_on: string|null}, settings: array{bar_percent: int, bar_days: int, hold_slip_for_fees: bool}, periods: list<array{id: string, name: string}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool}}
     */
    public function setup(Request $request, string $division, ResultsViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json($views->setup(self::user($request), $division));
    }

    /** @response 201 array{id: string} */
    public function saveScale(Request $request, string $division, SetupActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period_id' => ['required', 'uuid'], 'label' => ['nullable', 'string', 'max:80'], 'pass_mark' => ['required', 'integer'],
            'bands' => ['required', 'array', 'min:2', 'max:20'], 'bands.*.grade' => ['required', 'string', 'max:4'], 'bands.*.min' => ['required', 'integer'],
            'bands.*.max' => ['required', 'integer'], 'bands.*.points' => ['required', 'numeric']]);
        $bands = [];
        foreach ((array) $request->input('bands', []) as $b) {
            $b = (array) $b;
            $bands[] = ['grade' => self::str($b['grade'] ?? null), 'min' => (int) self::num($b['min'] ?? null), 'max' => (int) self::num($b['max'] ?? null), 'points' => self::num($b['points'] ?? null) ?? 0.0];
        }

        return response()->json(['id' => $actions->saveScale(self::user($request), $division, $request->string('period_id')->toString(), $request->string('label')->toString(),
            $request->integer('pass_mark'), $bands)], 201);
    }

    /** @response array{saved: bool} */
    public function saveSettings(Request $request, string $division, SetupActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['bar_percent' => ['required', 'integer'], 'bar_days' => ['required', 'integer'], 'hold_slip_for_fees' => ['required', 'boolean']]);
        $actions->saveSettings(self::user($request), $division, $request->integer('bar_percent'), $request->integer('bar_days'), $request->boolean('hold_slip_for_fees'));

        return response()->json(['saved' => true]);
    }

    /**
     * The Results tab of the learner record.
     *
     * @response array{semesters: list<array{period: array{id: string, name: string, starts_on: string}, division_id: string, courses: list<array{offering_id: string, code: string, title: string, credits: int, ca: float|null, exam: float|null, mark: int|null, grade: string|null, points: float|null, result: string, sitting: string, released: bool, changed: bool}>, credits: int, earned: int, gpa: float|null, decision: string, supplementary: int, deferred: int}>, cumulative: float|null, credits: int, open: int}
     */
    public function learner(Request $request, string $learner, ResultsParts $parts): JsonResponse
    {
        return response()->json($parts->tab(self::user($request), $learner));
    }

    /** A student's results slip for a semester, as an A4 PDF with its check code. */
    public function slip(Request $request, string $learner, ResultsParts $parts, ResultsSlip $slip): Response
    {
        $who = self::user($request);
        $parts->tab($who, $learner);
        $request->validate(['period' => ['required', 'uuid']]);
        $doc = $slip->render($who, $learner, $request->string('period')->toString());

        return self::pdf($doc);
    }

    /** @param  array{name: string, pdf: string}  $doc */
    public static function pdf(array $doc): Response
    {
        return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $doc['name'], 'results-slip.pdf'), 'Cache-Control' => 'no-store']);
    }

    private static function sitting(Request $request): string
    {
        $s = $request->input('sitting', 'main');

        return in_array($s, ['main', 'supplementary'], true) ? $s : throw new NotFoundHttpException;
    }

    /** @return list<array{learner_id: string, exam: float|null, reason: string}> */
    private static function changes(Request $request): array
    {
        $out = [];
        foreach ((array) $request->input('changes', []) as $c) {
            $c = (array) $c;
            $out[] = ['learner_id' => self::str($c['learner_id'] ?? null), 'exam' => self::num($c['exam'] ?? null), 'reason' => self::str($c['reason'] ?? null)];
        }

        return $out;
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function text(Request $request, string $key): ?string
    {
        $v = $request->input($key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    private static function user(Request $request): User
    {
        $u = $request->user();

        return $u instanceof User ? $u : throw new AuthenticationException;
    }
}
