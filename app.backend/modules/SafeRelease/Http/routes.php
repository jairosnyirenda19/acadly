<?php

declare(strict_types=1);

use Acadly\Modules\SafeRelease\Http\PickupController;
use Illuminate\Support\Facades\Route;

// The parent portal: a parent's own child. No permission: the active link is the rule.
Route::middleware(['auth:sanctum', 'module:saferelease'])->group(function (): void {
    Route::get('/portal/children/{id}/pickup', [PickupController::class, 'family'])->whereUuid('id')->name('portal.pickup');
    Route::post('/portal/children/{id}/collectors', [PickupController::class, 'addForToday'])->whereUuid('id')->middleware('throttle:20,1')->name('portal.collectors');
});

Route::middleware(['auth:sanctum', 'module:saferelease'])->prefix('pickup')->name('pickup.')->group(function (): void {
    Route::get('/', [PickupController::class, 'desk'])->name('desk');
    Route::get('/learners/{id}', [PickupController::class, 'child'])->whereUuid('id')->name('child');
    Route::post('/learners/{id}/release', [PickupController::class, 'release'])->whereUuid('id')->name('release');
    Route::post('/learners/{id}/arrive', [PickupController::class, 'arrive'])->whereUuid('id')->name('arrive');
    Route::post('/learners/{id}/refuse', [PickupController::class, 'refuse'])->whereUuid('id')->name('refuse');
    Route::post('/learners/{id}/collectors', [PickupController::class, 'addCollector'])->whereUuid('id')->name('collectors.store');
    Route::post('/collectors/{id}/end', [PickupController::class, 'endCollector'])->whereUuid('id')->name('collectors.end');
    Route::post('/learners/{id}/bars', [PickupController::class, 'bar'])->whereUuid('id')->name('bars.store');
    Route::post('/bars/{id}/end', [PickupController::class, 'endBar'])->whereUuid('id')->name('bars.end');
});
