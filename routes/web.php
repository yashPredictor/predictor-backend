<?php

use App\Http\Controllers\Admin\ApiAnalyticsController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CronDashboardController;
use App\Http\Controllers\Admin\CronEmergencyController;
use App\Http\Controllers\Admin\FailedJobsController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\PauseWindowController;
use App\Http\Controllers\Admin\SettingsController;
use Google\Cloud\Core\Timestamp as GoogleTimestamp;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Illuminate\Http\JsonResponse;
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
        Route::get('/api-analytics', [ApiAnalyticsController::class, 'index'])->name('api-analytics.index');
        Route::get('/api-analytics/{log}', [ApiAnalyticsController::class, 'show'])->name('api-analytics.show');
        Route::get('/emergency', [CronEmergencyController::class, 'index'])->name('emergency.index');
        Route::post('/emergency/toggle', [CronEmergencyController::class, 'toggle'])->name('emergency.toggle');
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings/firestore', [SettingsController::class, 'updateFirestore'])->name('settings.firestore');
        Route::put('/settings/cricbuzz', [SettingsController::class, 'updateCricbuzz'])->name('settings.cricbuzz');
        Route::get('/maintenance', [MaintenanceController::class, 'edit'])->name('maintenance.edit');
        Route::put('/maintenance/log-retention', [MaintenanceController::class, 'updateLogRetention'])->name('maintenance.log-retention');
        Route::post('/maintenance/truncate', [MaintenanceController::class, 'truncateTable'])->name('maintenance.truncate');
        Route::get('/failed-jobs', [FailedJobsController::class, 'index'])->name('failed-jobs.index');
        Route::post('/failed-jobs/{failedJob}/retry', [FailedJobsController::class, 'retry'])->name('failed-jobs.retry');
        Route::get('/pause-window', [PauseWindowController::class, 'edit'])->name('pause-window.edit');
        Route::put('/pause-window', [PauseWindowController::class, 'update'])->name('pause-window.update');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::get('/server-time', function () {
    return now()->valueOf();
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
