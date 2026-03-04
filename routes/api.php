<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::post('/admin/login', [Api\LoginController::class, 'handleLogin'])
    ->name('admin.handleLogin');

Route::post('/admin/about', [Api\AboutController::class, 'updateAbout'])
    ->name('admin.updateAbout');
