<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PauseWindow;
use App\Services\PauseWindowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PauseWindowController extends Controller
{
    public function __construct()
    {
        view()->share('adminNav', CronDashboardController::navItems());
    }

    public function edit(PauseWindowService $pauseWindow): View
    {
        $settings = $pauseWindow->current();
        $record   = PauseWindow::query()->first();
        $now      = now($settings->timezone);

        $status = [
            'is_paused'      => $pauseWindow->isPaused($now),
            'next_pause_at'  => $pauseWindow->nextPauseAt($now),
            'next_resume_at' => $pauseWindow->nextResumeAt($now),
        ];

        $timezoneOptions = Collection::make([
            'Asia/Kolkata',
            'Asia/Dubai',
            'Europe/London',
            'America/New_York',
            'UTC',
            config('app.timezone'),
            $settings->timezone,
        ])->filter()->unique()->values()->all();

        return view('admin.pause-window', [
            'pageTitle' => 'Processing Pause Window',
            'pageIntro' => 'Control when cron jobs and queued workers should pause for maintenance hours.',
            'settings'  => $settings,
            'record'    => $record,
            'now'       => $now,
            'status'    => $status,
            'timezones' => $timezoneOptions,
        ]);
    }

    public function update(Request $request, PauseWindowService $pauseWindow): RedirectResponse
    {
        $validated = $request->validate([
            'enabled'    => ['nullable', 'boolean'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time'   => ['required', 'date_format:H:i', Rule::notIn([$request->input('start_time')])],
            'timezone'   => ['required', 'timezone'],
        ], [
            'end_time.not_in' => 'End time must differ from the start time.',
        ]);

        $record = PauseWindow::query()->first() ?? new PauseWindow();

        $record->fill([
            'starts_at' => $this->timeToMinutes($validated['start_time']),
            'ends_at'   => $this->timeToMinutes($validated['end_time']),
            'timezone'  => $validated['timezone'],
            'enabled'   => (bool) ($validated['enabled'] ?? false),
        ]);

        $record->save();

        $pauseWindow->refreshCache();

        return redirect()
            ->route('admin.pause-window.edit')
            ->with('toast', [
                'type'    => 'success',
                'message' => 'Pause window settings updated.',
                'emoji'   => '⏱️',
            ]);
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        $hours   = max(0, min($hours, 23));
        $minutes = max(0, min($minutes, 59));

        return ($hours * 60) + $minutes;
    }
}
