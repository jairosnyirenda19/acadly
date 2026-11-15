<?php

declare(strict_types=1);

use Acadly\Core\Institution\InstitutionCore;
use Acadly\Modules\Attendance\AttendanceModule;

return [

    'version' => env('ACADLY_VERSION', '0.0.0-stage1'),

    /*
    | The school this deployment serves, as the sign-in screens show it.
    | It moves to the institution record when that is built.
    */
    'school_name' => env('ACADLY_SCHOOL_NAME', 'School'),

    /*
    | The public key that verifies the licence IQ-X issues (Ed25519, base64).
    | With no key on a development machine, every module is licensed.
    */
    'licence_public_key' => env('ACADLY_LICENCE_PUBLIC_KEY'),

    /*
    | Where people use the console. Password reset links point here.
    */
    'frontend_url' => env('ACADLY_FRONTEND_URL', 'http://localhost:5173'),

    /*
    | Compiled blueprints and the lock of released versions (02 §1). Made by
    | blueprint:compile and blueprint:release; read by the journey engine.
    */
    'blueprints' => [
        'compiled' => base_path('blueprints/compiled'),
        'lock' => base_path('blueprints/blueprints.lock'),
    ],

    /*
    | Every Core domain and every Module in this build, by manifest class.
    | A module that is not listed here does not exist for the application.
    */
    'modules' => [
        InstitutionCore::class,
        AttendanceModule::class,
    ],

];
