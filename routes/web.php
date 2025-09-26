<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CronDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin-yash')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    });

    Route::middleware('auth')->group(function () {
        Route::get('/', [CronDashboardController::class, 'index'])->name('dashboard');
        Route::get('/jobs/{job}', [CronDashboardController::class, 'job'])->name('jobs.show');
        Route::get('/jobs/{job}/runs/{runId}', [CronDashboardController::class, 'run'])->name('jobs.runs.show');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
