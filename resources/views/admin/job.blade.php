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
    $statusCounts = $summary['status_breakdown'] ?? [];
    $statusTotal = max(1, array_sum(array_map('intval', $statusCounts)));
    $latestRun = $summary['recent_runs']->first();
    $latestApi = $latestRun['api_calls'] ?? null;
    $windowApi = $summary['api_window_summary'] ?? null;
    $accentPalette = [
        'indigo' => 'rgba(129, 140, 248, 0.6)',
        'emerald' => 'rgba(16, 185, 129, 0.6)',
        'amber' => 'rgba(245, 158, 11, 0.6)',
        'rose' => 'rgba(244, 114, 182, 0.6)',
        'cyan' => 'rgba(6, 182, 212, 0.6)',
        'violet' => 'rgba(139, 92, 246, 0.6)',
        'slate' => 'rgba(100, 116, 139, 0.6)',
    ];

    $statusFilter = $statusFilter ?? null;

    $recentRunsForChart = $summary['recent_runs']->reverse()->values();
    $runChartData = [
        'labels'   => $recentRunsForChart->map(fn ($run) => \Illuminate\Support\Str::limit($run['run_id'] ?? 'run', 8))->values(),
        'apiCalls' => $recentRunsForChart->map(fn ($run) => (int) ($run['api_calls']['total'] ?? $run['api_call_total'] ?? 0))->values(),
        'durations'=> $recentRunsForChart->map(fn ($run) => round(((int) ($run['duration_seconds'] ?? 0)) / 60, 2))->values(),
    ];

    $statusChartData = [
        'labels' => ['Errors', 'Warnings', 'Success'],
        'data'   => [
            (int) ($statusCounts['error'] ?? 0),
            (int) ($statusCounts['warning'] ?? 0),
            (int) ($statusCounts['success'] ?? 0),
        ],
    ];
@endphp

