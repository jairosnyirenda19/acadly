<?php

declare(strict_types=1);

use Acadly\Modules\Assessment\Http\AssessmentController;
use Acadly\Modules\Assessment\Http\FamilyCardController;
use Acadly\Modules\Assessment\Http\PublishController;
use Acadly\Modules\Assessment\Http\ResultsController;
use Acadly\Modules\Assessment\Http\SettingsController;
use Illuminate\Support\Facades\Route;

// The parent portal: a parent's own child. No permission: the active link is the rule.
Route::middleware(['auth:sanctum', 'module:assessment'])->get('/portal/children/{id}/card', FamilyCardController::class)->whereUuid('id')->name('portal.card');

Route::middleware(['auth:sanctum', 'module:assessment'])->prefix('assessment')->name('assessment.')->group(function (): void {
    Route::get('/scales', [SettingsController::class, 'scales'])->name('scales.index');
    Route::put('/scales', [SettingsController::class, 'addScale'])->name('scales.store');
    Route::put('/scales/{id}', [SettingsController::class, 'saveScale'])->whereUuid('id')->name('scales.update');
    Route::put('/level-scales', [SettingsController::class, 'levelScale'])->name('level-scales');
    Route::get('/plans', [SettingsController::class, 'plans'])->name('plans.index');
    Route::post('/plans', [SettingsController::class, 'savePlan'])->name('plans.store');

    Route::get('/mine', [AssessmentController::class, 'mine'])->name('mine');
    Route::get('/options', [AssessmentController::class, 'options'])->name('options');
    Route::get('/grid', [AssessmentController::class, 'grid'])->name('grid');
    Route::post('/assessments', [AssessmentController::class, 'store'])->name('assessments.store');
    Route::get('/assessments/{id}', [AssessmentController::class, 'show'])->whereUuid('id')->name('assessments.show');
    Route::put('/assessments/{id}/marks', [AssessmentController::class, 'marks'])->whereUuid('id')->name('assessments.marks');
    Route::post('/assessments/{id}/submit', [AssessmentController::class, 'submit'])->whereUuid('id')->name('assessments.submit');
    Route::post('/assessments/{id}/lock', [AssessmentController::class, 'lock'])->whereUuid('id')->name('assessments.lock');
    Route::post('/assessments/{id}/return', [AssessmentController::class, 'return'])->whereUuid('id')->name('assessments.return');
    Route::post('/assessments/{id}/correct', [AssessmentController::class, 'correct'])->whereUuid('id')->name('assessments.correct');
    Route::post('/assessments/{id}/papers', [AssessmentController::class, 'addPaper'])->whereUuid('id')->middleware('throttle:60,1')->name('assessments.papers.store');
    Route::post('/papers/{id}/move', [AssessmentController::class, 'movePaper'])->whereUuid('id')->name('papers.move');
    Route::delete('/papers/{id}', [AssessmentController::class, 'removePaper'])->whereUuid('id')->name('papers.remove');

    Route::get('/results', [ResultsController::class, 'index'])->name('results');
    Route::get('/results/working', [ResultsController::class, 'working'])->name('results.working');
    Route::get('/learner/{id}', [ResultsController::class, 'learner'])->whereUuid('id')->name('learner');

    Route::get('/publishing', [PublishController::class, 'index'])->name('publishing');
    Route::post('/publishing', [PublishController::class, 'publish'])->name('publishing.publish');
    Route::put('/publishing/settings', [PublishController::class, 'settings'])->name('publishing.settings');
    Route::get('/comments', [PublishController::class, 'comments'])->name('comments');
    Route::put('/comments', [PublishController::class, 'comment'])->name('comments.write');
    Route::get('/cards/{id}', [PublishController::class, 'cards'])->whereUuid('id')->name('cards');
});
