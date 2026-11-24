<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application\Registration;

use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Documents\Pdf\PdfRenderer;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Modules\Programmes\Contracts\StudyMode;
use Acadly\Modules\Programmes\Domain\Registration;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The registration slip (D-098): a registered student's courses for the
 * semester, drawn by the document engine, with a check code. A change of
 * courses in add and drop issues the next version; each version keeps its code.
 */
final class RegistrationSlip
{
    public const string KIND = 'programmes.registration_slip';

    public function __construct(
        private readonly RegistrationViews $views,
        private readonly PdfRenderer $renderer,
        private readonly DocumentChecks $checks,
        private readonly SchoolProfile $school,
    ) {}

    /** @return array{name: string, pdf: string} */
    public function render(User $viewer, Registration $r): array
    {
        if ($r->state !== State::Registered->value) {
            throw ValidationException::withMessages(['registration' => ['A slip is issued only once the student is registered.']]);
        }

        $v = $this->views->registration($viewer, $r);
        $registered = array_values(array_filter($v['history'], fn (array $h): bool => $h['step'] === 'register'))[0] ?? null;
        $approved = array_values(array_filter($v['history'], fn (array $h): bool => $h['step'] === 'approve' && $h['outcome'] === 'approved'))[0] ?? null;
        $issued = $registered !== null ? CarbonImmutable::parse($registered['at']) : CarbonImmutable::now();
        $reference = 'RS-'.$v['student']['code'].'-'.strtoupper(substr(str_replace('-', '', $r->period_id), -4)).'-'.$r->version;
        $code = $this->checks->codeFor(self::KIND, $reference, $issued->toDateString(), $viewer->id);
        $kinds = ['core' => 'Core', 'elective' => 'Elective', 'repeat' => 'Repeat'];

        $rows = array_map(fn (array $c): array => [$c['course']['code'], $c['course']['title'], $kinds[$c['kind']] ?? $c['kind'], $c['group'], (string) $c['credits']], $v['courses']);
        $rows[] = ['', 'Total', '', '', (string) array_sum(array_column($v['courses'], 'credits'))];
        $fees = $v['fees']['allowed'] !== null ? 'Allowed by '.$v['fees']['allowed']['by'].': '.$v['fees']['allowed']['reason'] : 'Cleared';
        $window = Windows::of($r->period_id);

        $instruction = [
            'title' => 'Registration slip · '.$v['period'],
            'reference' => $reference,
            'status' => 'Version '.$r->version,
            'sections' => [
                ['kind' => 'facts', 'heading' => 'Student', 'columns' => 2, 'items' => [
                    ['label' => 'Student', 'value' => $v['student']['name']],
                    ['label' => 'Learner code', 'value' => $v['student']['code']],
                    ['label' => 'Programme', 'value' => $v['student']['programme']['code'].' '.$v['student']['programme']['name'].' (curriculum '.$v['student']['curriculum'].')'],
                    ['label' => 'Year of study and mode', 'value' => 'Year '.$r->year_of_study.' · '.(StudyMode::tryFrom($v['student']['mode']) ?? StudyMode::FullTime)->label()],
                ]],
                ['kind' => 'table', 'heading' => 'Courses', 'columns' => ['Code', 'Course', 'Kind', 'Group', 'Credits'], 'align' => ['mono', 'left', 'left', 'left', 'right'],
                    'weights' => [1.2, 3.6, 1.2, 0.8, 1], 'rows' => $rows],
                ['kind' => 'facts', 'columns' => 2, 'items' => array_values(array_filter([
                    $approved !== null ? ['label' => 'Approved', 'value' => $approved['by'].', '.CarbonImmutable::parse($approved['at'])->format('j M Y')] : null,
                    ['label' => 'Fees', 'value' => $fees],
                    $window !== null ? ['label' => 'Add and drop until', 'value' => CarbonImmutable::parse($window['add_drop_until'])->format('l j F Y')] : null,
                    $r->late ? ['label' => 'Registration', 'value' => 'Late'] : null,
                ]))],
                ['kind' => 'text', 'text' => 'A change of courses issues a new version of this slip. Earlier versions stay on the student\'s record and are marked as replaced.'],
                // The check code and its picture are drawn with the signature (D-107).
                ['kind' => 'signatures', 'signed_by' => 'Registry'],
            ],
            'check' => ['code' => $code, 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Registration slip '.$reference,
        ];

        return ['name' => 'Registration slip '.$reference.'.pdf', 'pdf' => $this->renderer->render($instruction)];
    }

    private static function checkUrl(): string
    {
        $base = config('acadly.frontend_url');

        return rtrim(is_string($base) ? $base : '', '/').'/check';
    }
}
