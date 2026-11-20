<?php

declare(strict_types=1);

use Acadly\Modules\Programmes\Http\ProgrammesController;
use Acadly\Modules\Programmes\Http\RegistrationController;
use Acadly\Modules\Programmes\Http\StudentAccountsController;
use Acadly\Modules\Programmes\Http\StudentPortalController;
use Illuminate\Support\Facades\Route;

// The student portal (D-101): setting up the account, then the student's own records.
Route::post('student/set-up', [StudentPortalController::class, 'setUp'])->middleware('throttle:20,1')->name('student.set-up');
Route::middleware(['auth:sanctum', 'module:programmes'])->prefix('student')->name('student.')->group(function (): void {
    Route::get('/me', [StudentPortalController::class, 'me'])->name('me');
    Route::get('/courses', [StudentPortalController::class, 'courses'])->name('courses');
    Route::get('/registration', [StudentPortalController::class, 'registration'])->name('registration');
    Route::put('/registration/courses', [StudentPortalController::class, 'saveCourses'])->name('registration.courses');
    Route::post('/registration/submit', [StudentPortalController::class, 'submit'])->name('registration.submit');
    Route::get('/registration/slip', [StudentPortalController::class, 'slip'])->middleware('throttle:30,1')->name('registration.slip');
    Route::get('/fees', [StudentPortalController::class, 'fees'])->name('fees');
    Route::get('/fees/statement', [StudentPortalController::class, 'statement'])->middleware('throttle:30,1')->name('fees.statement');
});

Route::middleware(['auth:sanctum', 'module:programmes'])->get('programmes/learners/{id}', [RegistrationController::class, 'learner'])->whereUuid('id')->name('programmes.learner');

Route::middleware(['auth:sanctum', 'module:programmes'])->prefix('programmes/{division}')->whereUuid('division')->name('programmes.')->group(function (): void {
    Route::get('/structure', [ProgrammesController::class, 'structure'])->name('structure');
    Route::put('/faculties', [ProgrammesController::class, 'saveFaculties'])->name('faculties.save');
    Route::post('/departments', [ProgrammesController::class, 'saveDepartment'])->name('departments.save');
    Route::get('/catalogue', [ProgrammesController::class, 'catalogue'])->name('catalogue');
    Route::post('/courses', [ProgrammesController::class, 'saveCourse'])->name('courses.save');
    Route::post('/programmes', [ProgrammesController::class, 'saveProgramme'])->name('programmes.save');
    Route::get('/programmes/{id}', [ProgrammesController::class, 'programme'])->whereUuid('id')->name('programmes.show');
    Route::post('/programmes/{id}/versions', [ProgrammesController::class, 'newVersion'])->whereUuid('id')->name('versions.store');
    Route::put('/versions/{id}', [ProgrammesController::class, 'saveDraft'])->whereUuid('id')->name('versions.save');
    Route::post('/versions/{id}/release', [ProgrammesController::class, 'release'])->whereUuid('id')->name('versions.release');
    Route::get('/offerings', [ProgrammesController::class, 'offerings'])->name('offerings');
    Route::post('/offerings/{period}/prepare', [ProgrammesController::class, 'prepare'])->whereUuid('period')->name('offerings.prepare');
    Route::post('/offerings/{period}/groups', [ProgrammesController::class, 'addGroup'])->whereUuid('period')->name('offerings.group');
    Route::put('/offering/{id}', [ProgrammesController::class, 'saveOffering'])->whereUuid('id')->name('offerings.save');
    Route::get('/students', [RegistrationController::class, 'students'])->name('students');
    Route::post('/students', [RegistrationController::class, 'place'])->name('students.place');
    Route::post('/students/{id}/status', [RegistrationController::class, 'status'])->whereUuid('id')->name('students.status');
    Route::get('/registration', [RegistrationController::class, 'desk'])->name('registration.desk');
    Route::put('/registration/{period}/window', [RegistrationController::class, 'saveWindow'])->whereUuid('period')->name('registration.window');
    Route::post('/registration/{period}/start', [RegistrationController::class, 'start'])->whereUuid('period')->name('registration.start');
    Route::post('/registration/{period}/open', [RegistrationController::class, 'open'])->whereUuid('period')->name('registration.open');
    Route::post('/registration/{period}/approve', [RegistrationController::class, 'approve'])->whereUuid('period')->name('registration.approve');
    Route::post('/registration/{period}/register', [RegistrationController::class, 'register'])->whereUuid('period')->name('registration.register');
    Route::get('/registrations/{id}', [RegistrationController::class, 'show'])->whereUuid('id')->name('registrations.show');
    Route::put('/registrations/{id}/courses', [RegistrationController::class, 'saveCourses'])->whereUuid('id')->name('registrations.courses');
    Route::post('/registrations/{id}/{action}', [RegistrationController::class, 'act'])->whereUuid('id')->whereIn('action', ['submit', 'return', 'allow-fees', 'change', 'withdraw'])->name('registrations.act');
    Route::get('/registrations/{id}/slip', [RegistrationController::class, 'slip'])->whereUuid('id')->middleware('throttle:30,1')->name('registrations.slip');
    Route::get('/accounts', [StudentAccountsController::class, 'index'])->name('accounts');
    Route::put('/accounts/{learner}/phone', [StudentAccountsController::class, 'phone'])->whereUuid('learner')->name('accounts.phone');
    Route::post('/accounts/codes', [StudentAccountsController::class, 'codes'])->middleware('throttle:120,1')->name('accounts.codes');
    Route::post('/accounts/{learner}/suspend', [StudentAccountsController::class, 'suspend'])->whereUuid('learner')->name('accounts.suspend');
});
