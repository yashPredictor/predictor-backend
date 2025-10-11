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
        'cyan' => 'rgba(6, 182, 212, 0.6)',
        'violet' => 'rgba(139, 92, 246, 0.6)',
        'slate' => 'rgba(100, 116, 139, 0.6)',
        'teal' => 'rgba(20, 184, 166, 0.6)',
        'fuchsia' => 'rgba(217, 70, 239, 0.6)',
        'lime' => 'rgba(132, 204, 22, 0.6)',
    ];

    $statusFilter = $statusFilter ?? null;
    $searchTerm = $searchTerm ?? '';

    $statusChartData = [
        'labels' => ['Errors', 'Warnings', 'Success', 'Info'],
        'data'   => [
            (int) ($run['error_count'] ?? 0),
            (int) ($run['warning_count'] ?? 0),
            (int) ($run['success_count'] ?? 0),
            (int) ($run['info_count'] ?? 0),
        ],
    ];

    $apiBreakdown = collect($run['api_calls']['breakdown'] ?? [])
        ->take(6)
        ->map(function ($entry) {
            return [
                'label' => $entry['label'] ?? ($entry['path'] ?? 'Endpoint'),
                'count' => (int) ($entry['count'] ?? 0),
            ];
        });

    $apiChartData = [
        'labels' => $apiBreakdown->pluck('label'),
        'data'   => $apiBreakdown->pluck('count'),
    ];

    $disabled = $disabled ?? ($run['disabled'] ?? false);
@endphp

