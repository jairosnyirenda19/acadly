<?php

declare(strict_types=1);

use Acadly\Modules\Support\Http\SupportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:support'])->prefix('support')->name('support.')->group(function (): void {
    Route::get('/', [SupportController::class, 'watch'])->name('watch');
    Route::get('/rules', [SupportController::class, 'rules'])->name('rules');
    Route::put('/rules', [SupportController::class, 'saveRules'])->name('rules.save');
    Route::get('/learners/{id}', [SupportController::class, 'learner'])->whereUuid('id')->name('learner');
    Route::get('/learners/{id}/opening', [SupportController::class, 'opening'])->whereUuid('id')->name('learner.opening');
    Route::post('/learners/{id}/set-aside', [SupportController::class, 'setAside'])->whereUuid('id')->name('learner.set-aside');
    Route::post('/plans', [SupportController::class, 'open'])->name('plans.store');
    Route::get('/plans/{id}', [SupportController::class, 'plan'])->whereUuid('id')->name('plans.show');
    Route::post('/plans/{id}/{what}', [SupportController::class, 'change'])->whereUuid('id')->whereIn('what', ['goals', 'actions', 'agree', 'reviews', 'close'])->name('plans.change');
});
