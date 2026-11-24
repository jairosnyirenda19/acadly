<?php

declare(strict_types=1);

namespace Acadly\Modules\Attendance;

use Acadly\Core\Enrolment\Contracts\DivisionFigures;
use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\LearnerRecordPart;
use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Acadly\Core\Enrolment\Contracts\ReportCardLine;
use Acadly\Core\Enrolment\Contracts\YearEndCheck;
use Acadly\Core\Institution\Contracts\DashboardPanels;
use Acadly\Core\Institution\Contracts\TodayItems;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Modules\Attendance\Application\AttendanceDashboard;
use Acadly\Modules\Attendance\Application\AttendanceFamilyLine;
use Acadly\Modules\Attendance\Application\AttendanceFigures;
use Acadly\Modules\Attendance\Application\AttendanceReportLine;
use Acadly\Modules\Attendance\Application\AttendanceSignals;
use Acadly\Modules\Attendance\Application\AttendanceToday;
use Acadly\Modules\Attendance\Application\AttendanceYearEnd;
use Acadly\Modules\Attendance\Application\CorrectionApproval;
use Acadly\Modules\Attendance\Application\LateRegisterApproval;
use Acadly\Modules\Attendance\Application\LearnerAttendance;
use Acadly\Modules\Attendance\Application\UnexplainedLessons;
use Acadly\Modules\Attendance\Contracts\MissedLessons;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Attendance: tables and routes. Every route needs the module on. */
final class AttendanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LearnerAttendance::class);
        $this->app->bind(MissedLessons::class, UnexplainedLessons::class);
        $this->app->tag([LearnerAttendance::class], LearnerRecordPart::TAG);
        $this->app->tag([AttendanceSignals::class], LearnerSignals::TAG);
        $this->app->tag([CorrectionApproval::class, LateRegisterApproval::class], ApprovalTopic::TAG);
        $this->app->tag([AttendanceReportLine::class], ReportCardLine::TAG);
        $this->app->tag([AttendanceFamilyLine::class], FamilyLine::TAG);
        $this->app->tag([AttendanceYearEnd::class], YearEndCheck::TAG);
        $this->app->tag([AttendanceFigures::class], DivisionFigures::TAG);
        $this->app->tag([AttendanceDashboard::class], DashboardPanels::TAG);
        $this->app->tag([AttendanceToday::class], TodayItems::TAG);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/Http/routes.php');
    }
}
