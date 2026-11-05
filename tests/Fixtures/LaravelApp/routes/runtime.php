<?php

use Fixture\App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/api/dashboard', [DashboardController::class, 'index']);
Route::get('/api/broken', [DashboardController::class, 'broken']);
