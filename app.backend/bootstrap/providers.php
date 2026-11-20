<?php

use Acadly\Core\Academics\AcademicsServiceProvider;
use Acadly\Core\Enrolment\EnrolmentServiceProvider;
use Acadly\Core\Institution\InstitutionServiceProvider;
use Acadly\Core\Setup\SetupServiceProvider;
use Acadly\Kernel\KernelServiceProvider;
use Acadly\Kernel\Module\ModuleServiceProvider;
use Acadly\Modules\Assessment\AssessmentServiceProvider;
use Acadly\Modules\Attendance\AttendanceServiceProvider;
use Acadly\Modules\Conduct\ConductServiceProvider;
use Acadly\Modules\Fees\FeesServiceProvider;
use Acadly\Modules\News\NewsServiceProvider;
use Acadly\Modules\Programmes\ProgrammesServiceProvider;
use Acadly\Modules\SafeRelease\SafeReleaseServiceProvider;
use Acadly\Modules\Support\SupportServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    KernelServiceProvider::class,
    InstitutionServiceProvider::class,
    AcademicsServiceProvider::class,
    EnrolmentServiceProvider::class,
    SetupServiceProvider::class,
    AttendanceServiceProvider::class,
    AssessmentServiceProvider::class,
    FeesServiceProvider::class,
    ConductServiceProvider::class,
    SupportServiceProvider::class,
    SafeReleaseServiceProvider::class,
    NewsServiceProvider::class,
    ProgrammesServiceProvider::class,
];
