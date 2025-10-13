@extends('layouts.admin')

@section('content')
@php
    $statusClassMap = [
        true => 'status-pill error',
        false => 'status-pill success',
    ];
@endphp

<div class="stacked-section" style="gap: 28px;">
    <form method="get" id="api-analytics-filter" class="toolbar" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
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
            <div class="stat-value" data-total-calls>{{ number_format($totalCalls) }}</div>
        </article>
        <article class="card stat-card">
            <div class="stat-label">Success</div>
            <div class="stat-value" data-success-calls>{{ number_format($successCalls) }}</div>
        </article>
        <article class="card stat-card">
            <div class="stat-label">Errors</div>
            <div class="stat-value" data-error-calls>{{ number_format($errorCalls) }}</div>
        </article>
        <article class="card stat-card">
            <div class="stat-label">Avg Duration</div>
            <div class="stat-value" data-average-duration>{{ $averageDuration !== null ? $averageDuration . ' ms' : '—' }}</div>
        </article>
    </section>

    <div class="cards-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px;">
        <section class="card">
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
                    <tbody data-top-hosts>
                        @include('admin.partials.api-analytics-hosts', ['hosts' => $topHosts])
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
                    <tbody data-top-tags>
                        @include('admin.partials.api-analytics-tags', ['tags' => $topTags])
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="card" style="display: grid; gap: 16px;">
        <div class="section-title">
            <span>Recent API Calls</span>
            <span class="badge" data-total-badge>{{ number_format($logs->total()) }} total</span>
        </div>
        <div class="table-responsive" id="api-logs-table">
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
                        <th>Response</th>
                    </tr>
                </thead>
                <tbody data-api-logs>
                    @include('admin.partials.api-analytics-table', ['logs' => $logs, 'statusClassMap' => $statusClassMap])
                </tbody>
            </table>
        </div>
        <div id="api-logs-pagination">
            @include('admin.partials.pagination', ['paginator' => $logs])
        </div>
    </section>
</div>

<style>
.api-pagination {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}
.api-pagination__link {
    padding: 4px 8px;
    border-radius: 4px;
    background: rgba(148, 163, 184, 0.15);
    color: inherit;
    text-decoration: none;
    font-size: 0.85rem;
}
.api-pagination__link.active {
    background: rgba(59, 130, 246, 0.4);
    font-weight: 600;
}
.api-pagination__link.disabled {
    opacity: 0.4;
    pointer-events: none;
}
.api-pagination__ellipsis {
    opacity: 0.6;
}

#api-json-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(15, 23, 42, 0.85);
    z-index: 999;
    height: 100vh
}

#api-json-modal.open {
    display: flex;
}
.api-json-dialog {
    width: 100%;
    max-height: 100vh;
    background: rgba(15, 23, 42, 0.96);
    border-radius: 12px;
    padding: 35px 20px;
    display: grid;
    gap: 12px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
    border: 1px solid rgba(148, 163, 184, 0.2);
    height: 100%;
}
.api-json-toolbar {
    display: flex;
    gap: 12px;
    align-items: center;
}
.api-json-toolbar input {
    flex: 1;
    background: rgba(148,163,184,0.12);
    border: none;
    border-radius: 6px;
    padding: 8px 12px;
    color: inherit;
}
.api-json-toolbar button {
    background: transparent;
    border: none;
    color: inherit;
    font-size: 1.4rem;
    line-height: 1;
    cursor: pointer;
}
.api-json-body {
    overflow: auto;
    background: rgba(15, 23, 42, 0.6);
    border-radius: 8px;
    padding: 16px;
    scrollbar-width: none;
}
.api-json-body pre {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
    font-family: 'Fira Code', 'Menlo', monospace;
    font-size: 0.85rem;
    color: #e2e8f0;
}
.api-json-body mark {
    background: rgba(59, 130, 246, 0.4);
    padding: 2px 0;
    border-radius: 2px;
}
</style>

