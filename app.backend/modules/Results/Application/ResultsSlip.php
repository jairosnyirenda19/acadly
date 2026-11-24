<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application;

use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Documents\Pdf\PdfRenderer;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The results slip (D-105): a student's released results for one semester,
 * with the semester and cumulative GPA, drawn by the document engine with a
 * check code. A change after release issues the next version.
 *
 * @phpstan-import-type Semester from Standing
 */
final class ResultsSlip
{
    public const string KIND = 'results.slip';

    private const array RESULTS = ['pass' => 'Pass', 'fail' => 'Supplementary', 'barred' => 'Barred', 'deferred' => 'Deferred', 'repeat' => 'Repeat', 'missing' => 'Pending'];

    public function __construct(
        private readonly Standing $standing,
        private readonly CourseTeaching $teaching,
        private readonly PdfRenderer $renderer,
        private readonly DocumentChecks $checks,
        private readonly SchoolProfile $school,
    ) {}

    /** @return array{name: string, pdf: string} */
    public function render(User $viewer, string $learnerId, string $periodId): array
    {
        $history = $this->standing->history($learnerId, true);
        $sem = array_values(array_filter($history['semesters'], fn (array $s): bool => $s['period']['id'] === $periodId))[0] ?? null;
        if ($sem === null) {
            throw ValidationException::withMessages(['period' => ['No results of this semester are released.']]);
        }
        $file = $this->teaching->studentFile($learnerId) ?? throw new NotFoundHttpException;
        $offerings = array_column($sem['courses'], 'offering_id');
        $version = 1 + DB::table('results_marks as m')->join('results_sheets as s', 's.id', '=', 'm.sheet_id')->whereIn('s.offering_id', $offerings ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('m.learner_id', $learnerId)->where('m.stage', 'correction')->count();
        $released = DB::table('results_sheets')->whereIn('offering_id', $offerings ?: ['00000000-0000-0000-0000-000000000000'])->max('released_at');
        $issued = is_string($released) ? CarbonImmutable::parse($released) : CarbonImmutable::now();
        $reference = 'RR-'.$file['number'].'-'.strtoupper(substr(str_replace('-', '', $periodId), -4)).'-'.$version;
        $code = $this->checks->codeFor(self::KIND, $reference, $issued->toDateString(), $viewer->id);

        $rows = array_map(fn (array $c): array => [$c['code'], $c['title'].($c['sitting'] === 'supplementary' ? ' (supplementary)' : ''), (string) $c['credits'],
            $c['mark'] === null ? '–' : (string) $c['mark'], $c['grade'] ?? '–', self::RESULTS[$c['result']] ?? $c['result']], $sem['courses']);

        $instruction = [
            'title' => 'Results slip · '.$sem['period']['name'],
            'reference' => $reference,
            'status' => 'Version '.$version,
            'sections' => [
                ['kind' => 'facts', 'heading' => 'Student', 'columns' => 2, 'items' => [
                    ['label' => 'Student', 'value' => $file['name']],
                    ['label' => 'Student number', 'value' => $file['number']],
                    ['label' => 'Programme', 'value' => $file['programme_code'].' '.$file['programme']],
                    ['label' => 'Year of study', 'value' => 'Year '.$file['year_of_study']],
                ]],
                ['kind' => 'table', 'heading' => 'Courses', 'columns' => ['Code', 'Course', 'Credits', 'Mark', 'Grade', 'Result'], 'align' => ['mono', 'left', 'right', 'right', 'left', 'left'],
                    'weights' => [1.1, 3.6, 0.8, 0.8, 0.8, 1.4], 'rows' => $rows],
                ['kind' => 'facts', 'columns' => 3, 'items' => [
                    ['label' => 'Semester GPA', 'value' => $sem['gpa'] === null ? '–' : number_format($sem['gpa'], 2)],
                    ['label' => 'Cumulative GPA', 'value' => $history['cumulative'] === null ? '–' : number_format($history['cumulative'], 2)],
                    ['label' => 'Credits earned', 'value' => $sem['earned'].' of '.array_sum(array_column($sem['courses'], 'credits'))],
                ]],
                ['kind' => 'text', 'text' => 'These results were approved by the examiners\' board and released on '.$issued->format('j F Y').'. A change after release issues a new version of this slip.'],
                // The check code and its picture are drawn with the signature (D-107).
                ['kind' => 'signatures', 'signed_by' => 'Registrar'],
            ],
            'check' => ['code' => $code, 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Results slip '.$reference,
        ];

        return ['name' => 'Results slip '.$reference.'.pdf', 'pdf' => $this->renderer->render($instruction)];
    }

    private static function checkUrl(): string
    {
        $base = config('acadly.frontend_url');

        return rtrim(is_string($base) ? $base : '', '/').'/check';
    }
}
