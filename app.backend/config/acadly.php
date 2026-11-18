<?php

declare(strict_types=1);

use Acadly\Core\Academics\AcademicsCore;
use Acadly\Core\Enrolment\EnrolmentCore;
use Acadly\Core\Institution\InstitutionCore;
use Acadly\Core\Setup\SetupCore;
use Acadly\Kernel\Notify\Sms\AfricasTalkingProvider;
use Acadly\Kernel\Notify\Sms\MeTicketProvider;
use Acadly\Modules\Assessment\AssessmentModule;
use Acadly\Modules\Attendance\AttendanceModule;
use Acadly\Modules\Conduct\ConductModule;
use Acadly\Modules\Fees\FeesModule;
use Acadly\Modules\Support\SupportModule;

return [

    'version' => env('ACADLY_VERSION', '0.0.0-stage1'),

    /*
    | The school this deployment serves, as the sign-in screens show it.
    | It moves to the institution record when that is built.
    */
    'school_name' => env('ACADLY_SCHOOL_NAME', 'School'),

    /*
    | Where the school is. Moments are stored in UTC; a school day (a register,
    | a date admitted) is the day in this timezone.
    */
    'timezone' => env('ACADLY_TIMEZONE', 'Africa/Blantyre'),

    /*
    | How phone numbers are read: a national number takes this country code.
    */
    'phone' => [
        'country_code' => env('ACADLY_PHONE_COUNTRY_CODE', '265'),
        'trunk_prefix' => env('ACADLY_PHONE_TRUNK_PREFIX', '0'),
        'subscriber_length' => (int) env('ACADLY_PHONE_SUBSCRIBER_LENGTH', 9),
    ],

    /*
    | Text messages. `driver` is `school` (send through the provider the
    | school chose on the Messages page) or `log` (write each message to the
    | log and send nothing). `providers` lists the companies a school may
    | choose, by key; each class implements ChoosableSmsProvider. The access
    | key is entered on the Messages page and kept encrypted.
    */
    'sms' => [
        'driver' => env('ACADLY_SMS_DRIVER', 'school'),
        'providers' => [
            'meticket' => MeTicketProvider::class,
            'africastalking' => AfricasTalkingProvider::class,
        ],
        'meticket_url' => env('ACADLY_SMS_METICKET_URL', 'https://smpp.meticketmw.com'),
        'africastalking_url' => env('ACADLY_SMS_AFRICASTALKING_URL', 'https://api.africastalking.com'),
        'africastalking_sandbox_url' => env('ACADLY_SMS_AFRICASTALKING_SANDBOX_URL', 'https://api.sandbox.africastalking.com'),
        'timeout' => (int) env('ACADLY_SMS_TIMEOUT', 15),
    ],

    /*
    | The public key that verifies the licence IQ-X issues (Ed25519, base64).
    | With no key on a development machine, every module is licensed.
    */
    'licence_public_key' => env('ACADLY_LICENCE_PUBLIC_KEY'),

    /*
    | Where people use the console. Password reset links point here.
    */
    'frontend_url' => env('ACADLY_FRONTEND_URL', 'http://localhost:5173'),

    // The parent portal's address, as families type it. Text messages that open the portal end with it (D-083).
    'portal_url' => env('ACADLY_PORTAL_URL', 'http://localhost:5174'),

    /*
    | Compiled blueprints and the lock of released versions (02 §1). Made by
    | blueprint:compile and blueprint:release; read by the journey engine.
    */
    'blueprints' => [
        'profiles' => base_path('blueprints/profiles'),
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
    | The administrator made by the development seed (php artisan db:seed).
    | Local and testing only; a real deployment uses acadly:administrator.
    */
    'development' => [
        'admin_email' => env('DEV_ADMIN_EMAIL'),
        'admin_name' => env('DEV_ADMIN_NAME', 'Development Administrator'),
        'admin_password' => env('DEV_ADMIN_PASSWORD'),

        // View as: an administrator steps into another account to test what
        // it sees. Off unless turned on here, and never in production.
        'view_as' => (bool) env('DEV_VIEW_AS', false),
        'view_as_minutes' => (int) env('DEV_VIEW_AS_MINUTES', 60),
    ],

    /*
    | Every Core domain and every Module in this build, by manifest class.
    | A module that is not listed here does not exist for the application.
    */
    'modules' => [
        InstitutionCore::class,
        AcademicsCore::class,
        EnrolmentCore::class,
        SetupCore::class,
        AttendanceModule::class,
        AssessmentModule::class,
        FeesModule::class,
        ConductModule::class,
        SupportModule::class,
    ],

];
