<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\Results;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Term grades so far, from locked marks (the approved Class results and Learner results designs). */
final class ResultsController
{
    public function __construct(private readonly Results $results) {}

    /**
     * A class's term grades, learner by subject, with what is not ready: `?class_id=&period_id=`.
     *
     * @response array{class: array{id: string, name: string, level: string}, period: array{id: string, name: string, starts_on: string, ends_on: string}, scale: array{id: string, name: string, overall: 'average_percent'|'average_grade'|'best_six', pass_from: string|null}|null, summary_kind: 'average_percent'|'average_grade'|'best_six', subjects: list<array{id: string, name: string, short: string, ready: bool, waiting: string|null}>, learners: list<array{id: string, name: string, grades: list<array{subject_id: string, percent: float|null, grade: string|null, ready: bool, passes: bool|null}>, summary: array{kind: 'average_percent'|'average_grade'|'best_six', value: float|null, of: int}, total: array{total: float, out_of: int, complete: bool}, position: array{position: int, of: int, shared: bool}|null, below_pass: bool}>, totals: array{learners: int, ranked: int, subjects: int, subjects_ready: int, below_pass: int}}
     */
    public function index(Request $request): JsonResponse
    {
        $period = self::text($request, 'period_id');

        return response()->json($this->results->forClass(self::user($request), self::text($request, 'class_id'), $period !== '' ? $period : null));
    }

    /**
     * How one learner's term grade in one subject is made: `?class_id=&learner_id=&subject_id=&period_id=`.
     *
     * @response array{learner: array{id: string, name: string}, subject: array{id: string, name: string}, class: array{id: string, name: string}, period: array{id: string, name: string, starts_on: string, ends_on: string}, scale: array{id: string, name: string}|null, working: array{ready: bool, not_ready: string|null, plan: array{version: int, mode: 'continuous'|'exam_only', default: bool, summary: string}, parts: list<array{key: string, label: string, weight: int, rule: string, rule_label: string, missing: string, missing_label: string, score: float|null, assessments: list<array{id: string, title: string, kind: string, kind_label: string, held_on: string, value: float|null, reason: string|null, out_of: int, percent: float|null, counted: bool, note: string|null}>}>, percent: float|null, grade: string|null, word: string|null, passes: bool|null, pending: list<array{id: string, title: string, state: string}>}}
     */
    public function working(Request $request): JsonResponse
    {
        $period = self::text($request, 'period_id');

        return response()->json($this->results->working(self::user($request), self::text($request, 'class_id'), self::text($request, 'learner_id'),
            self::text($request, 'subject_id'), $period !== '' ? $period : null));
    }

    /**
     * One learner's term so far: a grade per subject, and this term's assessments with the learner's mark once locked.
     *
     * @response array{learner_id: string, class: array{id: string, name: string}, period: array{id: string, name: string, starts_on: string, ends_on: string}|null, scale: array{id: string, name: string, overall: 'average_percent'|'average_grade'|'best_six'}|null, grades: list<array{subject_id: string, subject: string, percent: float|null, grade: string|null, word: string|null, ready: bool, not_ready: string|null, passes: bool|null}>, summary: array{kind: 'average_percent'|'average_grade'|'best_six', value: float|null, of: int}, total: array{total: float, out_of: int, complete: bool}|null, position: array{position: int, of: int, shared: bool}|null, assessments: list<array{id: string, held_on: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, counts: bool, state: 'marking'|'submitted'|'returned'|'locked', locked: bool, out_of: int, value: float|null, reason: string|null, percent: float|null, paper: list<array{id: string, document_id: string, position: int, type: string}>}>, published: array{publication_id: string, version: int, published_at: string, reference: string, subjects: list<array{subject_id: string, subject: string, short: string, percent: float|null, grade: string|null, word: string|null, passes: bool|null, ready: bool}>, total: array{total: float, out_of: int, complete: bool}|null, position: array{position: int, of: int, shared: bool}|null, versions: list<array{publication_id: string, version: int, published_at: string, by: string, reason: string|null}>}|null}
     */
    public function learner(Request $request, string $id): JsonResponse
    {
        return response()->json($this->results->learner(self::user($request), $id));
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
