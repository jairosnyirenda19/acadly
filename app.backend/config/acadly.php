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
    | Documents (D-053). Bytes are stored once per content, encrypted with a
    | key of their own, which is wrapped by `key` (base64 of 32 random bytes).
    | A development or test machine with no key derives one from APP_KEY;
    | anywhere else, uploads are refused until a key is set. `key_id` names
    | the key, so it can be rotated by re-wrapping. The virus scanner is
    | clamd; with `scan` off, nothing is scanned (development only).
    */
    'documents' => [
        'disk' => env('ACADLY_DOCUMENTS_DISK', 'documents'),
        'key' => env('ACADLY_DOCUMENTS_KEY'),
        'key_id' => env('ACADLY_DOCUMENTS_KEY_ID', 'k1'),
        'chunk_size' => (int) env('ACADLY_DOCUMENTS_CHUNK_SIZE', 1024 * 1024),
        'max_upload_bytes' => (int) env('ACADLY_DOCUMENTS_MAX_UPLOAD_BYTES', 50 * 1024 * 1024),
        'trash_days' => (int) env('ACADLY_DOCUMENTS_TRASH_DAYS', 30),
        'scan' => env('ACADLY_VIRUS_SCAN'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        ],
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
