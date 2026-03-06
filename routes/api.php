<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::get('health', function () {
    return "ok";
})->name('health');

Route::prefix('admin')->group(function () {
    Route::post('/login', [Api\LoginController::class, 'handleLogin'])
        ->name('admin.handleLogin');
    Route::post('/about', [Api\AboutController::class, 'updateAbout'])
        ->name('admin.updateAbout');
    Route::post('/tag/sort', [Api\TagController::class, 'updateSort'])
        ->name('admin.tag.updateSort');
    Route::post('/tag', [Api\TagController::class, 'store'])
        ->name('admin.tag.store');
    Route::put('/tag/{id}', [Api\TagController::class, 'update'])
        ->name('admin.tag.update');
    Route::delete('/tag/{id}', [Api\TagController::class, 'destroy'])
        ->name('admin.tag.destroy');
    Route::get('/article', [Api\ArticleController::class, 'index'])
        ->name('admin.article.index');
    Route::post('/article', [Api\ArticleController::class, 'store'])
        ->name('admin.article.store');
});

Route::post('/image/upload', [Api\ImageController::class, 'upload'])
    ->name('image.upload');
