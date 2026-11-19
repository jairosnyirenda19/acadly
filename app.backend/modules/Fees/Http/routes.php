<?php

declare(strict_types=1);

use Acadly\Modules\Fees\Http\FeesController;
use Acadly\Modules\Fees\Http\FeesSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:fees'])->prefix('fees')->name('fees.')->group(function (): void {
    Route::get('/desk', [FeesSettingsController::class, 'desk'])->name('desk');
    Route::get('/payments/grid', [FeesSettingsController::class, 'payments'])->name('payments.grid');
    Route::get('/behind/grid', [FeesSettingsController::class, 'behind'])->name('behind.grid');
    Route::post('/days/{day}/close', [FeesSettingsController::class, 'close'])->where('day', '\d{4}-\d{2}-\d{2}')->name('days.close');
    Route::post('/reminders', [FeesSettingsController::class, 'remind'])->name('reminders');
    Route::get('/export', [FeesSettingsController::class, 'export'])->name('export');

    Route::get('/learners/{id}', [FeesController::class, 'learner'])->whereUuid('id')->name('learner');
    Route::put('/learners/{id}/optional', [FeesController::class, 'optional'])->whereUuid('id')->name('learner.optional');
    Route::post('/payments', [FeesController::class, 'pay'])->middleware('throttle:120,1')->name('payments.store');
    Route::get('/payments/{id}', [FeesController::class, 'receipt'])->whereUuid('id')->name('payments.show');
    Route::get('/payments/{id}/pdf', [FeesController::class, 'receiptPdf'])->whereUuid('id')->middleware('throttle:30,1')->name('payments.pdf');
    Route::get('/learners/{id}/statement', [FeesController::class, 'statementPdf'])->whereUuid('id')->middleware('throttle:30,1')->name('learner.statement');
    Route::post('/payments/{id}/cancel', [FeesController::class, 'cancel'])->whereUuid('id')->name('payments.cancel');
    Route::post('/charges/{id}/plan', [FeesController::class, 'plan'])->whereUuid('id')->name('charges.plan');
    Route::post('/charges/{id}/adjust', [FeesController::class, 'adjust'])->whereUuid('id')->name('charges.adjust');
    Route::get('/shop', [FeesController::class, 'shop'])->name('shop.index');
    Route::put('/shop', [FeesSettingsController::class, 'saveShop'])->name('shop.save');
    Route::post('/shop/sale', [FeesController::class, 'sale'])->name('shop.sale');

    Route::get('/items', [FeesSettingsController::class, 'items'])->name('items.index');
    Route::put('/items', [FeesSettingsController::class, 'saveItems'])->name('items.save');
    Route::get('/terms', [FeesSettingsController::class, 'terms'])->name('terms.index');
    Route::get('/terms/{period}/{division}', [FeesSettingsController::class, 'term'])->whereUuid(['period', 'division'])->name('terms.show');
    Route::put('/terms/{period}/{division}', [FeesSettingsController::class, 'saveTerm'])->whereUuid(['period', 'division'])->name('terms.save');
    Route::post('/terms/{period}/{division}/bill', [FeesSettingsController::class, 'bill'])->whereUuid(['period', 'division'])->name('terms.bill');
});
