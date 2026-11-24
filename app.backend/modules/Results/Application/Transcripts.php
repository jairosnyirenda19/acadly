<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Documents\Pdf\PdfRenderer;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The transcript (D-106): every semester's released results, every attempt,
 * the semester and cumulative GPAs and, once confirmed, the award. The
 * student's own copy says so and has no check code; an official transcript
 * is issued by the registry, kept as a row, and carries a check code.
 */
final class Transcripts
{
    public const string KIND = 'results.transcript';

    private const array RESULTS = ['pass' => '', 'fail' => 'Failed', 'barred' => 'Barred', 'deferred' => 'Deferred', 'repeat' => 'Failed', 'missing' => 'Pending'];

    public function __construct(
        private readonly Standing $standing,
        private readonly Awards $awards,
        private readonly CourseTeaching $teaching,
        private readonly PdfRenderer $renderer,
        private readonly DocumentChecks $checks,
        private readonly SchoolProfile $school,
    ) {}

    /**
     * The student's own copy.
     *
     * @return array{name: string, pdf: string}
     */
    public function copy(string $learnerId): array
    {
        return $this->render(null, $learnerId, null, CarbonImmutable::now());
    }

    /**
     * An official transcript issued before, drawn again as issued.
     *
     * @return array{name: string, pdf: string}
     */
    public function issued(User $viewer, string $transcriptId, ?string $learnerId = null): array
    {
        $t = DB::table('results_transcripts')->where('id', $transcriptId)->first() ?? throw new NotFoundHttpException;
        if ($learnerId !== null && Row::text($t, 'learner_id') !== $learnerId) {
            throw new NotFoundHttpException;
        }

        return $this->render($viewer, Row::text($t, 'learner_id'), Row::text($t, 'reference'), CarbonImmutable::parse(Row::text($t, 'issued_at')));
    }

    /**
     * Requests and issued transcripts for the registry, newest first.
     *
     * @param  list<string>|null  $learners  only these learners, or every one
     * @return array{requests: list<array{id: string, learner_id: string, name: string, number: string, for_kind: string, organisation: string|null, email: string|null, purpose: string|null, at: string, issued: array{id: string, reference: string, at: string, by: string}|null}>, issued: list<array{id: string, learner_id: string, name: string, number: string, reference: string, at: string, by: string}>}
     */
    public function desk(string $divisionId, ?array $learners = null): array
    {
        $issued = [];
        $byRequest = [];
        $q = DB::table('results_transcripts as t')->join('users as u', 'u.id', '=', 't.issued_by')->where('t.division_id', $divisionId)->orderByDesc('t.issued_at');
        if ($learners !== null) {
            $q->whereIn('t.learner_id', $learners === [] ? ['00000000-0000-0000-0000-000000000000'] : $learners);
        }
        foreach ($q->limit(200)->get(['t.id', 't.learner_id', 't.request_id', 't.reference', 't.issued_at', 'u.name']) as $t) {
            $file = $this->teaching->studentFile(Row::text($t, 'learner_id'));
            $row = ['id' => Row::text($t, 'id'), 'learner_id' => Row::text($t, 'learner_id'), 'name' => $file['name'] ?? '', 'number' => $file['number'] ?? '',
                'reference' => Row::text($t, 'reference'), 'at' => CarbonImmutable::parse(Row::text($t, 'issued_at'))->toIso8601String(), 'by' => Row::text($t, 'name')];
            $issued[] = $row;
            if (($t->request_id ?? null) !== null) {
                $byRequest[Row::text($t, 'request_id')] ??= ['id' => $row['id'], 'reference' => $row['reference'], 'at' => $row['at'], 'by' => $row['by']];
            }
        }
        $requests = [];
        $r = DB::table('results_transcript_requests')->where('division_id', $divisionId)->orderByDesc('created_at');
        if ($learners !== null) {
            $r->whereIn('learner_id', $learners === [] ? ['00000000-0000-0000-0000-000000000000'] : $learners);
        }
        foreach ($r->limit(200)->get() as $q2) {
            $file = $this->teaching->studentFile(Row::text($q2, 'learner_id'));
            $requests[] = ['id' => Row::text($q2, 'id'), 'learner_id' => Row::text($q2, 'learner_id'), 'name' => $file['name'] ?? '', 'number' => $file['number'] ?? '',
                'for_kind' => Row::text($q2, 'for_kind'), 'organisation' => Row::maybeText($q2, 'organisation'), 'email' => Row::maybeText($q2, 'email'),
                'purpose' => Row::maybeText($q2, 'purpose'), 'at' => CarbonImmutable::parse(Row::text($q2, 'created_at'))->toIso8601String(),
                'issued' => $byRequest[Row::text($q2, 'id')] ?? null];
        }

        return ['requests' => $requests, 'issued' => $issued];
    }

