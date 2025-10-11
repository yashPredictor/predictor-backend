@extends('layouts.admin')

@section('content')
@php
    $statsQueues = collect($stats['queues'] ?? []);
    $topJobTypes = collect($stats['top_job_types'] ?? []);
    $topReasons  = collect($stats['top_reasons'] ?? []);
    $sampleSize  = (int) ($stats['sample_size'] ?? 0);
    $oldestFail  = $stats['oldest'] ?? null;
    $latestFail  = $stats['latest'] ?? null;
@endphp

<div class="stacked-section" style="display: flex; flex-direction: column; gap: 28px;">
    <section class="card" style="gap: 18px;">
        <div class="section-title">
            <span>Failure analytics</span>
            <span class="badge">{{ number_format($stats['total'] ?? 0) }} recorded</span>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            Monitoring failure volume across queues helps spot systemic issues early. Metrics below update in real time as jobs fail or are retried.
        </p>
        <div class="metrics-row">
            <div class="metric">
                <span class="stat-value">{{ number_format($stats['last_hour'] ?? 0) }}</span>
                <span class="stat-label">Last hour</span>
            </div>
            <div class="metric">
                <span class="stat-value">{{ number_format($stats['last_day'] ?? 0) }}</span>
                <span class="stat-label">Last 24 hours</span>
            </div>
            <div class="metric">
                <span class="stat-value">{{ $latestFail ? $latestFail->diffForHumans() : '—' }}</span>
                <span class="stat-label">Most recent failure</span>
            </div>
            <div class="metric">
                <span class="stat-value">{{ $oldestFail ? $oldestFail->diffForHumans() : '—' }}</span>
                <span class="stat-label">Oldest on record</span>
            </div>
        </div>
        <div class="section-subtitle" style="margin-top: 16px;">Hot queues</div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            @forelse($statsQueues as $queueStat)
                <span class="badge">{{ $queueStat['queue'] ?: 'default' }} · {{ number_format($queueStat['count']) }}</span>
            @empty
                <span class="badge">No queue data yet</span>
            @endforelse
        </div>
        <div class="section-subtitle" style="margin-top: 16px;">Top failing jobs <span class="stat-label" style="margin-left: 10px;">based on last {{ $sampleSize }} failures</span></div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            @forelse($topJobTypes as $jobStat)
                <span class="badge">{{ $jobStat['label'] }} · {{ number_format($jobStat['count']) }}</span>
            @empty
                <span class="badge">No data yet</span>
            @endforelse
        </div>
        <div class="section-subtitle" style="margin-top: 16px;">Common failure reasons</div>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            @forelse($topReasons as $reasonStat)
                <div style="background: rgba(30, 41, 59, 0.6); border-radius: 12px; padding: 10px 14px;">
                    <div style="font-size: 0.88rem;">{{ $reasonStat['label'] }}</div>
                    <div class="stat-label">Seen {{ number_format($reasonStat['count']) }} times</div>
                </div>
            @empty
                <span class="badge">No reason breakdown available</span>
            @endforelse
        </div>
    </section>

    <section class="card" style="gap: 16px;">
        <div class="section-title">
            <span>Search &amp; filtering</span>
        </div>
        <form method="GET" action="{{ route('admin.failed-jobs.index') }}" class="form-grid" style="display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));">
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Search</span>
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="UUID, message, payload…">
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Queue</span>
                <select name="queue">
                    <option value="">All queues</option>
                    @foreach($queues as $queueName)
                        <option value="{{ $queueName }}" @selected(($filters['queue'] ?? '') === $queueName)>{{ $queueName }}</option>
                    @endforeach
                </select>
            </label>
            <label class="form-control" style="display: flex; flex-direction: column; gap: 8px;">
                <span class="stat-label">Results per page</span>
                <select name="per_page">
                    @foreach([10, 25, 50, 75, 100] as $size)
                        <option value="{{ $size }}" @selected(($filters['per_page'] ?? 25) == $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </label>
            <div style="display: flex; align-items: flex-end; gap: 12px;">
                <button type="submit" class="btn btn-primary">
                    Apply
                </button>
                <a href="{{ route('admin.failed-jobs.index') }}" class="btn btn-secondary">
                    Reset
                </a>
            </div>
        </form>
    </section>

    <section class="card" style="padding: 0;">
        <div style="padding: 24px 24px 0; display: flex; justify-content: space-between; align-items: baseline;">
            <div>
                <div class="card-title">Failed jobs</div>
                <div class="card-subtitle">{{ number_format($jobs->total()) }} matching result{{ $jobs->total() === 1 ? '' : 's' }}</div>
            </div>
            <div class="card-subtitle">
                Page {{ $jobs->currentPage() }} of {{ $jobs->lastPage() }}
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Failed at</th>
                        <th>Job</th>
                        <th>Queue</th>
                        <th>Connection</th>
                        <th>Reasoning</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                        <tr>
                            <td>
                                <div>{{ optional($job['failed_at'])->format('M j, Y H:i') }}</div>
                                <div class="stat-label">{{ optional($job['failed_at'])->diffForHumans() }}</div>
                                <div class="stat-label">UUID {{ $job['uuid'] }}</div>
                            </td>
                            <td>
                                <div style="font-weight: 600;">{{ $job['display_name'] }}</div>
                                @if($job['job_class'])
                                    <div class="stat-label">{{ $job['job_class'] }}</div>
                                @endif
                                @if(!is_null($job['attempts']))
                                    <span class="badge" style="margin-top: 8px;">Attempts {{ $job['attempts'] }}</span>
                                @endif
                            </td>
                            <td>
                                <div>{{ $job['queue'] ?: 'default' }}</div>
                            </td>
                            <td>
                                <div>{{ $job['connection'] }}</div>
                            </td>
                            <td>
                                <div>{{ $job['exception_headline'] }}</div>
                                <details style="margin-top: 8px;">
                                    <summary class="stat-label" style="cursor: pointer;">Expand reasoning</summary>
                                    <pre style="margin-top: 8px; white-space: pre-wrap; font-size: 0.78rem; background: rgba(15, 23, 42, 0.6); padding: 12px; border-radius: 12px; max-height: 220px; overflow-y: auto;">{{ $job['exception'] }}</pre>
                                </details>
                                @if($job['payload_json'])
                                    <details style="margin-top: 8px;">
                                        <summary class="stat-label" style="cursor: pointer;">View payload</summary>
                                        <pre style="margin-top: 8px; white-space: pre-wrap; font-size: 0.78rem; background: rgba(15, 23, 42, 0.6); padding: 12px; border-radius: 12px; max-height: 220px; overflow-y: auto;">{{ $job['payload_json'] }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.failed-jobs.retry', $job['id']) }}" style="display: flex; flex-direction: column; gap: 8px;">
                                    @csrf
                                    <input type="hidden" name="search" value="{{ $filters['search'] ?? '' }}">
                                    <input type="hidden" name="queue" value="{{ $filters['queue'] ?? '' }}">
                                    <input type="hidden" name="per_page" value="{{ $filters['per_page'] ?? 25 }}">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Re-dispatch this job? It will be removed from failed jobs.');">
                                        Resync
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 24px;">
                                <div class="stat-label">No failed jobs match your filters.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="toolbar" style="justify-content: space-between; padding: 16px 24px;">
            @php
                $start = $jobs->firstItem();
                $end = $jobs->lastItem();
            @endphp
            <span class="card-subtitle">
                @if($jobs->total())
                    Showing {{ $start }}-{{ $end }} of {{ number_format($jobs->total()) }}
                @else
                    No matching jobs
                @endif
            </span>
            <div class="pagination">
                @if($jobs->onFirstPage())
                    <span>Prev</span>
                @else
                    <a href="{{ $jobs->previousPageUrl() }}">Prev</a>
                @endif

                @foreach($jobs->getUrlRange(max(1, $jobs->currentPage() - 2), min($jobs->lastPage(), $jobs->currentPage() + 2)) as $page => $url)
                    @if($page == $jobs->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach

                @if($jobs->hasMorePages())
                    <a href="{{ $jobs->nextPageUrl() }}">Next</a>
                @else
                    <span>Next</span>
                @endif
            </div>
        </div>
    </section>
</div>
@endsection
