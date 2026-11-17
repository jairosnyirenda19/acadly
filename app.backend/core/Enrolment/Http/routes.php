<?php

declare(strict_types=1);

use Acadly\Core\Enrolment\Http\FamiliesController;
use Acadly\Core\Enrolment\Http\LearnerImportController;
use Acadly\Core\Enrolment\Http\LearnersController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/families', [FamiliesController::class, 'index'])->name('families.index');
    Route::post('/families/invite', [FamiliesController::class, 'invite'])->middleware('throttle:10,1')->name('families.invite');
    Route::post('/families/links/{id}', [FamiliesController::class, 'link'])->whereUuid('id')->name('families.link');

    // The parent portal: a parent's own family. No permission: a person reads their own record.
    Route::get('/portal/family', [FamiliesController::class, 'family'])->name('portal.family');
    Route::post('/portal/family/confirm', [FamiliesController::class, 'confirm'])->name('portal.confirm');
    Route::get('/portal/children/{id}/messages', [FamiliesController::class, 'messages'])->whereUuid('id')->name('portal.messages');
});

Route::middleware('auth:sanctum')->prefix('learners')->name('learners.')->group(function (): void {
    Route::get('/', [LearnersController::class, 'index'])->name('index');
    Route::get('/grid', [LearnersController::class, 'grid'])->name('grid');
    Route::post('/', [LearnersController::class, 'store'])->name('store');
    Route::get('/import/template', [LearnerImportController::class, 'template'])->name('import.template');
    Route::post('/import/check', [LearnerImportController::class, 'check'])->name('import.check');
    Route::post('/import', [LearnerImportController::class, 'import'])->name('import');
    Route::get('/{id}', [LearnersController::class, 'show'])->whereUuid('id')->name('show');
    Route::put('/{id}', [LearnersController::class, 'update'])->whereUuid('id')->name('update');
    Route::get('/{id}/messages', [LearnersController::class, 'messages'])->whereUuid('id')->name('messages');
    Route::post('/{id}/class', [LearnersController::class, 'move'])->whereUuid('id')->name('move');
    Route::post('/{id}/contacts', [LearnersController::class, 'addContact'])->whereUuid('id')->name('contacts.store');
    Route::post('/{id}/contacts/{contact}/notices', [LearnersController::class, 'contactNotices'])->whereUuid(['id', 'contact'])->name('contacts.notices');
    Route::post('/{id}/contacts/{contact}/main', [LearnersController::class, 'mainContact'])->whereUuid(['id', 'contact'])->name('contacts.main');
    Route::post('/{id}/contacts/{contact}/remove', [LearnersController::class, 'removeContact'])->whereUuid(['id', 'contact'])->name('contacts.remove');
    Route::post('/{id}/photo', [LearnersController::class, 'photo'])->whereUuid('id')->name('photo.store');
    Route::get('/{id}/photo', [LearnersController::class, 'openPhoto'])->whereUuid('id')->name('photo.show');
});
