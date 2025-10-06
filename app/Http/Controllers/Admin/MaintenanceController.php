<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    private const TRUNCATE_PASSWORD = 'SuperAdminYash123!@#';

    public function __construct()
    {
        view()->share('adminNav', CronDashboardController::navItems());
    }

    public function edit(AdminSettingsService $settings): View
    {
        return view('admin.maintenance', [
            'pageTitle'          => 'Jobs Maintenance',
            'pageIntro'          => 'Control automated log retention and perform table maintenance tasks.',
            'logRetentionDays'   => $settings->logRetentionDays(),
            'tables'             => $this->logTables(),
        ]);
    }

    public function updateLogRetention(Request $request, AdminSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:5', 'max:365'],
        ]);

        $settings->updateLogRetentionDays((int) $validated['days']);

        return redirect()
            ->route('admin.maintenance.edit')
            ->with('toast', [
                'type'    => 'success',
                'message' => 'Log retention interval updated.',
                'emoji'   => '🧹',
            ]);
    }

    public function truncateTable(Request $request): RedirectResponse
    {
        $allowedTables = array_keys($this->logTables());

        $validated = $request->validate([
            'table'    => ['required', Rule::in($allowedTables)],
            'password' => ['required', 'string'],
        ]);

        if (!hash_equals(self::TRUNCATE_PASSWORD, $validated['password'])) {
            return back()
                ->withErrors(['password' => 'The provided password is incorrect.'])
                ->withInput($request->except('password'));
        }

        DB::table($validated['table'])->truncate();

        return redirect()
            ->route('admin.maintenance.edit')
            ->with('toast', [
                'type'    => 'success',
                'message' => sprintf('Table %s truncated.', $validated['table']),
                'emoji'   => '🗑️',
            ]);
    }

    private function logTables(): array
    {
        return [
            'series_sync_logs'      => 'Series Sync Logs',
            'live_match_sync_logs'  => 'Live Match Sync Logs',
            'match_overs_sync_logs' => 'Match Overs Sync Logs',
            'match_info_sync_logs'  => 'Match Info Sync Logs',
            'scorecard_sync_logs'   => 'Scorecard Sync Logs',
            'series_squad_sync_logs'=> 'Series Squad Sync Logs',
            'recent_match_status_logs' => 'Recent Match Status Logs',
            'squad_sync_logs'       => 'Squad Sync Logs',
        ];
    }
}
