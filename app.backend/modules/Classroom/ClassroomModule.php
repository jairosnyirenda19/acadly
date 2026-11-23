<?php

declare(strict_types=1);

namespace Acadly\Modules\Classroom;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Classroom\Contracts\ClassroomAudit;
use Acadly\Modules\Classroom\Contracts\ClassroomDocument;
use Acadly\Modules\Classroom\Contracts\ClassroomPermission;
use Acadly\Modules\Classroom\Notifications\CourseAnnounced;
use Acadly\Modules\Programmes\ProgrammesModule;

/**
 * Classroom (D-104): a page for each course offering. Resources and
 * announcements; assignments and exam papers that students hand in on the
 * phone or a computer, with withdraw and hand in again before the deadline;
 * marking with feedback, released together; and each course's continuous
 * assessment, which the results of P6 build on.
 */
final class ClassroomModule implements Module
{
    public function key(): string
    {
        return 'classroom';
    }

    public function name(): string
    {
        return 'Classroom';
    }

    public function summary(): string
    {
        return 'Course pages: resources, announcements, assignments and exam papers, hand-ins, marking and continuous assessment.';
    }

    public function tier(): Tier
    {
        return Tier::Optional;
    }

    public function requires(): array
    {
        return [InstitutionCore::class, AcademicsCore::class, EnrolmentCore::class, ProgrammesModule::class];
    }

    public function permissions(): array
    {
        return ClassroomPermission::cases();
    }

    public function auditActions(): array
    {
        return ClassroomAudit::cases();
    }

    public function events(): array
    {
        return [];
    }

    public function subscriptions(): array
    {
        return [];
    }

    public function journeys(): array
    {
        return [];
    }

    public function topics(): array
    {
        return [CourseAnnounced::class];
    }

    public function documentKinds(): array
    {
        return ClassroomDocument::cases();
    }
}
