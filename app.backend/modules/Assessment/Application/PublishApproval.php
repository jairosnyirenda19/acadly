<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\OptionalApproval;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Assessment\Application\Actions\PublishResults;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Support\Facades\DB;

/**
 * Publishing a class's term results, in the Approvals catalogue (D-081). No
 * steps until the school sets some: then Publish asks, and the last yes
 * publishes the results as they are at that moment.
 */
final class PublishApproval implements OptionalApproval
{
    public const string KEY = 'assessment.publish';

    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly Teaching $teaching,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'assessment';
    }

    public function area(): string
    {
        return 'Assessment';
    }

    public function title(): string
    {
        return 'Publishing results';
    }

    public function description(): string
    {
        return 'Term results and report cards reach families. With no steps, results publish at once.';
    }

    public function permission(): Permission
    {
        return AssessmentPermission::Publish;
    }

    public function relations(): array
    {
        return ['class_teacher' => 'The class teacher'];
    }

    public function facts(): array
    {
        return [];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $row = Ids::isValid($subjectId) ? DB::table('result_publish_requests')->where('id', $subjectId)->first() : null;

        if ($row === null) {
            return null;
        }

        $classId = Row::text($row, 'class_id');
        $class = $this->calendar->classNames([$classId])[$classId]['name'] ?? '';
        $period = $this->teaching->period(Row::text($row, 'period_id'))['name'] ?? '';
        $classTeacher = $this->calendar->classTeacherId($classId);

        return new ApprovalCase(
            requesterId: Row::text($row, 'requested_by'),
            at: ScopeRef::of(ScopeType::ClassGroup, $classId),
            title: 'Publish results · '.$class,
            where: $period,
            reason: null,
            link: '/marks/publish',
            related: ['class_teacher' => $classTeacher !== null ? [$classTeacher] : []],
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        if ($approved) {
            app(PublishResults::class)->settle($by, $subjectId);
        }
    }
}
