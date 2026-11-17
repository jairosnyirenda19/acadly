<?php

declare(strict_types=1);

use Acadly\Kernel\Access\Http\RolesController;
use Acadly\Kernel\Approvals\Http\ApprovalsController;
use Acadly\Kernel\Documents\Http\DocumentsController;
use Acadly\Kernel\Grid\GridsController;
use Acadly\Kernel\Http\HealthController;
use Acadly\Kernel\Http\ReadinessController;
use Acadly\Kernel\Http\SchoolController;
use Acadly\Kernel\Identity\Http\MeController;
use Acadly\Kernel\Identity\Http\PasswordResetController;
use Acadly\Kernel\Identity\Http\PeopleController;
use Acadly\Kernel\Identity\Http\SignInController;
use Acadly\Kernel\Identity\Http\SignOutController;
use Acadly\Kernel\Identity\Http\ViewAsController;
use Acadly\Kernel\Module\Http\ContextController;
use Acadly\Kernel\Module\Http\ModulesController;
use Acadly\Kernel\Notify\Http\MessagesController;
use Acadly\Kernel\Notify\Http\NotificationsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('kernel.health');
Route::post('/notify/reports/{token}', [MessagesController::class, 'report'])->where('token', '[A-Za-z0-9]{16,64}')->middleware('throttle:240,1')->name('notify.reports');
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

    Route::prefix('approvals')->name('approvals.')->group(function (): void {
        Route::get('/waiting', [ApprovalsController::class, 'waiting'])->name('waiting');
        Route::get('/topics', [ApprovalsController::class, 'topics'])->name('topics');
        Route::get('/topics/{key}', [ApprovalsController::class, 'topic'])->where('key', '[a-z][a-z0-9_.]*')->name('topic');
        Route::put('/topics/{key}', [ApprovalsController::class, 'save'])->where('key', '[a-z][a-z0-9_.]*')->name('topic.save');
        Route::get('/{id}', [ApprovalsController::class, 'show'])->whereUuid('id')->name('show');
        Route::post('/{id}/answer', [ApprovalsController::class, 'answer'])->whereUuid('id')->name('answer');
        Route::post('/{id}/withdraw', [ApprovalsController::class, 'withdraw'])->whereUuid('id')->name('withdraw');
    });

    // View as (development only; 404 wherever it is not available).
    Route::get('/view-as/people', [ViewAsController::class, 'candidates'])->name('view-as.people');
    Route::post('/view-as/{id}', [ViewAsController::class, 'start'])->whereUuid('id')->name('view-as.start');
    Route::delete('/view-as', [ViewAsController::class, 'leave'])->name('view-as.leave');
    Route::get('/me/notifications', [NotificationsController::class, 'index'])->name('me.notifications');
    Route::post('/me/notifications/{id}/read', [NotificationsController::class, 'read'])->whereUuid('id')->name('me.notifications.read');

    Route::post('/documents', [DocumentsController::class, 'store'])->name('documents.store');
    Route::get('/documents/{id}', [DocumentsController::class, 'show'])->whereUuid('id')->name('documents.show');
    Route::get('/documents/{id}/content', [DocumentsController::class, 'content'])->whereUuid('id')->name('documents.content');
    Route::post('/documents/{id}/versions', [DocumentsController::class, 'addVersion'])->whereUuid('id')->name('documents.versions.store');
    Route::delete('/documents/{id}', [DocumentsController::class, 'trash'])->whereUuid('id')->name('documents.trash');
    Route::post('/documents/trash/{batch}/restore', [DocumentsController::class, 'restore'])->whereUuid('batch')->name('documents.restore');
    Route::prefix('messages')->name('messages.')->group(function (): void {
        Route::get('/channels', [MessagesController::class, 'channels'])->name('channels');
        Route::put('/channels/sms', [MessagesController::class, 'saveSms'])->name('channels.sms');
        Route::put('/channels/email', [MessagesController::class, 'saveEmail'])->name('channels.email');
        Route::put('/limits', [MessagesController::class, 'saveLimits'])->name('limits');
        Route::post('/test', [MessagesController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::get('/notices', [MessagesController::class, 'notices'])->name('notices');
        Route::get('/notices/{key}', [MessagesController::class, 'notice'])->where('key', '[a-z][a-z0-9_.]*')->name('notice');
        Route::put('/notices/{key}', [MessagesController::class, 'saveNotice'])->where('key', '[a-z][a-z0-9_.]*')->name('notice.save');
        Route::delete('/notices/{key}/wording', [MessagesController::class, 'resetNotice'])->where('key', '[a-z][a-z0-9_.]*')->name('notice.reset');
        Route::get('/sent', [MessagesController::class, 'sent'])->name('sent');
        Route::get('/sent/grid', [MessagesController::class, 'grid'])->name('sent.grid');
        Route::get('/sent/{id}', [MessagesController::class, 'message'])->whereUuid('id')->name('message');
        Route::post('/sent/{id}/again', [MessagesController::class, 'again'])->whereUuid('id')->name('again');
    });

    Route::prefix('grids')->name('grids.')->group(function (): void {
        Route::post('/{grid}/views', [GridsController::class, 'storeView'])->where('grid', '[a-z][a-z0-9_]*')->name('views.store');
        Route::put('/{grid}/views/{id}', [GridsController::class, 'updateView'])->where('grid', '[a-z][a-z0-9_]*')->whereUuid('id')->name('views.update');
        Route::delete('/{grid}/views/{id}', [GridsController::class, 'deleteView'])->where('grid', '[a-z][a-z0-9_]*')->whereUuid('id')->name('views.delete');
        Route::put('/{grid}/columns', [GridsController::class, 'columns'])->where('grid', '[a-z][a-z0-9_]*')->name('columns');
        Route::post('/{grid}/exports', [GridsController::class, 'export'])->where('grid', '[a-z][a-z0-9_]*')->middleware('throttle:10,1')->name('exports.store');
        Route::post('/{grid}/bulk', [GridsController::class, 'bulk'])->where('grid', '[a-z][a-z0-9_]*')->name('bulk');
        Route::get('/exports/{id}', [GridsController::class, 'showExport'])->whereUuid('id')->name('exports.show');
        Route::get('/exports/{id}/file', [GridsController::class, 'file'])->whereUuid('id')->name('exports.file');
    });

    Route::get('/modules', [ModulesController::class, 'index'])->name('modules.index');
    Route::put('/modules/{key}', [ModulesController::class, 'update'])->where('key', '[a-z][a-z0-9_]*')->name('modules.update');

    Route::prefix('people')->name('people.')->group(function (): void {
        Route::get('/', [PeopleController::class, 'index'])->name('index');
        Route::get('/grid', [PeopleController::class, 'grid'])->name('grid');
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
    Route::delete('/roles/{id}', [RolesController::class, 'destroy'])->whereUuid('id')->name('roles.destroy');
});
