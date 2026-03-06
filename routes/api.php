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
    // 文章
    Route::get('/article', [Api\ArticleController::class, 'index'])
        ->name('admin.article.index');
    Route::post('/article', [Api\ArticleController::class, 'store'])
        ->name('admin.article.store');
    Route::delete('/article/{id}', [Api\ArticleController::class, 'destroy'])
        ->name('admin.article.destroy');
    // 社群 icon
    Route::get('/social', [Api\SocialController::class, 'index'])
        ->name('admin.social.index');
    Route::patch('/social/{id}', [Api\SocialController::class, 'update'])
        ->name('admin.social.update');
});

Route::post('/image/upload', [Api\ImageController::class, 'upload'])
    ->name('image.upload');
