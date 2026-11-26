<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Actions\LeavingActions;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Documents\Pdf\PdfRenderer;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The transfer letter of a learner who left during the year (D-114): the
 * dates, the class, and the attendance and results the record shows, on the
 * school's paper with a check code the new school can verify. Fees, conduct
 * and support never go on it.
 */
final class TransferLetters
{
    public const string KIND = 'enrolment.transfer';

    /** The record parts a transfer letter carries; nothing else leaves the school. */
    private const array SHARED_PARTS = ['attendance', 'assessment', 'lectures', 'results'];

    public function __construct(
        private readonly Reach $reach,
        private readonly LearnerPlaces $places,
        private readonly AcademicCalendar $calendar,
        private readonly ModuleActivation $activation,
        private readonly Container $container,
        private readonly PdfRenderer $renderer,
        private readonly DocumentChecks $checks,
        private readonly SchoolProfile $school,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @return array{name: string, pdf: string} */
    public function for(User $viewer, string $learnerId): array
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($viewer, $learnerId, EnrolmentPermission::Manage);
        $place = $this->places->current($learner->id);
        $left = $place === null || $place['open'] ? null
            : DB::table('learner_departures')->where('placement_id', $place['id'])->orderByDesc('created_at')->first(['last_day', 'kind', 'destination']);

        if ($left === null) {
            throw ValidationException::withMessages(['learner' => ['A transfer letter is made for a learner who has left.']]);
        }

        $lastDay = substr(Row::text($left, 'last_day'), 0, 10);
        $admitted = DB::table('enrolments')->where('id', $place['enrolment_id'])->value('admitted_on');
        $from = is_string($admitted) ? substr($admitted, 0, 10) : $place['starts_on'];
        $class = $this->calendar->classNames([$place['class_id']])[$place['class_id']]['name'] ?? '';
        $day = fn (string $d): string => CarbonImmutable::parse($d)->format('j F Y');
        $reference = 'TRF-'.$learner->acadly_id.'-'.str_replace('-', '', $lastDay);
        $code = $this->checks->codeFor(self::KIND, $reference, $this->clock->today(), $viewer->id);
        $name = $learner->name();
        $rows = [];

        foreach ($this->container->tagged(LearnerRecordPart::TAG) as $part) {
            if ($part instanceof LearnerRecordPart && in_array($part->key(), self::SHARED_PARTS, true) && $this->activation->isActive($part->key(), $at)) {
                foreach ($part->band($viewer, $learner->id) as $b) {
                    $rows[] = [$b['label'], $b['value'], $b['note']];
                }
            }
        }

        $destination = Row::maybeText($left, 'destination');
        $sections = [
            ['kind' => 'facts', 'heading' => 'Learner', 'columns' => 2, 'items' => [
                ['label' => 'Name', 'value' => $name],
                ['label' => 'Date of birth', 'value' => $day($learner->date_of_birth->toDateString())],
                ['label' => 'Last class', 'value' => $class],
                ['label' => 'Learner code', 'value' => $learner->acadly_id],
                ['label' => 'At the school from', 'value' => $day($from)],
                ['label' => 'Last day', 'value' => $day($lastDay)],
            ]],
            ['kind' => 'text', 'text' => "This is to confirm that {$name} attended {$this->school->name()} from {$day($from)} to {$day($lastDay)}, last in {$class}. "
                .(LeavingActions::KINDS[Row::text($left, 'kind')] ?? '').($destination !== null ? ": {$destination}." : '.')],
        ];

        if ($rows !== []) {
            $sections[] = ['kind' => 'table', 'heading' => 'The record at leaving', 'columns' => ['', 'Figure', 'Note'], 'align' => ['left', 'left', 'left'], 'weights' => [1.4, 1.2, 3], 'rows' => $rows];
        }

        $sections[] = ['kind' => 'signatures', 'signed_by' => 'Head teacher'];
        $pdf = $this->renderer->render([
            'title' => 'Transfer letter',
            'reference' => $reference,
            'status' => 'Original',
            'sections' => $sections,
            'check' => ['code' => $code, 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Transfer letter '.$reference,
        ]);
        $this->audit->record(EnrolmentAudit::TransferLetterMade, subject: $learner, actor: $this->audit->actorFor($viewer), at: $at, meta: ['reference' => $reference]);

        return ['name' => 'Transfer letter '.$name.'.pdf', 'pdf' => $pdf];
    }

    private static function checkUrl(): string
    {
        $base = config('acadly.frontend_url');

        return (is_string($base) ? rtrim($base, '/') : '').'/check';
    }
}
