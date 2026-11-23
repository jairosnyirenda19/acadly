<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Results\Application\Actions\ProgressionActions;
use Acadly\Modules\Results\Application\Actions\SetupActions;
use Acadly\Modules\Results\Application\Actions\TranscriptActions;
use Acadly\Modules\Results\Application\BoardViews;
use Acadly\Modules\Results\Application\Progression;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Application\Transcripts;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The examiners' board at the end of the year (D-106): progression by
 * programme and year, the graduation lists, transcripts and the board's
 * rules. Each route checks the division first.
 */
final class BoardController
{
    public function __construct(private readonly Sheets $sheets) {}

    /**
     * The progression lists of the year, by programme and year of study.
     *
     * @response array{period: array{id: string, name: string}, lists: list<array{programme_id: string, year: int, label: string, name: string, students: int, state: string, list_id: string|null}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool, award: bool, transcripts: bool}}
     */
    public function progressions(Request $request, string $division, BoardViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json($views->progressions(self::user($request), $division));
    }

    /**
     * One programme and year: every student's decision.
     *
     * @response array{programme: array{id: string, code: string, name: string, years: int}, year: int, period: string, list: array{id: string, state: string}|null, rows: list<array{learner_id: string, name: string, number: string, cgpa: float|null, credits: int, passed: int, failed_credits: int, failed: list<string>, standing: string|null, proposed: string|null, fallback: string|null, why: string, choice: array{decision: string, reason: string, by: string, at: string}|null, decision: string|null}>, summary: array{proceed: int, carry: int, repeat: int, discontinue: int, open: int}, rules: array{id: string|null, good_cgpa: float, carry_credits: int, max_attempts: int, time_factor: int, classes: array{diploma: list<array{name: string, from: float}>, degree: list<array{name: string, from: float}>}, hold_certificate: bool, from_on: string|null}, steps: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: string|null, remedy: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool, award: bool, transcripts: bool}}
     */
    public function progression(Request $request, string $division, BoardViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['programme' => ['required', 'uuid'], 'year' => ['required', 'integer', 'min:1', 'max:10']]);