<div class="stacked-section" style="gap: 32px;">
    <div class="cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
        <div class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }};">
            <div class="card-title">Total runs tracked</div>
            <div class="stat-value">{{ number_format($summary['total_runs']) }}</div>
            <div class="card-subtitle">All-time executions for this job</div>
        </div>
        <div class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }};">
            <div class="card-title">Runs in last {{ $summary['window_days'] }} day{{ $summary['window_days'] > 1 ? 's' : '' }}</div>
            <div class="stat-value">{{ number_format($summary['runs_last_window']) }}</div>
            <div class="card-subtitle">Compare behaviour inside the observation window</div>
        </div>
        <div class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }};">
            <div class="card-title">Last run completed</div>
            <div class="stat-value" style="font-size: 1.4rem;">
                {{ $summary['last_run_at'] ? $summary['last_run_at']->diffForHumans($appNow) : 'Never' }}
            </div>
            @if($latestRun)
                <div class="card-subtitle">Final status · <span class="{{ $statusClassMap[$latestRun['final_status']] ?? 'status-pill muted' }}">{{ strtoupper($latestRun['final_status']) }}</span></div>
            @else
                <div class="card-subtitle">No historical runs captured yet.</div>
            @endif
        </div>
        <div class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }};">
            <div class="card-title">Status mix</div>
            <div class="status-bar" style="margin-top: 12px;">
                @foreach(['error', 'warning', 'success', 'info'] as $status)
                    @php
                        $count = (int) ($statusCounts[$status] ?? 0);
                        $width = $count ? max(3, round(($count / $statusTotal) * 100, 2)) : 0;
                    @endphp
                    @if($width > 0)
                        <div class="status-segment {{ $status }}" style="width: {{ $width }}%;"></div>
                    @endif
                @endforeach
            </div>
            <div class="section-subtitle" style="margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap;">
                <span class="badge">Errors {{ $statusCounts['error'] ?? 0 }}</span>
                <span class="badge">Warnings {{ $statusCounts['warning'] ?? 0 }}</span>
                <span class="badge">Success {{ $statusCounts['success'] ?? 0 }}</span>
            </div>
        </div>
        <div class="card" style="border-top: 3px solid {{ $accentPalette[$job['accent']] ?? 'rgba(148,163,184,0.35)' }}; grid-column: span 2; min-width: 320px;">
            <div class="card-title">API usage</div>
            <p class="card-subtitle">Compare the latest run with the aggregated behaviour inside the current time window.</p>

            <div class="metrics-row" style="margin-top: 16px;">
                @if(!is_null($latestApi['total'] ?? null))
                    <div class="metric">
                        <span class="stat-label">Latest run calls</span>
                        <span class="stat-value">{{ number_format($latestApi['total']) }}</span>
                        <span class="card-subtitle">Most recent completion</span>
                    </div>
                @else
                    <div class="metric">
                        <span class="stat-label">Latest run calls</span>
                        <span class="stat-value">—</span>
                        <span class="card-subtitle">No telemetry recorded</span>
                    </div>
                @endif

                @if(!is_null($windowApi['total'] ?? null))
                    <div class="metric">
                        <span class="stat-label">Window total</span>
                        <span class="stat-value">{{ number_format($windowApi['total']) }}</span>
                        <span class="card-subtitle">{{ $summary['window_days'] }} day span</span>
                    </div>
                @else
                    <div class="metric">
                        <span class="stat-label">Window total</span>
                        <span class="stat-value">—</span>
                        <span class="card-subtitle">No telemetry recorded</span>
                    </div>
                @endif

                <div class="metric">
                    <span class="stat-label">Runs in window</span>
                    <span class="stat-value">{{ number_format($windowApi['runs'] ?? 0) }}</span>
                    <span class="card-subtitle">Included executions</span>
                </div>
            </div>

            <div class="card-subtitle" style="margin-top: 18px;">Endpoint breakdown</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                <div class="card" style="padding: 16px; background: rgba(129,140,248,0.07); border: 1px solid rgba(129,140,248,0.18);">
                    <div class="section-subtitle" style="margin-bottom: 8px;">Latest run</div>
                    @if(!empty($latestApi['breakdown']))
                        <ul style="margin: 0; padding-left: 18px;">
                            @foreach(array_slice($latestApi['breakdown'], 0, 6) as $entry)
                                @php
                                    $endpointLabel = $entry['label'];
                                    if (!empty($entry['method']) && !empty($entry['path'])) {
                                        $endpointLabel = $entry['method'] . ' ' . $entry['path'];
                                    } elseif (!empty($entry['method']) && !empty($entry['host'])) {
                                        $endpointLabel = $entry['method'] . ' ' . $entry['host'];
                                    }
                                @endphp
                                <li class="card-subtitle" style="margin-bottom: 6px;">{{ $endpointLabel }} · <strong>{{ number_format($entry['count']) }}</strong></li>
                            @endforeach
                        </ul>
                    @else
                        <div class="card-subtitle">No endpoints recorded.</div>
                    @endif
                </div>

                <div class="card" style="padding: 16px; background: rgba(20,184,166,0.07); border: 1px solid rgba(20,184,166,0.18);">
                    <div class="section-subtitle" style="margin-bottom: 8px;">{{ $summary['window_days'] }} day window</div>
                    @if(!empty($windowApi['breakdown']))
                        <ul style="margin: 0; padding-left: 18px;">
                            @foreach(array_slice($windowApi['breakdown'], 0, 8) as $entry)
                                @php
                                    $endpointLabel = $entry['label'];
                                    if (!empty($entry['method']) && !empty($entry['path'])) {
                                        $endpointLabel = $entry['method'] . ' ' . $entry['path'];
                                    } elseif (!empty($entry['method']) && !empty($entry['host'])) {
                                        $endpointLabel = $entry['method'] . ' ' . $entry['host'];
                                    }
                                @endphp
                                <li class="card-subtitle" style="margin-bottom: 6px;">{{ $endpointLabel }} · <strong>{{ number_format($entry['count']) }}</strong></li>
                            @endforeach
                        </ul>
                    @else
                        <div class="card-subtitle">No endpoints recorded in this window.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
        <div class="card" style="padding: 24px;">
            <div class="section-title">
                <span>Recent run trends</span>
                <span class="badge">Last {{ max(1, $recentRunsForChart->count()) }} runs</span>
            </div>
            <p class="section-subtitle">Track API calls and runtime duration across the latest executions.</p>
            <div style="position: relative; min-height: 220px;">
                <canvas id="job-run-trend-chart" height="200"></canvas>
            </div>
        </div>

        <div class="card" style="padding: 24px;">
            <div class="section-title">
                <span>Status distribution (window)</span>
            </div>
            <p class="section-subtitle">Proportion of status events recorded inside the selected window.</p>
            <div style="position: relative; min-height: 220px;">
                <canvas id="job-status-chart" height="200"></canvas>
            </div>
        </div>
    </div>

    <section class="stacked-section" style="gap: 16px;">
        <div class="section-title">
            <span>Run history</span>
            <div class="toolbar">
                <form method="get" class="toolbar" style="gap: 10px;">
                    <input type="hidden" name="run" value="{{ $search }}" />
                    <input type="hidden" name="status" value="{{ $statusFilter }}" />
                    <div class="input-group">
                        <label for="days" style="font-size: 0.8rem; color: var(--text-muted); margin-right: 6px;">Window</label>
                        <select id="days" name="days">
                            @foreach([1, 3, 7, 14, 30] as $option)
                                <option value="{{ $option }}" @selected($days === $option)>{{ $option }} day{{ $option > 1 ? 's' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </form>
            </div>
        </div>
        <p class="section-subtitle">Inspect every execution with duration, status and counts. Use the quick filter to locate a specific run id.</p>

        <form method="get" class="toolbar" style="justify-content: space-between;">
            <div class="badge">{{ $runs->total() }} runs total</div>
            <div class="toolbar" style="gap: 12px;">
                @if($days)
                    <input type="hidden" name="days" value="{{ $days }}">
                @endif
                <div class="input-group" style="max-width: 220px;">
                    <input type="search" name="run" value="{{ $search }}" placeholder="Filter by run id" aria-label="Filter by run id">
                </div>
                <div class="input-group">
                    <select name="status" aria-label="Filter by status">
                        <option value="" @selected(!$statusFilter)>All statuses</option>
                        @foreach(['success', 'warning', 'error', 'info'] as $statusOption)
                            <option value="{{ $statusOption }}" @selected($statusFilter === $statusOption)>{{ ucfirst($statusOption) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Search</button>
                <a href="{{ route('admin.jobs.show', [$job['key']]) }}" class="btn btn-secondary">Reset</a>
            </div>
        </form>

        <div class="card" style="padding: 0; overflow: hidden;">
            <table class="table">
                <thead>
                <tr>
                    <th>Run</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th>Finished</th>
                            <th>Duration</th>
                            <th>API calls</th>
                            <th>Events</th>
                            <th>Errors</th>
                            <th>Warnings</th>
                            <th>Success</th>
                </tr>
                </thead>
                <tbody>
                @forelse($runs as $run)
                    @php
                        $status = $run->final_status ?? 'info';
                        $statusPill = $statusClassMap[$status] ?? 'status-pill muted';
                    @endphp
                    <tr>
                        <td>
                            <span class="run-id-cell">
                                <a class="table-link" href="{{ route('admin.jobs.runs.show', [$job['key'], $run->run_id]) }}">{{ $run->run_id }}</a>
                                <button type="button" class="copy-button" data-copy-text="{{ $run->run_id }}" aria-label="Copy run id {{ $run->run_id }}">Copy</button>
                            </span>
                        </td>
                        <td><span class="{{ $statusPill }}">{{ strtoupper($status) }}</span></td>
                        <td>{{ $run->started_at?->format('M j · H:i:s') ?? '—' }}</td>
                        <td>{{ $run->finished_at?->format('M j · H:i:s') ?? '—' }}</td>
                        <td>{{ $run->duration_human ?? '—' }}</td>
                        <td>{{ isset($run->api_call_total) && !is_null($run->api_call_total) ? number_format($run->api_call_total) : '—' }}</td>
                        <td>{{ $run->event_count }}</td>
                        <td>{{ $run->error_count }}</td>
                        <td>{{ $run->warning_count }}</td>
                        <td>{{ $run->success_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <div class="empty-state">No runs recorded yet.</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="toolbar" style="justify-content: space-between;">
            <span class="card-subtitle">Page {{ $runs->currentPage() }} of {{ $runs->lastPage() }}</span>
            <div class="pagination">
                @if($runs->onFirstPage())
                    <span>Prev</span>
                @else
                    <a href="{{ $runs->previousPageUrl() }}">Prev</a>
                @endif

                @foreach($runs->getUrlRange(max(1, $runs->currentPage() - 2), min($runs->lastPage(), $runs->currentPage() + 2)) as $page => $url)
                    @if($page == $runs->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach

                @if($runs->hasMorePages())
                    <a href="{{ $runs->nextPageUrl() }}">Next</a>
                @else
                    <span>Next</span>
                @endif
            </div>
        </div>
    </section>

    <section class="card">
        <div class="section-title">
            <span>Recent issues</span>
        </div>
        @if($summary['recent_issues']->isEmpty())
            <div class="empty-state">No warnings or errors logged inside the current window.</div>
        @else
            <div class="issues-list">
                @foreach($summary['recent_issues'] as $log)
                    @php
                        $status = $log->status ?? 'info';
                        $statusPill = $statusClassMap[$status] ?? 'status-pill muted';
                    @endphp
                    <article class="issue-item {{ $status === 'warning' ? 'warning' : '' }}">
                        <div class="issue-title">
                            <span class="{{ $statusPill }}">{{ strtoupper($status) }}</span>
                            <span style="margin-left: 12px;">Action: {{ $log->action }}</span>
                        </div>
                        <p class="card-subtitle" style="margin: 0 0 8px;">{{ $log->message }}</p>
                        <div class="issue-meta">
                            <span class="run-id-cell">
                                <span>Run {{ $log->run_id }}</span>
                                <button type="button" class="copy-button" data-copy-text="{{ $log->run_id }}" aria-label="Copy run id {{ $log->run_id }}">Copy</button>
                            </span>
                            <span>{{ optional($log->created_at)?->diffForHumans($appNow) }}</span>
                            <a class="table-link" href="{{ route('admin.jobs.runs.show', [$job['key'], $log->run_id]) }}">View run</a>
                        </div>
                    </article>
                @endforeach
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
            const runCtx = document.getElementById('job-run-trend-chart');
            const statusCtx = document.getElementById('job-status-chart');

            const runData = @json($runChartData);
            const statusData = @json($statusChartData);

            if (runCtx && typeof Chart !== 'undefined' && runData.labels.length) {
                new Chart(runCtx, {
                    type: 'bar',
                    data: {
                        labels: runData.labels,
                        datasets: [
                            {
                                label: 'API calls',
                                data: runData.apiCalls,
                                backgroundColor: 'rgba(129, 140, 248, 0.6)',
                                borderRadius: 8,
                                yAxisID: 'y',
                            },
                            {
                                label: 'Duration (minutes)',
                                data: runData.durations,
                                borderColor: 'rgba(16, 185, 129, 0.9)',
                                backgroundColor: 'rgba(16, 185, 129, 0.25)',
                                type: 'line',
                                tension: 0.25,
                                yAxisID: 'y1',
                            }
                        ]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                position: 'left',
                                ticks: { color: '#cbd5f5' },
                                grid: { color: 'rgba(148, 163, 184, 0.1)' }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                ticks: { color: '#a7f3d0' },
                                grid: { drawOnChartArea: false }
                            },
                            x: {
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

            if (statusCtx && typeof Chart !== 'undefined' && statusData.data.some(v => v > 0)) {
                new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: statusData.labels,
                        datasets: [{
                            data: statusData.data,
                            backgroundColor: [
                                'rgba(248, 113, 113, 0.7)',
                                'rgba(234, 179, 8, 0.7)',
                                'rgba(34, 197, 94, 0.7)'
                            ],
                            borderColor: 'rgba(15, 23, 42, 0.85)',
                            borderWidth: 2,
                        }]
                    },
                    options: {
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
