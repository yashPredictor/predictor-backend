<?php

use App\Http\Controllers\Admin\CronDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin-yash')->name('admin.')->group(function () {
    Route::get('/', [CronDashboardController::class, 'index'])->name('dashboard');
    Route::get('/jobs/{job}', [CronDashboardController::class, 'job'])->name('jobs.show');
    Route::get('/jobs/{job}/runs/{runId}', [CronDashboardController::class, 'run'])->name('jobs.runs.show');
});
