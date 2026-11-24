<?php

declare(strict_types=1);

use Acadly\Modules\Conduct\Http\ConductController;
use Acadly\Modules\Conduct\Http\FamilyConductController;
use Illuminate\Support\Facades\Route;

// The parent portal (D-110): a parent's own child. No permission: the active link is the rule.
// Conduct off where the child is, or not shown to families, answers not found.
Route::middleware(['auth:sanctum'])->get('/portal/children/{id}/conduct', [FamilyConductController::class, 'show'])->whereUuid('id')->name('portal.conduct');

Route::middleware(['auth:sanctum', 'module:conduct'])->prefix('conduct')->name('conduct.')->group(function (): void {
    Route::get('/', [ConductController::class, 'overview'])->name('overview');
    Route::get('/kinds', [ConductController::class, 'kinds'])->name('kinds.index');
    Route::put('/kinds', [ConductController::class, 'saveKinds'])->name('kinds.save');
    Route::put('/watch', [ConductController::class, 'saveWatch'])->name('watch.save');
    Route::post('/entries', [ConductController::class, 'record'])->middleware('throttle:120,1')->name('entries.store');
    Route::post('/entries/{id}/withdraw', [ConductController::class, 'withdraw'])->whereUuid('id')->name('entries.withdraw');
    Route::get('/learners/{id}', [ConductController::class, 'learner'])->whereUuid('id')->name('learner');
    Route::get('/incidents/{id}', [ConductController::class, 'incident'])->whereUuid('id')->name('incidents.show');
    Route::post('/incidents/{id}/notes', [ConductController::class, 'note'])->whereUuid('id')->name('incidents.notes');
    Route::post('/incidents/{id}/outcomes', [ConductController::class, 'outcome'])->whereUuid('id')->name('incidents.outcomes');
    Route::post('/incidents/{id}/learners/{learner}/end-suspension', [ConductController::class, 'endSuspension'])->whereUuid(['id', 'learner'])->name('incidents.end-suspension');
    Route::post('/incidents/{id}/{step}', [ConductController::class, 'step'])->whereUuid('id')->whereIn('step', ['take-up', 'decide', 'met', 'told', 'close', 'withdraw'])->name('incidents.step');
});
