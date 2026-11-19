<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Http\DivisionsController;
use Acadly\Core\Enrolment\Http\FamiliesController;
use Acadly\Core\Enrolment\Http\LearnerDocumentsController;
use Acadly\Core\Enrolment\Http\LearnerImportController;
use Acadly\Core\Enrolment\Http\LearnersController;
use Acadly\Core\Enrolment\Http\ProgressionController;
use Acadly\Core\Enrolment\Http\SchoolLearnersController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/divisions/overview', DivisionsController::class)->name('divisions.overview');
    // The whole-school overview's read-only list (D-100): the school view only.
    Route::get('/school/learners', SchoolLearnersController::class)->name('learners.school');
    Route::get('/families', [FamiliesController::class, 'index'])->name('families.index');
    Route::post('/families/invite', [FamiliesController::class, 'invite'])->middleware('throttle:10,1')->name('families.invite');
    Route::post('/families/links/{id}', [FamiliesController::class, 'link'])->whereUuid('id')->name('families.link');

    // The parent portal: a parent's own family. No permission: a person reads their own record.
    Route::get('/portal/family', [FamiliesController::class, 'family'])->name('portal.family');
    Route::post('/portal/family/confirm', [FamiliesController::class, 'confirm'])->name('portal.confirm');
    Route::get('/portal/children/{id}/messages', [FamiliesController::class, 'messages'])->whereUuid('id')->name('portal.messages');
});

// The year-end run (D-088).
Route::middleware('auth:sanctum')->prefix('year-end')->name('year-end.')->group(function (): void {
    Route::get('/', [ProgressionController::class, 'index'])->name('index');
    Route::get('/{division}', [ProgressionController::class, 'show'])->whereUuid('division')->name('show');
    Route::post('/{division}/open', [ProgressionController::class, 'open'])->whereUuid('division')->name('open');
    Route::post('/{division}/commit', [ProgressionController::class, 'commit'])->whereUuid('division')->name('commit');
    Route::put('/decisions/{id}', [ProgressionController::class, 'change'])->whereUuid('id')->name('change');
    Route::post('/decisions/{id}/intake', [ProgressionController::class, 'intake'])->whereUuid('id')->name('intake');
});

Route::middleware('auth:sanctum')->prefix('learners')->name('learners.')->group(function (): void {
    Route::get('/', [LearnersController::class, 'index'])->name('index');
    Route::get('/grid', [LearnersController::class, 'grid'])->name('grid');
    Route::post('/', [LearnersController::class, 'store'])->name('store');
    Route::get('/import/template', [LearnerImportController::class, 'template'])->name('import.template');
    Route::post('/import/check', [LearnerImportController::class, 'check'])->name('import.check');
    Route::post('/import', [LearnerImportController::class, 'import'])->name('import');
    Route::get('/{id}', [LearnersController::class, 'show'])->whereUuid('id')->name('show');
    Route::get('/{id}/place', [LearnersController::class, 'place'])->whereUuid('id')->name('place');
    Route::put('/{id}', [LearnersController::class, 'update'])->whereUuid('id')->name('update');
    Route::get('/{id}/messages', [LearnersController::class, 'messages'])->whereUuid('id')->name('messages');
    Route::post('/{id}/class', [LearnersController::class, 'move'])->whereUuid('id')->name('move');
    Route::post('/{id}/contacts', [LearnersController::class, 'addContact'])->whereUuid('id')->name('contacts.store');
    Route::post('/{id}/contacts/{contact}/notices', [LearnersController::class, 'contactNotices'])->whereUuid(['id', 'contact'])->name('contacts.notices');
    Route::post('/{id}/contacts/{contact}/main', [LearnersController::class, 'mainContact'])->whereUuid(['id', 'contact'])->name('contacts.main');
    Route::post('/{id}/contacts/{contact}/remove', [LearnersController::class, 'removeContact'])->whereUuid(['id', 'contact'])->name('contacts.remove');
    Route::post('/{id}/photo', [LearnersController::class, 'photo'])->whereUuid('id')->name('photo.store');
    Route::get('/{id}/photo', [LearnersController::class, 'openPhoto'])->whereUuid('id')->name('photo.show');
    Route::get('/{id}/documents', [LearnerDocumentsController::class, 'index'])->whereUuid('id')->name('documents.index');
    Route::post('/{id}/documents', [LearnerDocumentsController::class, 'store'])->whereUuid('id')->middleware('throttle:60,1')->name('documents.store');
});

// The school's learner document types (D-090).
Route::middleware('auth:sanctum')->prefix('learner-document-types')->name('learner-document-types.')->group(function (): void {
    Route::get('/', [LearnerDocumentsController::class, 'types'])->name('index');
    Route::post('/', [LearnerDocumentsController::class, 'addType'])->name('store');
    Route::put('/{id}', [LearnerDocumentsController::class, 'changeType'])->whereUuid('id')->name('update');
    Route::post('/{id}/retire', [LearnerDocumentsController::class, 'retireType'])->whereUuid('id')->name('retire');
});
