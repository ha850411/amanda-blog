<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;
use App\Http\Middleware\AdminMiddleware;

Route::get('health', function () {
    return "ok";
})->name('health');

Route::prefix('admin')->group(function () {
    Route::post('/login', [Api\LoginController::class, 'handleLogin'])
        ->name('admin.handleLogin');
});

Route::middleware(AdminMiddleware::class)->group(function () {
    // 更新關於我
    Route::post('/about', [Api\AboutController::class, 'updateAbout'])
        ->name('updateAbout');
    // 更新標籤排序
    Route::post('/tag/sort', [Api\TagController::class, 'updateSort'])
        ->name('tag.updateSort');
    // 新增標籤
    Route::post('/tag', [Api\TagController::class, 'store'])
        ->name('tag.store');
    // 更新標籤
    Route::put('/tag/{id}', [Api\TagController::class, 'update'])
        ->name('tag.update');
    // 刪除標籤
    Route::delete('/tag/{id}', [Api\TagController::class, 'destroy'])
        ->name('tag.destroy');
    // 新增文章
    Route::post('/article', [Api\ArticleController::class, 'store'])
        ->name('article.store');
    // 刪除文章
    Route::delete('/article/{id}', [Api\ArticleController::class, 'destroy'])
        ->name('article.destroy');
    // 更新社群 icon
    Route::patch('/social/{id}', [Api\SocialController::class, 'update'])
        ->name('social.update');
    // 上傳圖片
    Route::post('/image/upload', [Api\ImageController::class, 'upload'])
        ->name('image.upload');
});

// 取得文章
Route::get('/article', [Api\ArticleController::class, 'index'])
    ->name('article.index');

// 取得社群 icon
Route::get('/social', [Api\SocialController::class, 'index'])
    ->name('social.index');
