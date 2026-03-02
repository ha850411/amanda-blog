<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\AdminController;
use App\Http\Middleware\AdminMiddleware;

Route::get('/', [IndexController::class, 'index'])
    ->name('index');
Route::get('/article/{id}', [IndexController::class, 'article'])
    ->name('article');


Route::prefix('admin')->group(function () {
    // 登入頁（不需要驗證）
    Route::get('/login', [AdminController::class, 'login'])
        ->name('admin.login');

    // 需要登入才能存取的後台頁面
    Route::middleware(AdminMiddleware::class)->group(function () {
        Route::get('/', [AdminController::class, 'index'])
            ->name('admin.index');
        Route::get('/about', [AdminController::class, 'about'])
            ->name('admin.about');
        Route::get('/tag', [AdminController::class, 'tag'])
            ->name('admin.tag');
        Route::get('/article', [AdminController::class, 'article'])
            ->name('admin.article');
        Route::get('/article/add', [AdminController::class, 'addArticle'])
            ->name('admin.article.add');
        Route::get('/article/{id}', [AdminController::class, 'editArticle'])
            ->name('admin.article.edit');
        Route::get('social', [AdminController::class, 'social'])
            ->name('admin.social');
        Route::get('logout', [AdminController::class, 'logout'])
            ->name('admin.logout');
    });
});