<div class="stacked-section" style="gap: 28px;">
    @if($disabled)
        <section class="card" style="border-left: 4px solid rgba(234, 179, 8, 0.6); background: rgba(22, 45, 75, 0.7);">
            <div class="section-title" style="margin-bottom: 8px;">
                <span>Emergency pause active</span>
                <span class="status-pill warning">PAUSED</span>
            </div>
            <p class="section-subtitle" style="margin: 0;">
                This job is currently disabled via the emergency controls. No new runs will be scheduled until it is resumed.
            </p>
        </section>
    @endif

    <section class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }};">
        <div class="section-title">
            <div class="run-id-cell">
                <span>{{ $job['label'] }} · Run {{ $run['run_id'] }}</span>
                <button type="button" class="copy-button" data-copy-text="{{ $run['run_id'] }}" aria-label="Copy run id {{ $run['run_id'] }}">Copy</button>
            </div>
            <div class="toolbar" style="gap: 12px;">
                <a class="btn btn-secondary" href="{{ route('admin.jobs.show', [$job['key']]) }}">Back to job runs</a>
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
                <span class="stat-label">API calls</span>
                <span class="stat-value">{{ isset($run['api_calls']['total']) && !is_null($run['api_calls']['total']) ? number_format($run['api_calls']['total']) : '—' }}</span>
                <span class="card-subtitle">Captured from job telemetry</span>
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

        <form method="get" class="toolbar" style="margin-top: 22px; gap: 12px; flex-wrap: wrap;">
            <div class="input-group" style="max-width: 260px;">
                <input type="search" name="q" value="{{ $searchTerm }}" placeholder="Search message or action" aria-label="Search log entries">
            </div>
            <div class="input-group">
                <select name="status" aria-label="Filter by status">
                    <option value="" @selected(!$statusFilter)>All statuses</option>
                    @foreach(['success', 'warning', 'error', 'info'] as $statusOption)
                        <option value="{{ $statusOption }}" @selected($statusFilter === $statusOption)>{{ ucfirst($statusOption) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Apply filters</button>
            <a href="{{ route('admin.jobs.runs.show', [$job['key'], $run['run_id']]) }}" class="btn btn-secondary">Reset</a>
        </form>

        <div class="metrics-row" style="margin-top: 24px; flex-wrap: wrap;">
            <div class="chart-card">
                <div class="section-subtitle" style="margin: 0 0 12px;">Event distribution</div>
                <canvas id="run-status-chart" height="180"></canvas>
            </div>
            <div class="chart-card">
                <div class="section-subtitle" style="margin: 0 0 12px;">API calls breakdown</div>
                <canvas id="run-api-chart" height="180"></canvas>
            </div>
        </div>

        @if(!empty($run['api_calls']['breakdown']))
            <div class="section-subtitle" style="margin-top: 18px;">API breakdown</div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                @php
                    $apiTotal = $run['api_calls']['total'] ?? null;
                @endphp
                <span class="badge" style="background: rgba(129, 140, 248, 0.15);">Total {{ !is_null($apiTotal) ? number_format($apiTotal) : '—' }}</span>
                @foreach($run['api_calls']['breakdown'] as $entry)
                    @php
                        $endpointLabel = $entry['label'];
                        if (!empty($entry['method']) && !empty($entry['path'])) {
                            $endpointLabel = $entry['method'] . ' ' . $entry['path'];
                        } elseif (!empty($entry['method']) && !empty($entry['host'])) {
                            $endpointLabel = $entry['method'] . ' ' . $entry['host'];
                        }
                    @endphp
                    <span class="badge">{{ $endpointLabel }} · {{ number_format($entry['count']) }}</span>
                @endforeach
            </div>
        @endif
    </section>

    <section class="card">
        <div class="section-title">
            <span>Timeline</span>
            <span class="badge">{{ $logTotal }} entries</span>
        </div>
        <div class="timeline">
            @forelse($logs as $log)
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
            @empty
                <div class="empty-state">No logs captured for this page.</div>
            @endforelse
        </div>

        @if($logs->hasPages())
            <div class="toolbar" style="margin-top: 20px; justify-content: space-between;">
                <span class="card-subtitle">Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
                <div class="pagination">
                    @if($logs->onFirstPage())
                        <span>Prev</span>
                    @else
                        <a href="{{ $logs->previousPageUrl() }}">Prev</a>
                    @endif

                    @foreach($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                        @if($page == $logs->currentPage())
                            <span class="active">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if($logs->hasMorePages())
                        <a href="{{ $logs->nextPageUrl() }}">Next</a>
                    @else
                        <span>Next</span>
                    @endif
                </div>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
    @once
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    @endonce
    <script>
        (function () {
            const statusEl = document.getElementById('run-status-chart');
            const apiEl = document.getElementById('run-api-chart');

            const statusData = @json($statusChartData);
            const apiData = @json($apiChartData);

            if (statusEl && typeof Chart !== 'undefined') {
                new Chart(statusEl, {
                    type: 'doughnut',
                    data: {
                        labels: statusData.labels,
                        datasets: [{
                            data: statusData.data,
                            backgroundColor: [
                                'rgba(248, 113, 113, 0.7)',
                                'rgba(234, 179, 8, 0.7)',
                                'rgba(34, 197, 94, 0.7)',
                                'rgba(148, 163, 184, 0.7)'
                            ],
                            borderColor: 'rgba(15, 23, 42, 0.85)',
                            borderWidth: 2,
                        }]
                    },
                    options: {
                        plugins: {
                            legend: {
                                labels: {
                                    color: '#cbd5f5'
                                }
                            }
                        }
                    }
                });
            }

            if (apiEl && typeof Chart !== 'undefined' && apiData.data.length) {
                new Chart(apiEl, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Calls',
                            data: apiData.data,
                            backgroundColor: 'rgba(129, 140, 248, 0.6)',
                            borderRadius: 6,
                        }]
                    },
                    options: {
                        scales: {
                            x: {
                                ticks: { color: '#cbd5f5' },
                                grid: { color: 'rgba(148, 163, 184, 0.1)' }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: { color: '#cbd5f5' },
                                grid: { color: 'rgba(148, 163, 184, 0.1)' }
                            }
                        },
                        plugins: {
                            legend: {
                                labels: { color: '#cbd5f5' }
                            }
                        }
                    }
                });
            }
        })();
    </script>
@endpush
