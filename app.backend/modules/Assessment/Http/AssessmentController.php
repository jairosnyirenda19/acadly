<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Kernel\Grid\Grids;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\Actions\CorrectMark;
use Acadly\Modules\Assessment\Application\Actions\MarksSteps;
use Acadly\Modules\Assessment\Application\Actions\PaperActions;
use Acadly\Modules\Assessment\Application\Actions\SaveMarks;
use Acadly\Modules\Assessment\Application\Actions\SetAssessment;
use Acadly\Modules\Assessment\Application\Assessments;
use Acadly\Modules\Assessment\Application\Sheets;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The Marks screens (the approved designs): a teacher's marks, a new assessment, the marks sheet and the office list. */
final class AssessmentController
{
    public function __construct(
        private readonly Assessments $assessments,
        private readonly Sheets $sheets,
    ) {}

    /**
     * The viewer's assessments this term, with how far each is marked.
     *
     * @response array{today: string, totals: array{all: int, to_finish: int, submitted: int, locked: int}, assessments: list<array{id: string, class_id: string, class: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, learners: int, marked: int, absent: int}>, can_set: bool, looks_after: list<array{class_id: string, class: string}>}
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->assessments->mine(self::user($request)));
    }

    /**
     * The classes and subjects the viewer may set an assessment for, the kinds, and where each kind lands in the term grade.
     *
     * @response array{today: string, kinds: list<array{value: string, label: string}>, choices: list<array{class_id: string, class: string, level: string, subject_id: string, subject: string, period: array{id: string, name: string, starts_on: string, ends_on: string}|null, mode: 'continuous'|'exam_only', lands: list<array{kind: string, counts: bool, part_key: string|null, part_label: string|null, weight: int|null, rule: string|null, others: int}>}>}
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json($this->assessments->options(self::user($request)));
    }

    /**
     * Sets an assessment: `{class_id, subject_id, kind, title, held_on, out_of, counts}`. Answers with its marks sheet.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function store(Request $request, SetAssessment $set): JsonResponse
    {
        $viewer = self::user($request);
        $assessment = $set->handle($viewer, $request->all());

        return response()->json($this->sheets->sheet($viewer, $assessment->id), 201);
    }

    /**
     * The marks sheet: learners, the marks in force, the numbers, the ladder and what the viewer may do.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->sheets->sheet(self::user($request), $id));
    }

    /**
     * Saves marks: `{marks: [{learner_id, value, reason}]}`. Only a mark that changed adds a row.
     *
     * @response array{saved: int, sheet: array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}}
     */
    public function marks(Request $request, string $id, SaveMarks $save): JsonResponse
    {
        $viewer = self::user($request);
        $saved = $save->handle($viewer, $id, self::marksOf($request->input('marks')));

        return response()->json(['saved' => $saved, 'sheet' => $this->sheets->sheet($viewer, $id)]);
    }