    /** @return array{name: string, pdf: string} */
    private function render(?User $viewer, string $learnerId, ?string $reference, CarbonImmutable $issued): array
    {
        $file = $this->teaching->studentFile($learnerId) ?? throw new NotFoundHttpException;
        $history = $this->standing->history($learnerId, true);
        $award = $this->awards->of($learnerId);
        $sections = [
            ['kind' => 'facts', 'heading' => 'Student', 'columns' => 3, 'items' => array_values(array_filter([
                ['label' => 'Student', 'value' => $file['name']],
                ['label' => 'Student number', 'value' => $file['number']],
                ['label' => 'Programme', 'value' => $file['programme_code'].' '.$file['programme']],
                $award !== null ? ['label' => 'Award', 'value' => $award['programme'].($award['class'] !== null ? ' · '.$award['class'] : '')] : null,
                $award !== null && $award['graduation_on'] !== null && $award['state'] === 'conferred' ? ['label' => 'Conferred', 'value' => CarbonImmutable::parse($award['graduation_on'])->format('j F Y')] : null,
            ]))],
        ];
        foreach (array_reverse($history['semesters']) as $s) {
            $rows = [];
            foreach ($s['courses'] as $c) {
                $note = array_values(array_filter([$c['sitting'] === 'supplementary' && $c['result'] === 'pass' ? 'Supplementary' : null, $c['counts'] ? null : 'Repeated',
                    $c['attempt'] > 1 ? 'Attempt '.$c['attempt'] : null, self::RESULTS[$c['result']] ?? null]));
                $rows[] = [$c['code'], $c['title'], (string) $c['credits'], $c['grade'] ?? '–', $c['points'] === null ? '–' : number_format($c['points'], 1), implode(' · ', $note)];
            }
            $sections[] = ['kind' => 'table', 'heading' => $s['period']['name'].' · Semester GPA '.($s['gpa'] === null ? '–' : number_format($s['gpa'], 2)).' · Cumulative '.($s['cgpa'] === null ? '–' : number_format($s['cgpa'], 2)),
                'columns' => ['Code', 'Course', 'Credits', 'Grade', 'Points', ''], 'align' => ['mono', 'left', 'right', 'left', 'right', 'left'], 'weights' => [1.1, 3.4, 0.8, 0.7, 0.7, 1.6], 'rows' => $rows];
        }
        $sections[] = ['kind' => 'facts', 'columns' => 3, 'items' => [
            ['label' => 'Credits passed', 'value' => (string) $history['earned']],
            ['label' => 'Cumulative GPA', 'value' => $history['cumulative'] === null ? '–' : number_format($history['cumulative'], 2)],
            ['label' => 'Class of award', 'value' => $award['class'] ?? '–'],
        ]];
        $sections[] = ['kind' => 'text', 'text' => 'GPA = grade points × credits ÷ credits. A supplementary pass is recorded at the pass mark. A repeated course shows each attempt; the latest counts.'];
        if ($reference === null) {
            $sections[] = ['kind' => 'text', 'text' => 'Student copy. This is not an official transcript; the registry issues official transcripts with a check code.'];
        } else {
            // The check code and its picture are drawn with the signature (D-107).
            $sections[] = ['kind' => 'signatures', 'signed_by' => 'Registrar'];
        }
        $instruction = ['title' => $reference === null ? 'Transcript · student copy' : 'Official transcript', 'reference' => $reference ?? $file['number'],
            'status' => $reference === null ? 'Student copy' : 'Issued '.$issued->format('j F Y'), 'sections' => $sections,
            'footer' => $this->school->name().' · '.($reference === null ? 'Transcript, student copy' : 'Official transcript '.$reference)];
        if ($reference !== null && $viewer !== null) {
            $instruction['check'] = ['code' => $this->checks->codeFor(self::KIND, $reference, $issued->toDateString(), $viewer->id), 'url' => self::checkUrl()];
        }

        return ['name' => ($reference === null ? 'Transcript '.$file['number'] : 'Official transcript '.$reference).'.pdf', 'pdf' => $this->renderer->render($instruction)];
    }

    private static function checkUrl(): string
    {
        $base = config('acadly.frontend_url');

        return rtrim(is_string($base) ? $base : '', '/').'/check';
    }
}
