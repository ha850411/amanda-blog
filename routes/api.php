<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;

Route::post('/admin/login', [AdminController::class, 'handleLogin'])
    ->middleware('web')
    ->name('admin.handleLogin');
