<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Actions\CorrectLearner;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\Learner;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalRequests;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Correcting a learner's name, date of birth or sex, in the Approvals
 * catalogue (D-081). The details in force stay until the last yes, which
 * applies the correction with the reason that was given. Other details,
 * and contacts, change at once.
 */
final class DetailsApproval implements ApprovalTopic
{
    public const string KEY = 'enrolment.details';

    /** The details that need a yes, with their words. */
    public const array FIELDS = ['first_name' => 'First name', 'other_names' => 'Other names', 'surname' => 'Surname', 'sex' => 'Sex', 'date_of_birth' => 'Date of birth'];

    public function __construct(
        private readonly LearnerPlaces $places,
        private readonly AcademicCalendar $calendar,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'enrolment';
    }

    public function area(): string
    {
        return 'Learners';
    }

    public function title(): string
    {
        return 'Correcting learner details';
    }

    public function description(): string
    {
        return "A change to a learner's name, date of birth or sex. The details in force stay until the last yes.";
    }

    public function permission(): Permission
    {
        return EnrolmentPermission::Manage;
    }

    public function relations(): array
    {
        return ['class_teacher' => 'The class teacher'];
    }

    public function facts(): array
    {
        return ['date_of_birth' => 'the date of birth changes'];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $row = Ids::isValid($subjectId) ? DB::table('learner_detail_requests')->where('id', $subjectId)->first() : null;
        $learner = $row !== null ? Learner::query()->find(Row::text($row, 'learner_id')) : null;
        $at = $learner instanceof Learner ? $this->places->scopeOf($learner->id) : null;

        if ($row === null || ! $learner instanceof Learner || $at === null) {
            return null;
        }

        $changes = self::changes(self::map(Row::text($row, 'was')), self::map(Row::text($row, 'details')));
        $place = $this->places->current($learner->id);
        $class = $place !== null ? ($this->calendar->classNames([$place['class_id']])[$place['class_id']]['name'] ?? '') : '';
        $teacher = $place !== null ? $this->calendar->classTeacherId($place['class_id']) : null;

        return new ApprovalCase(
            requesterId: Row::text($row, 'requested_by'),
            at: $at,
            title: implode(' · ', array_map(fn (array $c): string => "{$c['label']}: {$c['from']} → {$c['to']}", $changes)),
            where: trim($learner->name().' · '.$class, ' ·'),
            reason: Row::text($row, 'reason'),
            link: '/learners/'.$learner->id,
            related: ['class_teacher' => $teacher !== null ? [$teacher] : []],
            facts: ['date_of_birth' => in_array('date_of_birth', array_column($changes, 'field'), true)],
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $row = DB::table('learner_detail_requests')->where('id', $subjectId)->first();

        if ($approved && $row !== null) {
            $d = self::map(Row::text($row, 'details'));
            app(CorrectLearner::class)->handle($by, Row::text($row, 'learner_id'), [
                'first_name' => $d['first_name'] ?? '', 'other_names' => $d['other_names'] ?? null, 'surname' => $d['surname'] ?? '',
                'sex' => $d['sex'] ?? '', 'date_of_birth' => $d['date_of_birth'] ?? '', 'admission_number' => $d['admission_number'] ?? null,
            ], Row::text($row, 'reason'), approved: true);
        }
    }

    /**
     * A correction still waiting for a learner, with what changes and who it waits for.
     *
     * @return array{id: string, changes: list<array{field: string, label: string, from: string, to: string}>, reason: string, asked_by: string, asked_by_id: string, waiting_for: list<string>}|null
     */
    public static function waiting(string $learnerId): ?array
    {
        $row = DB::table('learner_detail_requests as x')->join('approval_requests as r', 'r.subject_id', '=', 'x.id')
            ->where('r.topic_key', self::KEY)->where('r.state', 'waiting')->where('x.learner_id', $learnerId)->first(['x.id', 'x.details', 'x.was', 'x.reason']);
        $pending = $row !== null ? app(ApprovalRequests::class)->pending(self::KEY, Row::text($row, 'id')) : null;

        if ($row === null || $pending === null) {
            return null;
        }

        return ['id' => $pending['id'], 'changes' => self::changes(self::map(Row::text($row, 'was')), self::map(Row::text($row, 'details'))), 'reason' => Row::text($row, 'reason'),
            'asked_by' => $pending['asked_by'], 'asked_by_id' => $pending['asked_by_id'], 'waiting_for' => $pending['waiting_for']];
    }

    /**
     * The details that need a yes and differ: "Surname: Banda → Banda Mwale".
     *
     * @param  array<string, string|null>  $was
     * @param  array<string, string|null>  $now
     * @return list<array{field: string, label: string, from: string, to: string}>
     */
    public static function changes(array $was, array $now): array
    {
        $out = [];

        foreach (self::FIELDS as $field => $label) {
            if (($was[$field] ?? null) !== ($now[$field] ?? null)) {
                $out[] = ['field' => $field, 'label' => $label, 'from' => $was[$field] ?? '—', 'to' => $now[$field] ?? '—'];
            }
        }

        return $out;
    }

    /** @return array<string, string|null> */
    private static function map(string $json): array
    {
        $value = json_decode($json, true);
        $out = [];

        foreach (is_array($value) ? $value : [] as $k => $v) {
            if (is_string($k) && (is_string($v) || $v === null)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
