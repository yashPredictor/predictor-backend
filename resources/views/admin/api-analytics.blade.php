@extends('layouts.admin')

@section('content')
@php
    $statusClassMap = [
        true => 'status-pill error',
        false => 'status-pill success',
    ];
@endphp

<div class="stacked-section" style="gap: 28px;">
    <form method="get" class="toolbar" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
        <label class="toolbar-item">
            <span class="toolbar-label">Window</span>
            <select name="days">
                @foreach([1, 3, 7, 14, 30] as $option)
                    <option value="{{ $option }}" @selected($filters['days'] === $option)>{{ $option }} day{{ $option > 1 ? 's' : '' }}</option>
                @endforeach
            </select>
        </label>

        <label class="toolbar-item">
            <span class="toolbar-label">Job</span>
            <select name="job">
                <option value="">All</option>
                @foreach($jobKeys as $jobKey)
                    <option value="{{ $jobKey }}" @selected($filters['job'] === $jobKey)>{{ $jobKey }}</option>
                @endforeach
            </select>
        </label>

        <label class="toolbar-item">
            <span class="toolbar-label">Status</span>
            <select name="status">
                <option value="">All</option>
                <option value="success" @selected($filters['status'] === 'success')>Success</option>
                <option value="error" @selected($filters['status'] === 'error')>Error</option>
            </select>
        </label>

        <label class="toolbar-item">
            <span class="toolbar-label">Method</span>
            <select name="method">
                <option value="">All</option>
                @foreach($methods as $method)
                    <option value="{{ $method }}" @selected($filters['method'] === $method)>{{ $method }}</option>
                @endforeach
            </select>
        </label>

        <label class="toolbar-item">
            <span class="toolbar-label">Tag</span>
            <input type="text" name="tag" value="{{ $filters['tag'] }}" placeholder="match_overs, live_matches...">
        </label>

        <button type="submit" class="btn btn-primary">Apply</button>
    </form>

    <section class="cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <article class="card stat-card">
            <div class="stat-label">Total Calls</div>
            <div class="stat-value">{{ number_format($totalCalls) }}</div>
        </article>
        <article class="card stat-card">
            <div class="stat-label">Success</div>
            <div class="stat-value">{{ number_format($successCalls) }}</div>
        </article>
        <article class="card stat-card">
            <div class="stat-label">Errors</div>
            <div class="stat-value">{{ number_format($errorCalls) }}</div>
        </article>
        <article class="card stat-card">
            <div class="stat-label">Avg Duration</div>
            <div class="stat-value">{{ $averageDuration !== null ? $averageDuration . ' ms' : '—' }}</div>
        </article>
    </section>

    <div class="cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px;">
        <section class="card" style="display: grid; gap: 12px;">
            <div class="section-title">
                <span>Top Hosts</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Host</th>
                            <th style="width: 90px;">Calls</th>
                            <th style="width: 90px;">Errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topHosts as $host)
                            <tr>
                                <td>{{ $host->host ?? '—' }}</td>
                                <td>{{ number_format($host->total) }}</td>
                                <td>{{ number_format($host->error_total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" style="display: grid; gap: 12px;">
            <div class="section-title">
                <span>Top Tags</span>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tag</th>
                            <th style="width: 90px;">Calls</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($topTags as $tag)
                            <tr>
                                <td>{{ $tag->tag ?? '—' }}</td>
                                <td>{{ number_format($tag->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2">No data</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card" style="display: grid; gap: 16px;">
        <div class="section-title">
            <span>Recent API Calls</span>
            <span class="badge">{{ number_format($logs->total()) }} total</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Job</th>
                        <th>Tag</th>
                        <th>Method</th>
                        <th>Endpoint</th>
                        <th>Status</th>
                        <th>Duration</th>
                        <th>Bytes</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td>{{ $log->requested_at?->timezone(config('app.timezone', 'UTC'))->format('M d · H:i:s') ?? '—' }}</td>
                            <td>{{ $log->job_key ?? '—' }}</td>
                            <td>{{ $log->tag ?? '—' }}</td>
                            <td>{{ $log->method }}</td>
                            <td style="max-width: 360px;">
                                <div class="truncate">{{ $log->host ? ($log->host . ($log->path ?? '')) : $log->url }}</div>
                            </td>
                            <td>
                                <span class="{{ $statusClassMap[$log->is_error] }}">
                                    {{ $log->status_code ?? '—' }}
                                </span>
                            </td>
                            <td>{{ $log->duration_ms !== null ? $log->duration_ms . ' ms' : '—' }}</td>
                            <td>{{ $log->response_bytes !== null ? number_format($log->response_bytes) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">No API activity in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </section>
</div>
@endsection
