@extends('layouts.admin')

@section('content')
<div class="stacked-section" style="display: grid; gap: 24px;">
    <section class="card" style="display: grid; gap: 18px;">
        <div class="section-title">
            <span>Firestore configuration</span>
            <span class="badge">Used by cron jobs</span>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            Leave a field blank to fall back to the value defined in <code>.env</code> or <code>config/services.php</code>.
            Updates require the admin password.
        </p>
        <form method="POST" action="{{ route('admin.settings.firestore') }}" class="form-grid" style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
            @csrf
            @method('PUT')
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Project ID</span>
                <input type="text" name="project_id" value="{{ old('project_id', $firestore['project_id']) }}" placeholder="firestore-project" autocomplete="off">
                @error('project_id')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Service account JSON path</span>
                <input type="text" name="sa_json" value="{{ old('sa_json', $firestore['sa_json']) }}" placeholder="/path/to/service-account.json" autocomplete="off">
                @error('sa_json')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Admin password</span>
                <input type="password" name="password" required autocomplete="current-password">
                @error('password')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-primary">Save Firestore settings</button>
            </div>
        </form>
    </section>

    <section class="card" style="display: grid; gap: 18px;">
        <div class="section-title">
            <span>Cricbuzz API</span>
            <span class="badge">RapidAPI credentials</span>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            Update the API host or key used for scorecard and squad sync operations. Leave the key blank to retain the existing value.
        </p>
        <form method="POST" action="{{ route('admin.settings.cricbuzz') }}" class="form-grid" style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
            @csrf
            @method('PUT')
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">API host</span>
                <input type="text" name="host" value="{{ old('host', $cricbuzz['host']) }}" placeholder="cricbuzz-cricket2.p.rapidapi.com" autocomplete="off">
                @error('host')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">API key</span>
                <input type="password" name="key" value="{{ old('key') ?? ($cricbuzz['key'] ? $cricbuzz['key'] : '') }}" placeholder="Enter new API key" autocomplete="off">
                @error('key')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Admin password</span>
                <input type="password" name="password" required autocomplete="current-password">
                @error('password')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-primary">Save Cricbuzz settings</button>
            </div>
        </form>
    </section>
</div>
@endsection