        return response()->json($views->progression(self::user($request), $division, $request->string('programme')->toString(), $request->integer('year')));
    }

    /** @response array{saved: bool} */
    public function choose(Request $request, string $division, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['programme_id' => ['required', 'uuid'], 'year' => ['required', 'integer', 'min:1', 'max:10'], 'learner_id' => ['required', 'uuid'],
            'decision' => ['required', 'in:'.implode(',', Progression::CHOICES)], 'reason' => ['required', 'string', 'max:255']]);
        $actions->choose(self::user($request), $division, $request->string('programme_id')->toString(), $request->integer('year'), $request->string('learner_id')->toString(),
            $request->string('decision')->toString(), $request->string('reason')->trim()->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function decide(Request $request, string $division, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['programme_id' => ['required', 'uuid'], 'year' => ['required', 'integer', 'min:1', 'max:10']]);
        $actions->decide(self::user($request), $division, $request->string('programme_id')->toString(), $request->integer('year'));

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function reopen(Request $request, string $division, string $id, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $actions->reopen(self::user($request), $division, $id, $request->string('reason')->trim()->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{applied: int} */
    public function apply(Request $request, string $division, string $id, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json(['applied' => $actions->apply(self::user($request), $division, $id)]);
    }

    /**
     * The graduation lists of the year, by programme.
     *
     * @response array{period: string, lists: list<array{programme_id: string, code: string, name: string, students: int, state: string, list_id: string|null, graduation_on: string|null}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool, award: bool, transcripts: bool}}
     */
    public function graduations(Request $request, string $division, BoardViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json($views->graduations(self::user($request), $division));
    }

    /**
     * One programme's graduation list.
     *
     * @response array{programme: array{id: string, code: string, name: string, award: string, credits: int}, list: array{id: string, state: string, graduation_on: string|null}|null, rows: list<array{learner_id: string, name: string, number: string, cgpa: float|null, credits: int, class: string|null, complete: bool, missing: list<string>, certificate: string}>, summary: array{complete: int, not_complete: int, classes: list<array{name: string, count: int}>}, classes: list<array{name: string, from: float}>, steps: list<array{step: string, title: string, outcomes: list<array{outcome: string, title: string, requires_reason: bool}>, permitted: bool, blocked_reason: string|null, remedy: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool, award: bool, transcripts: bool}}
     */
    public function graduation(Request $request, string $division, BoardViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['programme' => ['required', 'uuid']]);

        return response()->json($views->graduation(self::user($request), $division, $request->string('programme')->toString()));
    }

    /** @response array{saved: bool} */
    public function approveAwards(Request $request, string $division, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['programme_id' => ['required', 'uuid']]);
        $actions->approveAwards(self::user($request), $division, $request->string('programme_id')->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function confirmAwards(Request $request, string $division, string $id, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['confirm' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $reason = $request->input('reason');
        $actions->confirmAwards(self::user($request), $division, $id, $request->boolean('confirm'), is_string($reason) && $reason !== '' ? $reason : null);

        return response()->json(['saved' => true]);
    }

    /** @response array{saved: bool} */
    public function graduationDay(Request $request, string $division, string $id, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['graduation_on' => ['required', 'date_format:Y-m-d']]);
        $actions->graduationDay(self::user($request), $division, $id, $request->string('graduation_on')->toString());

        return response()->json(['saved' => true]);
    }

    /** @response array{conferred: int} */
    public function confer(Request $request, string $division, string $id, ProgressionActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json(['conferred' => $actions->confer(self::user($request), $division, $id)]);
    }

    /**
     * Transcripts asked for and issued.
     *
     * @response array{requests: list<array{id: string, learner_id: string, name: string, number: string, for_kind: string, organisation: string|null, email: string|null, purpose: string|null, at: string, issued: array{id: string, reference: string, at: string, by: string}|null}>, issued: list<array{id: string, learner_id: string, name: string, number: string, reference: string, at: string, by: string}>, can_issue: bool}
     */
    public function transcripts(Request $request, string $division, BoardViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json($views->desk(self::user($request), $division));
    }

    /** @response 201 array{id: string} */
    public function issue(Request $request, string $division, TranscriptActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['learner_id' => ['required', 'uuid'], 'request_id' => ['nullable', 'uuid']]);
        $req = $request->input('request_id');

        return response()->json(['id' => $actions->issue(self::user($request), $division, $request->string('learner_id')->toString(), is_string($req) ? $req : null)], 201);
    }

    /** An issued official transcript, as a PDF. */
    public function transcript(Request $request, string $division, string $id, Transcripts $transcripts): Response
    {
        $who = self::user($request);
        $this->sheets->enter($who, $division);
        if (! $this->sheets->holds($who, ResultsPermission::Transcripts, $division) && ! $this->sheets->sees($who, $division)) {
            throw new AccessDeniedHttpException;
        }

        return ResultsController::pdf($transcripts->issued($who, $id));
    }

    /**
     * The board's rules.
     *
     * @response array{rules: array{id: string|null, good_cgpa: float, carry_credits: int, max_attempts: int, time_factor: int, classes: array{diploma: list<array{name: string, from: float}>, degree: list<array{name: string, from: float}>}, hold_certificate: bool, from_on: string|null}, standard: array{id: string|null, good_cgpa: float, carry_credits: int, max_attempts: int, time_factor: int, classes: array{diploma: list<array{name: string, from: float}>, degree: list<array{name: string, from: float}>}, hold_certificate: bool, from_on: string|null}, periods: list<array{id: string, name: string}>, can: array{setup: bool, release: bool, board: bool, moderate: bool, enter: bool, view: bool, award: bool, transcripts: bool}}
     */
    public function boardRules(Request $request, string $division, BoardViews $views): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);

        return response()->json($views->ruleSet(self::user($request), $division));
    }

    /** @response 201 array{id: string} */
    public function saveRules(Request $request, string $division, SetupActions $actions): JsonResponse
    {
        $this->sheets->enter(self::user($request), $division);
        $request->validate(['period_id' => ['required', 'uuid'], 'good_cgpa' => ['required', 'numeric'], 'carry_credits' => ['required', 'integer'], 'max_attempts' => ['required', 'integer'],
            'time_factor' => ['required', 'integer'], 'hold_certificate' => ['required', 'boolean'], 'classes' => ['required', 'array'],
            'classes.diploma' => ['required', 'array', 'max:8'], 'classes.degree' => ['required', 'array', 'max:8'],
            'classes.*.*.name' => ['required', 'string', 'max:24'], 'classes.*.*.from' => ['required', 'numeric']]);
        $read = function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $c) {
                $c = is_array($c) ? $c : [];
                $out[] = ['name' => is_string($c['name'] ?? null) ? $c['name'] : '', 'from' => is_numeric($c['from'] ?? null) ? (float) $c['from'] : 0.0];
            }

            return $out;
        };
        $classes = $request->input('classes');

        return response()->json(['id' => $actions->saveRules(self::user($request), $division, $request->string('period_id')->toString(), [
            'good_cgpa' => $request->float('good_cgpa'), 'carry_credits' => $request->integer('carry_credits'), 'max_attempts' => $request->integer('max_attempts'),
            'time_factor' => $request->integer('time_factor'), 'hold_certificate' => $request->boolean('hold_certificate'),
            'classes' => ['diploma' => $read(is_array($classes) ? ($classes['diploma'] ?? null) : null), 'degree' => $read(is_array($classes) ? ($classes['degree'] ?? null) : null)],
        ])], 201);
    }

    private static function user(Request $request): User
    {
        $u = $request->user();

        return $u instanceof User ? $u : throw new AuthenticationException;
    }
}
