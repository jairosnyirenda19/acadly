<?php

declare(strict_types=1);

use Acadly\Kernel\Documents\Http\DocumentsController;
use Acadly\Kernel\Http\HealthController;
use Acadly\Kernel\Http\ReadinessController;
use Acadly\Kernel\Http\SchoolController;
use Acadly\Kernel\Identity\Http\MeController;
use Acadly\Kernel\Identity\Http\PasswordResetController;
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
});
