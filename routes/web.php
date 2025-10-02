<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CronDashboardController;
use App\Http\Controllers\Admin\PauseWindowController;
use Illuminate\Support\Facades\Route;

Route::prefix('/')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    });

    Route::middleware('auth')->group(function () {
        Route::get('/', [CronDashboardController::class, 'index'])->name('dashboard');
        Route::get('/jobs/{job}', [CronDashboardController::class, 'job'])->name('jobs.show');
        Route::get('/jobs/{job}/runs/{runId}', [CronDashboardController::class, 'run'])->name('jobs.runs.show');
        Route::get('/pause-window', [PauseWindowController::class, 'edit'])->name('pause-window.edit');
        Route::put('/pause-window', [PauseWindowController::class, 'update'])->name('pause-window.update');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::fallback(function () {
    $route = auth()->check() ? 'admin.dashboard' : 'admin.login';

    return redirect()
        ->route($route)
        ->with('toast', [
            'type'    => 'warning',
            'message' => 'Event not found',
            'emoji'   => '🚫',
        ]);
});
