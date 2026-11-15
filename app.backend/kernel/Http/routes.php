<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Http\RolesController;
use Acadly\Kernel\Documents\Http\DocumentsController;
use Acadly\Kernel\Http\HealthController;
use Acadly\Kernel\Http\ReadinessController;
use Acadly\Kernel\Http\SchoolController;
use Acadly\Kernel\Identity\Http\MeController;
use Acadly\Kernel\Identity\Http\PasswordResetController;
use Acadly\Kernel\Identity\Http\PeopleController;
use Acadly\Kernel\Identity\Http\SignInController;
use Acadly\Kernel\Identity\Http\SignOutController;
use Acadly\Kernel\Module\Http\ContextController;
use Acadly\Kernel\Module\Http\ModulesController;
use Acadly\Kernel\Notify\Http\NotificationsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('kernel.health');
Route::get('/ready', ReadinessController::class)->name('kernel.ready');
Route::get('/school', SchoolController::class)->name('kernel.school');

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/sign-in', SignInController::class)->name('sign-in');
    Route::post('/sign-out', SignOutController::class)->middleware('auth:sanctum')->name('sign-out');
    Route::post('/forgot-password', [PasswordResetController::class, 'requestLink'])->middleware('throttle:6,1')->name('forgot-password');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1')->name('reset-password');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', MeController::class)->name('me');
    Route::get('/me/context', ContextController::class)->name('me.context');
    Route::get('/me/notifications', [NotificationsController::class, 'index'])->name('me.notifications');
    Route::post('/me/notifications/{id}/read', [NotificationsController::class, 'read'])->whereUuid('id')->name('me.notifications.read');

    Route::post('/documents', [DocumentsController::class, 'store'])->name('documents.store');
    Route::get('/documents/{id}', [DocumentsController::class, 'show'])->whereUuid('id')->name('documents.show');
    Route::get('/documents/{id}/content', [DocumentsController::class, 'content'])->whereUuid('id')->name('documents.content');
    Route::post('/documents/{id}/versions', [DocumentsController::class, 'addVersion'])->whereUuid('id')->name('documents.versions.store');
    Route::delete('/documents/{id}', [DocumentsController::class, 'trash'])->whereUuid('id')->name('documents.trash');
    Route::post('/documents/trash/{batch}/restore', [DocumentsController::class, 'restore'])->whereUuid('batch')->name('documents.restore');
    Route::get('/modules', [ModulesController::class, 'index'])->name('modules.index');
    Route::put('/modules/{key}', [ModulesController::class, 'update'])->where('key', '[a-z][a-z0-9_]*')->name('modules.update');

    Route::prefix('people')->name('people.')->group(function (): void {
        Route::get('/', [PeopleController::class, 'index'])->name('index');
        Route::post('/', [PeopleController::class, 'invite'])->name('invite');
        Route::get('/import/template', [PeopleController::class, 'template'])->name('import.template');
        Route::post('/import/check', [PeopleController::class, 'checkImport'])->name('import.check');
        Route::post('/import', [PeopleController::class, 'import'])->name('import');
        Route::get('/{id}', [PeopleController::class, 'show'])->whereUuid('id')->name('show');
        Route::post('/{id}/invitation', [PeopleController::class, 'resend'])->whereUuid('id')->name('resend');
        Route::post('/{id}/roles', [PeopleController::class, 'give'])->whereUuid('id')->name('give');
        Route::post('/{id}/roles/{assignment}/end', [PeopleController::class, 'end'])->whereUuid(['id', 'assignment'])->name('end');
        Route::post('/{id}/disable', [PeopleController::class, 'disable'])->whereUuid('id')->name('disable');
        Route::post('/{id}/password-reset', [PeopleController::class, 'passwordReset'])->whereUuid('id')->name('password-reset');
    });

    Route::get('/roles', [RolesController::class, 'index'])->name('roles.index');
    Route::post('/roles', [RolesController::class, 'store'])->name('roles.store');
    Route::put('/roles/{id}', [RolesController::class, 'update'])->whereUuid('id')->name('roles.update');
});
