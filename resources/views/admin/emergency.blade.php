@extends('layouts.admin')

@section('content')
@php
    $toggles = $toggles ?? [];
@endphp

<div class="stacked-section" style="gap: 24px;">
    <section class="card" style="gap: 16px;">
        <div class="section-title">
            <span>Emergency cron controls</span>
            <span class="badge">{{ count($jobs) }} jobs</span>
        </div>
        <p class="section-subtitle" style="margin: 0;">
            Toggle a job to temporarily pause or resume its scheduler execution. These switches do not cancel already running jobs.
        </p>
        <div class="card" style="padding: 0; overflow: hidden;">
            <table class="table">
                <thead>
                    <tr>
                        <th>Job</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($jobs as $key => $meta)
                        @php
                            $enabled = !array_key_exists($key, $toggles) || $toggles[$key];
                            $statusPill = $enabled ? 'status-pill success' : 'status-pill warning';
                            $statusLabel = $enabled ? 'ENABLED' : 'PAUSED';
                        @endphp
                        <tr>
                            <td style="font-weight: 600;">{{ $meta['label'] }}</td>
                            <td style="max-width: 520px;">{{ $meta['description'] }}</td>
                            <td><span class="{{ $statusPill }}">{{ $statusLabel }}</span></td>
                            <td>
                                <form method="POST" action="{{ route('admin.emergency.toggle') }}" style="display: inline;">
                                    @csrf
                                    <input type="hidden" name="job" value="{{ $key }}">
                                    <input type="hidden" name="action" value="{{ $enabled ? 'disable' : 'enable' }}">
                                    <button type="submit" class="btn {{ $enabled ? 'btn-danger' : 'btn-success' }}" style="width: 120px;">
                                        {{ $enabled ? 'Pause' : 'Resume' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
