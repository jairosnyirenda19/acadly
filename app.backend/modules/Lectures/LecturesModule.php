<?php

declare(strict_types=1);

namespace Acadly\Modules\Lectures;

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Kernel\Module\Module;
use Acadly\Kernel\Module\Tier;
use Acadly\Modules\Lectures\Contracts\LecturesAudit;
use Acadly\Modules\Lectures\Contracts\LecturesPermission;
use Acadly\Modules\Lectures\Notifications\CoverAsked;
use Acadly\Modules\Lectures\Notifications\LectureChanged;
use Acadly\Modules\Programmes\ProgrammesModule;

/**
 * Lectures (D-102): a college's rooms, the lecture timetable of each
 * semester, cover when a lecturer cannot come, and lecture attendance taken
 * by the students themselves: they scan the changing code on the room screen
 * with their own attendance phone, on campus.
 */
final class LecturesModule implements Module
{
    public function key(): string
    {
        return 'lectures';
    }

    public function name(): string
    {
        return 'Lectures';
    }

    public function summary(): string
    {
        return 'Rooms, the lecture timetable, cover, and lecture attendance by scan-in.';
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
        return LecturesPermission::cases();
    }

    public function auditActions(): array
    {
        return LecturesAudit::cases();
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
        return [LectureChanged::class, CoverAsked::class];
    }

    public function documentKinds(): array
    {
        return [];
    }
}
