<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::get('health', function () {
    return "ok";
})->name('health');

Route::post('/admin/login', [Api\LoginController::class, 'handleLogin'])
    ->name('admin.handleLogin');

Route::post('/admin/about', [Api\AboutController::class, 'updateAbout'])
    ->name('admin.updateAbout');

Route::post('/admin/tag/sort', [Api\TagController::class, 'updateSort'])
    ->name('admin.tag.updateSort');

Route::post('/admin/tag', [Api\TagController::class, 'store'])
    ->name('admin.tag.store');

Route::put('/admin/tag/{id}', [Api\TagController::class, 'update'])
    ->name('admin.tag.update');

Route::delete('/admin/tag/{id}', [Api\TagController::class, 'destroy'])
    ->name('admin.tag.destroy');

Route::post('/image/upload', [Api\ImageController::class, 'upload'])
    ->name('admin.image.upload');