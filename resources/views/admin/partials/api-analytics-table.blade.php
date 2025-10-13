@foreach($logs as $log)
    <tr>
        <td>{{ $log->requested_at?->timezone(config('app.timezone', 'UTC'))->format('M d · H:i:s') ?? '—' }}</td>
        <td>{{ $log->job_key ?? '—' }}</td>
        <td>{{ $log->tag ?? '—' }}</td>
        <td>{{ $log->method }}</td>
        <td style="max-width: 360px;"><div class="truncate">{{ $log->host ? ($log->host . ($log->path ?? '')) : $log->url }}</div></td>
        <td><span class="{{ $statusClassMap[$log->is_error] ?? 'status-pill muted' }}">{{ $log->status_code ?? '—' }}</span></td>
        <td>{{ $log->duration_ms !== null ? $log->duration_ms . ' ms' : '—' }}</td>
        <td>{{ $log->response_bytes !== null ? number_format($log->response_bytes) : '—' }}</td>
        <td>
            @if($log->response_body)
                <button type="button" class="btn btn-tertiary btn-xs" data-json-url="{{ route('admin.api-analytics.show', $log->id) }}">View JSON</button>
            @else
                —
            @endif
        </td>
    </tr>
@endforeach
@if($logs->isEmpty())
    <tr><td colspan="8">No API activity in this window.</td></tr>
@endif