    /**
     * Submits the marks.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function submit(Request $request, string $id, MarksSteps $steps): JsonResponse
    {
        $viewer = self::user($request);
        $steps->submit($viewer, $id);

        return response()->json($this->sheets->sheet($viewer, $id));
    }

    /**
     * Locks submitted marks. Never marks the viewer submitted.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function lock(Request $request, string $id, MarksSteps $steps): JsonResponse
    {
        $viewer = self::user($request);
        $steps->lock($viewer, $id);

        return response()->json($this->sheets->sheet($viewer, $id));
    }

    /**
     * Returns submitted marks to the teacher: `{reason}`.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function return(Request $request, string $id, MarksSteps $steps): JsonResponse
    {
        $viewer = self::user($request);
        $steps->return($viewer, $id, self::text($request, 'reason'));

        return response()->json($this->sheets->sheet($viewer, $id));
    }

    /**
     * Corrects a locked mark: `{learner_id, value, reason, note}`.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function correct(Request $request, string $id, CorrectMark $correct): JsonResponse
    {
        $viewer = self::user($request);
        $reason = $request->input('reason');
        $correct->handle($viewer, $id, self::text($request, 'learner_id'), $request->input('value'), is_string($reason) ? $reason : null, self::text($request, 'note'));

        return response()->json($this->sheets->sheet($viewer, $id));
    }

    /**
     * Adds one page of a learner's marked paper: multipart `learner_id`, `file` (a photo or a PDF). Optional. The response is the marks sheet.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function addPaper(Request $request, string $id, PaperActions $papers): JsonResponse
    {
        $viewer = self::user($request);
        // Access before the input is looked at: someone who may not see the assessment learns nothing.
        $this->sheets->find($viewer, $id);
        $request->validate(['learner_id' => ['required', 'uuid'], 'file' => ['required', 'file']]);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['Choose a photo or a PDF.']]);
        }

        $papers->add($viewer, $id, self::text($request, 'learner_id'), $file);

        return response()->json($this->sheets->sheet($viewer, $id));
    }

    /**
     * Moves a page of a paper one place: `{earlier}`. The response is the marks sheet.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function movePaper(Request $request, string $id, PaperActions $papers): JsonResponse
    {
        $viewer = self::user($request);
        $assessment = self::paperAssessment($id);
        $papers->move($viewer, $id, $request->boolean('earlier'));

        return response()->json($this->sheets->sheet($viewer, $assessment));
    }

    /**
     * Removes a page of a paper. It stays recorded. The response is the marks sheet.
     *
     * @response array{assessment: array{id: string, class_id: string, class: string, level: string, subject_id: string, subject: string, kind: string, kind_label: string, title: string, held_on: string, out_of: int, counts: bool, part: array{key: string, label: string, weight: int}|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string, set_by: string}, learners: list<array{id: string, name: string, admission_number: string|null, value: float|null, reason: 'absent'|'excused'|'exempt'|null, percent: float|null, grade: string|null, entered_by: string|null, entered_at: string|null, corrected: bool, note: string|null, paper: list<array{id: string, document_id: string, position: int, type: string}>, correction: array{value: float|null, reason: string|null, note: string, asked_by: string, waiting_for: list<string>, request_id: string}|null}>, stats: array{learners: int, marked: int, missing: int, absent: int, average: float|null, highest: float|null, lowest: float|null, below_half: int}, scale: array{id: string, name: string}|null, ladder: list<array{key: string, label: string, done: bool, current: bool, by: string|null, at: string|null}>, history: list<array{step: string, outcome: string, by: string, at: string, reason: string|null}>, reasons: list<array{value: string, label: string}>, can_enter: bool, can_submit: bool, submit_blocked: array{reason: string, remedy: string}|null, can_lock: bool, can_return: bool, lock_note: string|null, can_correct: bool, correction_asks: bool, can_add_papers: bool}
     */
    public function removePaper(Request $request, string $id, PaperActions $papers): JsonResponse
    {
        $viewer = self::user($request);
        $assessment = self::paperAssessment($id);
        $papers->remove($viewer, $id);

        return response()->json($this->sheets->sheet($viewer, $assessment));
    }

    private static function paperAssessment(string $id): string
    {
        $assessment = DB::table('assessment_papers')->where('id', $id)->value('assessment_id');

        return is_string($assessment) ? $assessment : throw new NotFoundHttpException;
    }

    /**
     * The assessments as a data grid: `?page=&per_page=&sort=&dir=&q=&filter[state]=submitted&filter[level]=division:F3&filter[subject]=id&filter[kind]=mid_term&filter[held]=from..to`.
     *
     * @response array{rows: list<array{id: string, class_id: string, class: string, level: string, subject: string, kind: string, kind_label: string, title: string, teacher: string|null, held_on: string, out_of: int, counts: bool, marked: int, learners: int, average: float|null, state: 'marking'|'submitted'|'returned'|'locked', state_label: string}>, total: int, page: int, per_page: int, pages: int, sort: string, dir: 'asc'|'desc', q: string, filters: array<string, list<string>>, columns: list<array{key: string, label: string, sortable: bool, shown: bool, always: bool}>, filter_options: list<array{key: string, label: string, kind: 'choice'|'dates', options: list<array{value: string, label: string, count: int}>}>, views: list<array{id: string, name: string, state: array<string, mixed>}>, bulk: list<array{key: string, label: string}>, can_export: bool}
     */
    public function grid(Request $request, Grids $grids): JsonResponse
    {
        return response()->json($grids->page(self::user($request), $grids->grid('assessments'), $request->query()));
    }

    /** @return list<array{learner_id: string, value: mixed, reason: string|null}> */
    private static function marksOf(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > SaveMarks::MOST) {
            return [];
        }

        $out = [];

        foreach ($value as $m) {
            $m = is_array($m) ? $m : [];
            $out[] = [
                'learner_id' => is_string($m['learner_id'] ?? null) ? $m['learner_id'] : '',
                'value' => is_int($m['value'] ?? null) || is_float($m['value'] ?? null) || is_string($m['value'] ?? null) ? $m['value'] : null,
                'reason' => is_string($m['reason'] ?? null) ? $m['reason'] : null,
            ];
        }

        return $out;
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