<div id="api-json-modal" aria-hidden="true">
    <div class="api-json-dialog" role="dialog" aria-modal="true">
        <div class="api-json-toolbar">
            <input type="text" id="api-json-search" placeholder="Search in JSON...">
            <button type="button" id="api-json-close" aria-label="Close">&times;</button>
        </div>
        <div class="api-json-body">
            <pre id="api-json-viewer">No response captured.</pre>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('api-analytics-filter');
    const tableBody = document.querySelector('[data-api-logs]');
    const paginationContainer = document.getElementById('api-logs-pagination');
    const totals = {
        total: document.querySelector('[data-total-calls]'),
        success: document.querySelector('[data-success-calls]'),
        error: document.querySelector('[data-error-calls]'),
        average: document.querySelector('[data-average-duration]'),
    };
    const totalBadge = document.querySelector('[data-total-badge]');
    const hostsContainer = document.querySelector('[data-top-hosts]');
    const tagsContainer = document.querySelector('[data-top-tags]');

    const modal = document.getElementById('api-json-modal');
    const modalClose = document.getElementById('api-json-close');
    const modalSearch = document.getElementById('api-json-search');
    const modalViewer = document.getElementById('api-json-viewer');
    let modalRawJson = '';
    let modalIsJson = false;

    function buildUrl(page = null) {
        const params = new URLSearchParams(new FormData(form));
        if (page) {
            if (page === '1') {
                params.delete('page');
            } else {
                params.set('page', page);
            }
        } else {
            params.delete('page');
        }
        const query = params.toString();
        return `${window.location.pathname}${query ? `?${query}` : ''}`;
    }

    function fetchPage(url) {
        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then(response => response.json())
            .then(data => {
                tableBody.innerHTML = data.html;
                paginationContainer.innerHTML = data.pagination;
                totals.total.textContent = data.totals.totalCalls;
                totals.success.textContent = data.totals.successCalls;
                totals.error.textContent = data.totals.errorCalls;
                totals.average.textContent = data.totals.averageDuration;
                if (totalBadge && data.totals.totalCalls !== undefined) {
                    totalBadge.textContent = `${data.totals.totalCalls} total`;
                }
                if (hostsContainer && data.hostsHtml) {
                    hostsContainer.innerHTML = data.hostsHtml;
                }
                if (tagsContainer && data.tagsHtml) {
                    tagsContainer.innerHTML = data.tagsHtml;
                }
            })
            .catch(error => console.error('Failed to load API logs:', error));
    }

    form.addEventListener('submit', event => {
        event.preventDefault();
        const url = buildUrl();
        window.history.pushState({}, '', url);
        fetchPage(url);
    });

    paginationContainer.addEventListener('click', event => {
        const link = event.target.closest('a[data-page]');
        if (!link) {
            return;
        }
        event.preventDefault();
        const page = link.dataset.page;
        const url = buildUrl(page);
        window.history.pushState({}, '', url);
        fetchPage(url);
    });

    window.addEventListener('popstate', () => {
        fetchPage(window.location.href);
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-json-url]');
        if (!button) {
            return;
        }

        const url = button.dataset.jsonUrl;
        fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then(response => response.json())
            .then(data => {
                modalRawJson = data.body || 'No response captured.';
                modalIsJson = !!data.is_json;
                renderModal();
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                modalSearch.value = '';
                modalSearch.focus();
                document.body.style.overflow = 'hidden';
            })
            .catch(error => console.error('Failed to load response body:', error));
    });

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', event => {
        if (event.target === modal) {
            closeModal();
        }
    });

    modalSearch.addEventListener('input', () => {
        renderModal(modalSearch.value.trim());
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal.classList.contains('open')) {
            closeModal();
        }
    });

    function closeModal() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        modalRawJson = '';
        modalIsJson = false;
        modalViewer.textContent = '';
        document.body.style.overflow = '';
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderModal(query = '') {
        if (!modalRawJson) {
            modalViewer.textContent = 'No response captured.';
            return;
        }

        let source = modalRawJson;
        if (modalIsJson) {
            try {
                const parsed = JSON.parse(modalRawJson);
                source = JSON.stringify(parsed, null, 2);
            } catch (e) {
                source = modalRawJson;
            }
        }

        const safe = escapeHtml(source);

        if (!query) {
            modalViewer.innerHTML = safe;
            modalViewer.scrollTop = 0;
            return;
        }

        const escapedQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escapedQuery})`, 'gi');
        modalViewer.innerHTML = safe.replace(regex, '<mark>$1</mark>');

        const firstHit = modalViewer.querySelector('mark');
        if (firstHit) {
            firstHit.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }
});
</script>
@endpush
@endsection
