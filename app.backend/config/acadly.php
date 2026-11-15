<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Modules\Attendance\AttendanceModule;

return [

    'version' => env('ACADLY_VERSION', '0.0.0-stage1'),

    /*
    | Where people use the console. Password reset links point here.
    */
    'frontend_url' => env('ACADLY_FRONTEND_URL', 'http://localhost:5173'),

    /*
    | Every Core domain and every Module in this build, by manifest class.
    | A module that is not listed here does not exist for the application.
    */
    'modules' => [
        InstitutionCore::class,
        AttendanceModule::class,
    ],

];
