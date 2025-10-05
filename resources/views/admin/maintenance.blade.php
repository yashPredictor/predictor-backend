@extends('layouts.admin')

@section('content')
<div class="stacked-section" style="display: grid; gap: 24px;">
    <section class="card" style="gap: 18px;">
        <div class="section-title">
            <span>Automated log cleanup</span>
            <span class="badge">Current interval: {{ $logRetentionDays }} days</span>
        </div>
        <p class="section-subtitle" style="margin: 0 0 8px;">
            The scheduler removes sync log entries older than the configured interval. Updates apply to all
            job log tables and take effect on the next cleanup run.
        </p>
        <form method="POST" action="{{ route('admin.maintenance.log-retention') }}" class="form-grid" style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            @csrf
            @method('PUT')
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Retention interval (days)</span>
                <input type="number" name="days" min="5" max="365" value="{{ old('days', $logRetentionDays) }}" required>
                @error('days')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <div style="display: flex; align-items: flex-end;">
                <button type="submit" class="btn btn-success">
                    Update interval
                </button>
            </div>
        </form>
    </section>

    <section class="card" style="gap: 18px;">
        <div class="section-title">
            <span>Manual table truncate</span>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            Truncating a table removes <strong>all</strong> rows instantly. Use this when a log table needs a clean slate.
            An admin password is required to confirm the operation.
        </p>
        <form method="POST" action="{{ route('admin.maintenance.truncate') }}" class="form-grid" style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            @csrf
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Select table</span>
                <select name="table" required>
                    <option value="" disabled @selected(!old('table'))>Choose table</option>
                    @foreach ($tables as $value => $label)
                        <option value="{{ $value }}" @selected(old('table') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('table')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Admin password</span>
                <input type="password" name="password" required>
                @error('password')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <div style="display: flex; align-items: flex-end; gap: 12px;">
                <button type="submit" class="btn btn-danger">
                    Truncate table
                </button>
            </div>
        </form>
        <p class="section-subtitle" style="margin: 0; font-size: 0.78rem; color: rgba(248, 250, 252, 0.6);">
            Tip: the automated cleanup keeps the last {{ $logRetentionDays }} days of data. Use manual truncation for emergency resets only.
        </p>
    </section>
</div>
@endsection
