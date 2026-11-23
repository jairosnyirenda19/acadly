<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Results\Application\Actions\TranscriptActions;
use Acadly\Modules\Results\Application\ResultsSlip;
use Acadly\Modules\Results\Application\StudentResults;
use Acadly\Modules\Results\Application\Transcripts;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Results in the student portal (D-105). Every route is the signed-in student's own. */
final class StudentResultsController
{
    public function __construct(private readonly StudentResults $results) {}

    /**
     * Released results by semester, with the GPAs and the supplementary exams to sit.
     *
     * @response array{semesters: list<array{period: array{id: string, name: string, starts_on: string}, division_id: string, courses: list<array{offering_id: string, course_id: string, attempt: int, counts: bool, code: string, title: string, credits: int, ca: float|null, exam: float|null, mark: int|null, grade: string|null, points: float|null, result: string, sitting: string, released: bool, changed: bool}>, credits: int, earned: int, gpa: float|null, cgpa: float|null, standing: string|null, decision: string, supplementary: int, deferred: int}>, cumulative: float|null, credits: int, earned: int, supplementary: list<array{code: string, title: string, kind: string, exam_on: string|null}>, slip_held: bool, decisions: list<array{period: string, year: int, decision: string, carried: list<string>, cgpa: float|null, reason: string|null, state: string, at: string}>, award: array{programme: string, class: string|null, cgpa: float|null, credits: int, state: string, graduation_on: string|null, certificate: string}|null}
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->results->results(self::user($request)));
    }

    /**
     * One course's released result, with the parts of its continuous assessment.
     *
     * @response array{course: array{id: string, code: string, title: string, credits: int, period: string}, line: array{learner_id: string, name: string, number: string, ca: float, share: int, held: int, attended: int, rate: int|null, barred: bool, lifted: string|null, exam: float|null, absence: string|null, mark: int|null, grade: string|null, points: float|null, result: string, kind: string, changed: bool}, first: array{learner_id: string, name: string, number: string, ca: float, share: int, held: int, attended: int, rate: int|null, barred: bool, lifted: string|null, exam: float|null, absence: string|null, mark: int|null, grade: string|null, points: float|null, result: string, kind: string, changed: bool}|null, parts: list<array{title: string, weight: float, mark: float|null, out_of: int, earned: float}>, pass_mark: int}
     */
    public function course(Request $request, string $id): JsonResponse
    {
        return response()->json($this->results->course(self::user($request), $id));
    }

    /**
     * Attendance this semester against the bar.
     *
     * @response array{bar_percent: int, courses: list<array{code: string, title: string, held: int, attended: int, rate: int, state: string}>}
     */
    public function attendance(Request $request): JsonResponse
    {
        return response()->json($this->results->attendance(self::user($request)));
    }

    /** The student's own results slip for a semester. */
    public function slip(Request $request, ResultsSlip $slip): Response
    {
        $who = self::user($request);
        $student = $this->results->student($who);
        $request->validate(['period' => ['required', 'uuid']]);
        if ($this->results->slipHeld($student)) {
            throw ValidationException::withMessages(['period' => ['The results slip is issued once the fees are paid. Your results are shown here.']]);
        }

        return ResultsController::pdf($slip->render($who, $student['learner_id'], $request->string('period')->toString()));
    }

    /** The student's own copy of the transcript: every released result, marked as a student copy. */
    public function transcriptCopy(Request $request, Transcripts $transcripts): Response
    {
        return ResultsController::pdf($transcripts->copy($this->results->student(self::user($request))['learner_id']));
    }

    /**
     * The student's requests for an official transcript, and those issued.
     *
     * @response array{requests: list<array{id: string, learner_id: string, name: string, number: string, for_kind: string, organisation: string|null, email: string|null, purpose: string|null, at: string, issued: array{id: string, reference: string, at: string, by: string}|null}>, issued: list<array{id: string, learner_id: string, name: string, number: string, reference: string, at: string, by: string}>}
     */
    public function transcripts(Request $request, Transcripts $transcripts): JsonResponse
    {
        $s = $this->results->student(self::user($request));

        return response()->json($transcripts->desk($s['division_id'], [$s['learner_id']]));
    }

    /** @response 201 array{id: string} */
    public function requestTranscript(Request $request, TranscriptActions $actions): JsonResponse
    {
        $this->results->student(self::user($request));
        $request->validate(['for_kind' => ['required', 'in:self,organisation'], 'organisation' => ['nullable', 'string', 'max:160'], 'email' => ['nullable', 'string', 'max:160'],
            'purpose' => ['nullable', 'string', 'max:255']]);
        $text = fn (string $k): ?string => is_string($v = $request->input($k)) ? $v : null;

        return response()->json(['id' => $actions->request(self::user($request), ['for_kind' => $request->string('for_kind')->toString(), 'organisation' => $text('organisation'),
            'email' => $text('email'), 'purpose' => $text('purpose')])], 201);
    }

    /** An official transcript issued to the student, as a PDF. */
    public function transcript(Request $request, string $id, Transcripts $transcripts): Response
    {
        $who = self::user($request);

        return ResultsController::pdf($transcripts->issued($who, $id, $this->results->student($who)['learner_id']));
    }

    private static function user(Request $request): User
    {
        $u = $request->user();

        return $u instanceof User ? $u : throw new AuthenticationException;
    }
}
