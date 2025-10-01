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
    $accentPalette = [
        'indigo' => 'rgba(129, 140, 248, 0.6)',
        'emerald' => 'rgba(16, 185, 129, 0.6)',
        'amber' => 'rgba(245, 158, 11, 0.6)',
        'rose' => 'rgba(244, 114, 182, 0.6)',
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
    </div>

    <section class="stacked-section" style="gap: 16px;">
        <div class="section-title">
            <span>Run history</span>
            <div class="toolbar">
                <form method="get" class="toolbar" style="gap: 10px;">
                    <input type="hidden" name="run" value="{{ $search }}" />
                    <div class="input-group">
                        <label for="days" style="font-size: 0.8rem; color: var(--text-muted); margin-right: 6px;">Window</label>
                        <select id="days" name="days">
                            @foreach([1, 3, 7, 14, 30] as $option)
                                <option value="{{ $option }}" @selected($days === $option)>{{ $option }} day{{ $option > 1 ? 's' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="pill" style="background: rgba(129, 140, 248, 0.25); color: var(--text-primary); border: 1px solid rgba(129, 140, 248, 0.35); cursor: pointer;">Apply</button>
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
                <div class="input-group">
                    <input type="search" name="run" value="{{ $search }}" placeholder="Filter by run id" aria-label="Filter by run id">
                </div>
                <button type="submit" class="pill" style="background: rgba(15, 118, 110, 0.35); color: var(--text-primary); border: 1px solid rgba(20, 184, 166, 0.35); cursor: pointer;">Search</button>
                <a href="{{ route('admin.jobs.show', [$job['key']]) }}" class="pill" style="background: transparent; border: 1px solid var(--divider);">Reset</a>
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
                        <td>{{ $run->event_count }}</td>
                        <td>{{ $run->error_count }}</td>
                        <td>{{ $run->warning_count }}</td>
                        <td>{{ $run->success_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
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
