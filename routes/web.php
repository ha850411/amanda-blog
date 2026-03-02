<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IndexController;
use App\Http\Controllers\AdminController;

Route::get('/', [IndexController::class, 'index'])
    ->name('index');
Route::get('/article/{id}', [IndexController::class, 'article'])
    ->name('article');


Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminController::class, 'login'])
        ->name('admin.login');
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
