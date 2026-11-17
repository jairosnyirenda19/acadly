<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Enrolment\Contracts\ReportCardLine;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Modules\Attendance\Application\AttendanceFamilyLine;
use Acadly\Modules\Attendance\Application\AttendanceReportLine;
use Acadly\Modules\Attendance\Application\CorrectionApproval;
use Acadly\Modules\Attendance\Application\LateRegisterApproval;
use Acadly\Modules\Attendance\Application\LearnerAttendance;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Attendance: tables and routes. Every route needs the module on. */
final class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LearnerAttendance::class);
        $this->app->tag([LearnerAttendance::class], LearnerRecordPart::TAG);
        $this->app->tag([CorrectionApproval::class, LateRegisterApproval::class], ApprovalTopic::TAG);
        $this->app->tag([AttendanceReportLine::class], ReportCardLine::TAG);
        $this->app->tag([AttendanceFamilyLine::class], FamilyLine::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
