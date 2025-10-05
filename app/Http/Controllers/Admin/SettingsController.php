<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const ADMIN_PASSWORD = 'SuperAdminYash123!@#';

    public function __construct()
    {
        view()->share('adminNav', CronDashboardController::navItems());
    }

    public function edit(AdminSettingsService $settings): View
    {
        return view('admin.settings', [
            'pageTitle'        => 'Integration Settings',
            'pageIntro'        => 'Configure Firestore and Cricbuzz credentials used by background jobs.',
            'firestore'        => $settings->firestoreSettings(),
            'cricbuzz'         => $settings->cricbuzzSettings(),
        ]);
    }

    public function updateFirestore(Request $request, AdminSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'project_id'            => ['nullable', 'string', 'max:255'],
            'sa_json'               => ['nullable', 'string', 'max:1024'],
            'password'              => ['required', 'string'],
        ]);

        if (!$this->passwordValid($validated['password'])) {
            return back()
                ->withErrors(['password' => 'The provided password is incorrect.'])
                ->withInput($request->except('password'));
        }

        $settings->updateFirestoreSettings([
            'project_id' => $validated['project_id'] ?? null,
            'sa_json'    => $validated['sa_json'] ?? null,
        ]);

        return redirect()
            ->route('admin.settings.edit')
            ->with('toast', [
                'type'    => 'success',
                'message' => 'Firestore settings updated.',
                'emoji'   => '🔥',
            ]);
    }

    public function updateCricbuzz(Request $request, AdminSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'host'     => ['nullable', 'string', 'max:255'],
            'key'      => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (!$this->passwordValid($validated['password'])) {
            return back()
                ->withErrors(['password' => 'The provided password is incorrect.'])
                ->withInput($request->except('password'));
        }

        $existing = $settings->cricbuzzSettings();
        $key = $validated['key'] ?? null;
        if ($key === null || trim($key) === '') {
            $key = $existing['key'] ?? null;
        }

        $settings->updateCricbuzzSettings([
            'host' => $validated['host'] ?? null,
            'key'  => $key,
        ]);

        return redirect()
            ->route('admin.settings.edit')
            ->with('toast', [
                'type'    => 'success',
                'message' => 'Cricbuzz API settings updated.',
                'emoji'   => '🏏',
            ]);
    }

    private function passwordValid(string $password): bool
    {
        return hash_equals(self::ADMIN_PASSWORD, $password);
    }
}
