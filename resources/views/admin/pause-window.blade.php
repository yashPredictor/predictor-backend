@extends('layouts.admin')

@section('content')
@php
    $statusPill = $status['is_paused'] ? 'status-pill warning' : 'status-pill success';
    $statusLabel = $status['is_paused'] ? 'PAUSED' : 'ACTIVE';
    $nextPause = $status['next_pause_at'];
    $nextResume = $status['next_resume_at'];
    $formattedNextPause = $nextPause ? $nextPause->setTimezone($settings->timezone)->format('M j · H:i') : null;
    $formattedNextResume = $nextResume ? $nextResume->setTimezone($settings->timezone)->format('M j · H:i') : null;
@endphp

<div class="stacked-section" style="gap: 24px;">
    <section class="card" style="display: grid; gap: 18px;">
        <div class="section-title">
            <span>Current window snapshot</span>
            <span class="{{ $statusPill }}">{{ $statusLabel }}</span>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            All scheduler jobs and queue workers respect this window. When the window is active, cron
            executions are skipped and queued jobs are released back to the queue with a delay.
        </p>
        <div class="meta-grid" style="display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
            <div>
                <div class="stat-label">Timezone</div>
                <div class="stat-value">{{ $settings->timezone }}</div>
            </div>
            <div>
                <div class="stat-label">Window start</div>
                <div class="stat-value">{{ $settings->startTime }}</div>
            </div>
            <div>
                <div class="stat-label">Window end</div>
                <div class="stat-value">{{ $settings->endTime }}</div>
            </div>
            <div>
                <div class="stat-label">Server time</div>
                <div class="stat-value">{{ optional($now)->format('M j · H:i:s') }}</div>
            </div>
        </div>
        <div class="meta-grid" style="display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <div>
                <div class="stat-label">Next pause window begins</div>
                <div class="stat-value">{{ $formattedNextPause ?? '—' }}</div>
            </div>
            <div>
                <div class="stat-label">Next resume</div>
                <div class="stat-value">{{ $formattedNextResume ?? '—' }}</div>
            </div>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.pause-window.update') }}" class="card" style="gap: 20px;">
        @csrf
        @method('PUT')
        <div class="section-title">
            <span>Update pause window</span>
        </div>
        <div class="form-grid" style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Window enabled</span>
                <label style="display: flex; align-items: center; gap: 10px;">
                    <input type="hidden" name="enabled" value="0">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $record->enabled ?? $settings->enabled)) style="width: 16px;">
                    <span>Pause jobs during this window</span>
                </label>
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Start time</span>
                <input type="time" name="start_time" value="{{ old('start_time', $settings->startTime) }}" required>
                @error('start_time')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">End time</span>
                <input type="time" name="end_time" value="{{ old('end_time', $settings->endTime) }}" required>
                @error('end_time')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Timezone</span>
                <select name="timezone" required style="padding: 12px !important;">
                    @foreach($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', $settings->timezone) === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
                @error('timezone')
                    <span style="color: var(--error); font-size: 0.75rem;">{{ $message }}</span>
                @enderror
            </label>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            Windows that span midnight are supported. For example, selecting <code>22:00 → 06:00</code> will pause jobs overnight
            and resume them at 6:00 the following morning.
        </p>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">
                Save changes
            </button>
        </div>
    </form>
</div>
@endsection
