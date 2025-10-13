@forelse($tags as $tag)
    <tr>
        <td>{{ $tag->tag ?? '—' }}</td>
        <td>{{ number_format($tag->total) }}</td>
    </tr>
@empty
    <tr><td colspan="2">No data</td></tr>
@endforelse
