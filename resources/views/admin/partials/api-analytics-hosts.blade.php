@forelse($hosts as $host)
    <tr>
        <td>{{ $host->host ?? '—' }}</td>
        <td>{{ number_format($host->total) }}</td>
        <td>{{ number_format($host->error_total) }}</td>
    </tr>
@empty
    <tr><td colspan="3">No data</td></tr>
@endforelse
