<?php

declare(strict_types=1);

use Acadly\Modules\News\Http\NewsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:news'])->group(function (): void {
    Route::get('news/{division}', [NewsController::class, 'index'])->whereUuid('division')->name('news.index');
    Route::post('news/{division}', [NewsController::class, 'store'])->whereUuid('division')->name('news.store');
    Route::put('news/posts/{id}', [NewsController::class, 'update'])->whereUuid('id')->name('news.update');
    Route::post('news/posts/{id}/withdraw', [NewsController::class, 'withdraw'])->whereUuid('id')->name('news.withdraw');

    // The student portal's news (D-101).
    Route::get('student/news', [NewsController::class, 'student'])->name('student.news');
    Route::get('student/news/{id}', [NewsController::class, 'studentPost'])->whereUuid('id')->name('student.news.show');
});
