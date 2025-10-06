<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CronEmergencyController extends Controller
{
    public function __construct()
    {
        view()->share('adminNav', CronDashboardController::navItems());
    }

    public function index(AdminSettingsService $settings): View
    {
        $jobs = CronDashboardController::jobs();
        $toggles = $settings->cronToggles();

        return view('admin.emergency', [
            'pageTitle' => 'Emergency Controls',
            'pageIntro' => 'Temporarily pause or resume cron jobs without redeploying.',
            'jobs'      => $jobs,
            'toggles'   => $toggles,
        ]);
    }

    public function toggle(Request $request, AdminSettingsService $settings): RedirectResponse
    {
        $jobKeys = array_keys(CronDashboardController::jobs());

        $validated = $request->validate([
            'job'    => ['required', Rule::in($jobKeys)],
            'action' => ['required', Rule::in(['enable', 'disable'])],
        ]);

        $enabled = $validated['action'] === 'enable';
        $settings->setCronEnabled($validated['job'], $enabled);

        $emoji    = $enabled ? '✅' : '⛔️';
        $message  = $enabled ? 'Job resumed.' : 'Job paused.';

        return redirect()
            ->route('admin.emergency.index')
            ->with('toast', [
                'type'    => $enabled ? 'success' : 'warning',
                'message' => sprintf('%s %s', CronDashboardController::jobs()[$validated['job']]['label'], $message),
                'emoji'   => $emoji,
            ]);
    }
}
