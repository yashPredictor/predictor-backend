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
    $accentPalette = [
        'indigo' => 'rgba(129, 140, 248, 0.6)',
        'emerald' => 'rgba(16, 185, 129, 0.6)',
        'amber' => 'rgba(245, 158, 11, 0.6)',
        'rose' => 'rgba(244, 114, 182, 0.6)',
    ];

    $aggregateIssues = collect($summaries)
        ->flatMap(function ($summary) {
            return $summary['recent_issues']->map(function ($log) use ($summary) {
                return [
                    'log'       => $log,
                    'job_key'   => $summary['key'],
                    'job_label' => $summary['label'],
                ];
            });
        })
        ->sortByDesc(fn ($item) => $item['log']->created_at)
        ->take(6);
@endphp

<div class="stacked-section" style="gap: 32px;">
    <form method="get" class="toolbar" style="justify-content: flex-end;">
        <div class="input-group">
            <label for="days" style="font-size: 0.8rem; color: var(--text-muted); margin-right: 6px;">Window</label>
            <select id="days" name="days">
                @foreach([1, 3, 7, 14, 30] as $option)
                    <option value="{{ $option }}" @selected($days === $option)>{{ $option }} day{{ $option > 1 ? 's' : '' }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="pill" style="background: rgba(129, 140, 248, 0.25); color: var(--text-primary); border: 1px solid rgba(129, 140, 248, 0.35); cursor: pointer;">Refresh</button>
    </form>

    <div class="cards-grid">
        @foreach($summaries as $summary)
            @php
                $latestRun = $summary['recent_runs']->first();
                $latestStatus = $latestRun['final_status'] ?? null;
                $statusClass = $statusClassMap[$latestStatus] ?? 'status-pill muted';
                $statusLabel = strtoupper($latestStatus ?? 'N/A');
                $statusCounts = $summary['status_breakdown'] ?? [];
                $statusOrder = ['error', 'warning', 'success', 'info'];
                $statusTotal = max(1, array_sum(array_map('intval', $statusCounts)));
            @endphp
            <a class="card" href="{{ route('admin.jobs.show', $summary['key']) }}" style="border-top: 3px solid {{ $accentPalette[$summary['accent']] ?? 'rgba(148,163,184,0.35)' }};">
                <div class="card-header">
                    <div>
                        <div class="card-title">{{ $summary['label'] }}</div>
                        <div class="card-subtitle">{{ $summary['description'] }}</div>
                    </div>
                    <span class="{{ $statusClass }}">{{ $statusLabel }}</span>
                </div>

                <div class="metrics-row">
                    <div class="metric">
                        <span class="stat-value">{{ number_format($summary['total_runs']) }}</span>
                        <span class="stat-label">Total runs</span>
                    </div>
                    <div class="metric">
                        <span class="stat-value">{{ number_format($summary['runs_last_window']) }}</span>
                        <span class="stat-label">Runs in {{ $summary['window_days'] }} day{{ $summary['window_days'] > 1 ? 's' : '' }}</span>
                    </div>
                    <div class="metric">
                        <span class="stat-value" style="font-size: 1.4rem;">
                            {{ $summary['last_run_at'] ? $summary['last_run_at']->diffForHumans($appNow) : 'Never' }}
                        </span>
                        <span class="stat-label">Last activity</span>
                    </div>
                </div>

                <div>
                    <div class="section-subtitle" style="margin-bottom: 8px;">Status mix ({{ $summary['window_days'] }} day window)</div>
                    <div class="status-bar">
                        @foreach($statusOrder as $status)
                            @php
                                $count = (int) ($statusCounts[$status] ?? 0);
                                $width = $count ? max(3, round(($count / $statusTotal) * 100, 2)) : 0;
                            @endphp
                            @if($width > 0)
                                <div class="status-segment {{ $status }}" style="width: {{ $width }}%;"></div>
                            @endif
                        @endforeach
                    </div>
                    <div class="section-subtitle" style="margin-top: 10px; display: flex; gap: 12px;">
                        <span class="badge">Errors {{ $statusCounts['error'] ?? 0 }}</span>
                        <span class="badge">Warnings {{ $statusCounts['warning'] ?? 0 }}</span>
                        <span class="badge">Success {{ $statusCounts['success'] ?? 0 }}</span>
                    </div>
                </div>

                @if($latestRun)
                    <div class="card-subtitle" style="margin-top: auto;">Latest run · {{ $latestRun['run_id'] }} · {{ $latestRun['finished_at']?->diffForHumans($appNow) }}</div>
                @endif
            </a>
        @endforeach
    </div>

    @if($aggregateIssues->isNotEmpty())
        <section class="card">
            <div class="section-title">
                <span>Latest issues across jobs</span>
                <span class="badge">{{ $aggregateIssues->count() }} highlighted</span>
            </div>
            <div class="issues-list">
                @foreach($aggregateIssues as $item)
                    @php
                        $log = $item['log'];
                        $status = $log->status ?? 'info';
                        $statusPill = $statusClassMap[$status] ?? 'status-pill muted';
                        $accent = $summaries[$item['job_key']]['accent'] ?? null;
                        $accentColor = $accentPalette[$accent] ?? 'rgba(129, 140, 248, 0.35)';
                    @endphp
                    <article class="issue-item {{ $status === 'warning' ? 'warning' : '' }}" style="border-left: 4px solid {{ $accentColor }};">
                        <div class="issue-title">
                            <span class="{{ $statusPill }}">{{ strtoupper($status) }}</span>
                            <span style="margin-left: 12px;">{{ $item['job_label'] }} · {{ $log->action }}</span>
                        </div>
                        <p class="card-subtitle" style="margin: 0 0 8px;">{{ $log->message }}</p>
                        <div class="issue-meta">
                            <span class="run-id-cell">
                                <span>Run {{ $log->run_id }}</span>
                                <button type="button" class="copy-button" data-copy-text="{{ $log->run_id }}" aria-label="Copy run id {{ $log->run_id }}">Copy</button>
                            </span>
                            <span>{{ optional($log->created_at)?->diffForHumans($appNow) }}</span>
                            <a class="table-link" href="{{ route('admin.jobs.runs.show', [$item['job_key'], $log->run_id]) }}">View run</a>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @foreach($summaries as $summary)
        <section id="job-{{ $summary['key'] }}" class="stacked-section" style="gap: 16px;">
            <div class="section-title">
                <span>{{ $summary['label'] }} · Recent runs</span>
                <a class="pill" href="{{ route('admin.jobs.show', $summary['key']) }}">Open job view</a>
            </div>
            <p class="section-subtitle">{{ $summary['description'] }}</p>
            @if($summary['recent_runs']->isEmpty())
                <div class="empty-state">No runs captured for this job yet.</div>
            @else
                <div class="card" style="padding: 0;">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Run</th>
                            <th>Status</th>
                            <th>Started</th>
                            <th>Finished</th>
                            <th>Duration</th>
                            <th>Events</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($summary['recent_runs'] as $run)
                            @php
                                $status = $run['final_status'] ?? 'info';
                                $statusPill = $statusClassMap[$status] ?? 'status-pill muted';
                            @endphp
                            <tr>
                                <td>
                                    <span class="run-id-cell">
                                        <a class="table-link" href="{{ route('admin.jobs.runs.show', [$summary['key'], $run['run_id']]) }}">{{ $run['run_id'] }}</a>
                                        <button type="button" class="copy-button" data-copy-text="{{ $run['run_id'] }}" aria-label="Copy run id {{ $run['run_id'] }}">Copy</button>
                                    </span>
                                </td>
                                <td><span class="{{ $statusPill }}">{{ strtoupper($status) }}</span></td>
                                <td>{{ $run['started_at']?->format('M j · H:i:s') ?? '—' }}</td>
                                <td>{{ $run['finished_at']?->diffForHumans($appNow) ?? '—' }}</td>
                                <td>{{ $run['duration_human'] ?? '—' }}</td>
                                <td>{{ $run['total_events'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endforeach
</div>
@endsection
