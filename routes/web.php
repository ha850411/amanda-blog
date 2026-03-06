<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\Admin;

Route::get('/', [IndexController::class, 'index'])
    ->name('index');
Route::get('/article/{id}', [IndexController::class, 'article'])
    ->name('article');


Route::prefix('admin')->group(function () {
    // 登入頁（不需要驗證）
    Route::get('/login', [Admin\LoginController::class, 'login'])
        ->name('admin.login');

    // 需要登入才能存取的後台頁面
    Route::middleware(AdminMiddleware::class)->group(function () {
        Route::get('/', [Admin\IndexController::class, 'index'])
            ->name('admin.index');
        Route::get('/about', [Admin\AboutController::class, 'about'])
            ->name('admin.about');
        Route::get('/tag', [Admin\TagController::class, 'tag'])
            ->name('admin.tag');
        // 文章
        Route::get('/article', [Admin\ArticleController::class, 'article'])
            ->name('admin.article');
        // 新增文章
        Route::get('/article/add', [Admin\ArticleController::class, 'addArticle'])
            ->name('admin.article.add');
        // 編輯文章
        Route::get('/article/{id}', [Admin\ArticleController::class, 'editArticle'])
            ->name('admin.article.edit');
        Route::get('social', [Admin\SocialController::class, 'social'])
            ->name('admin.social');
        Route::get('logout', [Admin\LoginController::class, 'logout'])
            ->name('admin.logout');
    });
});
