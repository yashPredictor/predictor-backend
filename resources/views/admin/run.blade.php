@extends('layouts.admin')

@section('content')
@php
    $appNow = now(config('app.timezone', 'UTC'));
    $statusClassMap = [
        'success' => 'status-pill success',
        'warning' => 'status-pill warning',
        'error'   => 'status-pill error',
        'info'    => 'status-pill info',
    ];
    $dotClassMap = [
        'success' => 'success',
        'warning' => 'warning',
        'error'   => 'error',
        'info'    => 'info',
    ];
    $accentPalette = [
        'indigo' => 'rgba(129, 140, 248, 0.6)',
        'emerald' => 'rgba(16, 185, 129, 0.6)',
        'amber' => 'rgba(245, 158, 11, 0.6)',
        'rose' => 'rgba(244, 114, 182, 0.6)',
    ];
@endphp

<div class="stacked-section" style="gap: 28px;">
    <section class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }};">
        <div class="section-title">
            <div class="run-id-cell">
                <span>{{ $job['label'] }} · Run {{ $run['run_id'] }}</span>
                <button type="button" class="copy-button" data-copy-text="{{ $run['run_id'] }}" aria-label="Copy run id {{ $run['run_id'] }}">Copy</button>
            </div>
            <div class="toolbar" style="gap: 12px;">
                <a class="pill" href="{{ route('admin.jobs.show', [$job['key']]) }}">Back to job runs</a>
                <span class="{{ $statusClassMap[$run['final_status']] ?? 'status-pill muted' }}">{{ strtoupper($run['final_status']) }}</span>
            </div>
        </div>
        <p class="section-subtitle">{{ $job['description'] }}</p>
        <div class="metrics-row">
            <div class="metric">
                <span class="stat-label">Started</span>
                <span class="stat-value" style="font-size: 1.3rem;">{{ $run['started_at']?->format('M j · H:i:s') ?? '—' }}</span>
                <span class="card-subtitle">{{ $run['started_at']?->diffForHumans($appNow) }}</span>
            </div>
            <div class="metric">
                <span class="stat-label">Finished</span>
                <span class="stat-value" style="font-size: 1.3rem;">{{ $run['finished_at']?->format('M j · H:i:s') ?? '—' }}</span>
                <span class="card-subtitle">{{ $run['finished_at']?->diffForHumans($appNow) }}</span>
            </div>
            <div class="metric">
                <span class="stat-label">Duration</span>
                <span class="stat-value">{{ $run['duration_human'] ?? '—' }}</span>
                <span class="card-subtitle">{{ $run['total_events'] }} logged events</span>
            </div>
            <div class="metric">
                <span class="stat-label">Errors</span>
                <span class="stat-value" style="color: var(--error);">{{ $run['error_count'] }}</span>
            </div>
            <div class="metric">
                <span class="stat-label">Warnings</span>
                <span class="stat-value" style="color: var(--warning);">{{ $run['warning_count'] }}</span>
            </div>
            <div class="metric">
                <span class="stat-label">Success events</span>
                <span class="stat-value" style="color: var(--success);">{{ $run['success_count'] }}</span>
            </div>
        </div>
        @if($run['summary_message'])
            <div class="card-subtitle" style="margin-top: 18px;">Summary: {{ $run['summary_message'] }}</div>
        @endif
    </section>

    <section class="card">
        <div class="section-title">
            <span>Timeline</span>
            <span class="badge">{{ $logs->count() }} entries</span>
        </div>
        <div class="timeline">
            @foreach($logs as $log)
                @php
                    $status = $log->status ?? 'info';
                    $pill = $statusClassMap[$status] ?? 'status-pill muted';
                    $context = $log->context ? json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;
                @endphp
                <article class="timeline-item">
                    <span class="timeline-dot {{ $dotClassMap[$status] ?? 'muted' }}"></span>
                    <div class="timeline-header">
                        <span class="{{ $pill }}">{{ strtoupper($status) }}</span>
                        <span class="timestamp">{{ optional($log->created_at)->format('M j, H:i:s') }}</span>
                    </div>
                    <p class="message">{{ $log->message }}</p>
                    <div class="issue-meta" style="margin-top: 6px;">
                        <span>Action: {{ $log->action }}</span>
                        <span class="run-id-cell">
                            <span>Run: {{ $log->run_id }}</span>
                            <button type="button" class="copy-button" data-copy-text="{{ $log->run_id }}" aria-label="Copy run id {{ $log->run_id }}">Copy</button>
                        </span>
                        <span>{{ optional($log->created_at)?->diffForHumans($appNow) }}</span>
                    </div>
                    @if($context)
                        <details style="margin-top: 10px;">
                            <summary class="card-subtitle" style="cursor: pointer;">Context payload</summary>
                            <pre class="json">{{ $context }}</pre>
                        </details>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
</div>
@endsection
