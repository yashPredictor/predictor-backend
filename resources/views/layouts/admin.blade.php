<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($pageTitle ?? 'Cron Analytics') . ' | Predictor Admin' }}</title>
    <style>
        :root {
            color-scheme: dark;
            --bg-gradient: radial-gradient(circle at top left, rgba(99, 102, 241, 0.18), transparent 55%),
                radial-gradient(circle at bottom, rgba(16, 185, 129, 0.12), transparent 45%),
                #0f172a;
            --panel-bg: rgba(15, 23, 42, 0.8);
            --panel-border: rgba(148, 163, 184, 0.18);
            --panel-hover: rgba(129, 140, 248, 0.25);
            --text-primary: #e2e8f0;
            --text-muted: #94a3b8;
            --divider: rgba(148, 163, 184, 0.18);
            --success: #34d399;
            --warning: #fbbf24;
            --error: #f87171;
            --info: #60a5fa;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
            background: var(--bg-gradient);
            min-height: 100vh;
            color: var(--text-primary);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .admin-shell {
            display: flex;
            min-height: 100vh;
            backdrop-filter: blur(20px);
        }

        .sidebar {
            width: 260px;
            padding: 32px 26px;
            border-right: 1px solid var(--divider);
            background: rgba(8, 13, 24, 0.85);
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(129, 140, 248, 0.8), rgba(56, 189, 248, 0.8));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            letter-spacing: 0.08em;
            font-size: 0.8rem;
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .brand-text .title {
            font-weight: 600;
            font-size: 1rem;
        }

        .brand-text .subtitle {
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            color: var(--text-muted);
            transition: background 0.2s ease, color 0.2s ease;
        }

        .nav-link:hover,
        .nav-link:focus-visible {
            background: rgba(15, 118, 110, 0.2);
            color: var(--text-primary);
        }

        .nav-link.active {
            background: linear-gradient(135deg, rgba(129, 140, 248, 0.25), rgba(20, 184, 166, 0.25));
            color: var(--text-primary);
            border: 1px solid rgba(129, 140, 248, 0.35);
        }

        .nav-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: rgba(148, 163, 184, 0.6);
        }

        .nav-link.active .nav-dot {
            background: rgba(129, 140, 248, 0.9);
        }

        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .main-header {
            padding: 28px 36px 0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .main-header h1 {
            margin: 0;
            font-size: 2rem;
            letter-spacing: -0.03em;
        }

        .main-header p {
            margin: 12px 0 0;
            color: var(--text-muted);
            max-width: 720px;
        }

        .logout-form {
            margin: 0;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 14px;
            padding: 10px 18px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            cursor: pointer;
            border: 1px solid transparent;
            color: var(--text-primary);
            background: rgba(148, 163, 184, 0.16);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border 0.18s ease, background 0.18s ease;
            text-decoration: none;
        }

        .btn:hover,
        .btn:focus-visible {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.25);
        }

        .btn:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.35);
        }

        .btn:disabled,
        .btn[disabled] {
            opacity: 0.6;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, rgba(129, 140, 248, 0.85), rgba(14, 165, 233, 0.75));
            border-color: rgba(129, 140, 248, 0.55);
            color: #f8fafc;
        }

        .btn-primary:hover,
        .btn-primary:focus-visible {
            border-color: rgba(129, 140, 248, 0.75);
        }

        .btn-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.8), rgba(56, 189, 248, 0.6));
            border-color: rgba(16, 185, 129, 0.55);
            color: #f8fafc;
        }

        .btn-success:hover,
        .btn-success:focus-visible {
            border-color: rgba(16, 185, 129, 0.7);
        }

        .btn-danger {
            background: linear-gradient(135deg, rgba(248, 113, 113, 0.85), rgba(244, 114, 182, 0.65));
            border-color: rgba(248, 113, 113, 0.6);
            color: #fff5f7;
        }

        .btn-danger:hover,
        .btn-danger:focus-visible {
            border-color: rgba(248, 113, 113, 0.75);
        }

        .btn-secondary {
            background: rgba(15, 118, 110, 0.25);
            border-color: rgba(20, 184, 166, 0.35);
            color: var(--text-primary);
        }

        .btn-secondary:hover,
        .btn-secondary:focus-visible {
            border-color: rgba(20, 184, 166, 0.5);
        }

        .content {
            padding: 28px 36px 48px;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(280px, 1fr));
            gap: 24px;
        }

        .card {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 20px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 18px;
            transition: border 0.2s ease, transform 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            border-color: var(--panel-hover);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
        }

        .card-subtitle {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 600;
            letter-spacing: -0.04em;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .metrics-row {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        .chart-card {
            flex: 1 1 260px;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            padding: 16px;
            min-width: 240px;
        }

        .chart-card canvas {
            width: 100%;
            height: 180px;
        }

        .metric {
            display: flex;
            flex-direction: column;
            gap: 6px;
            width: 47%;
            justify-content: center;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .status-pill.success {
            background: rgba(52, 211, 153, 0.16);
            color: var(--success);
        }

        .status-pill.warning {
            background: rgba(251, 191, 36, 0.16);
            color: var(--warning);
        }

        .status-pill.error {
            background: rgba(248, 113, 113, 0.16);
            color: var(--error);
        }

        .status-pill.info {
            background: rgba(96, 165, 250, 0.16);
            color: var(--info);
        }

        .status-pill.muted {
            background: rgba(148, 163, 184, 0.12);
            color: var(--text-muted);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        .table thead th {
            text-align: left;
            font-weight: 500;
            color: var(--text-muted);
            padding: 10px 12px;
            border-bottom: 1px solid var(--divider);
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-size: 0.72rem;
        }

        .table tbody td {
            padding: 12px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        }

        .table tbody tr:hover {
            background: rgba(30, 41, 59, 0.55);
        }

        .table .run-id-cell,
        .run-id-cell {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .copy-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 10px;
            border: 1px solid var(--panel-border);
            background: rgba(15, 23, 42, 0.9);
            color: var(--text-muted);
            font-size: 0.75rem;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, border 0.2s ease, transform 0.2s ease;
        }

        .copy-button:hover,
        .copy-button:focus-visible {
            color: var(--text-primary);
            border-color: rgba(129, 140, 248, 0.4);
            transform: translateY(-1px);
        }

        .copy-button.copied {
            background: rgba(34, 197, 94, 0.18);
            border-color: rgba(34, 197, 94, 0.45);
            color: var(--success);
        }

        .empty-state {
            padding: 32px;
            text-align: center;
            color: var(--text-muted);
            border: 1px dashed var(--divider);
            border-radius: 16px;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-subtitle {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 18px;
        }

        .toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .input-group {
            display: flex;
            align-items: center;
            background-color: rgba(15, 23, 42, 0.92);
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            padding: 0;
            overflow: hidden;
            padding-left: 10px;
        }

        .input-group select,
        .input-group input {
            flex: 1 1 auto;
            background-color: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 0.9rem;
            padding: 10px 12px;
            outline: none;
        }

        .input-group select {
            padding-right: 42px;
        }

        .form-control input,
        .form-control select,
        .form-control textarea,
        input[type="text"],
        input[type="number"],
        input[type="password"],
        input[type="time"],
        input[type="email"],
        select,
        textarea {
            width: 100%;
            background-color: rgba(15, 23, 42, 0.92);
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            padding: 10px 12px;
            color: var(--text-primary);
            font-size: 0.9rem;
            transition: border 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control input:focus,
        .form-control select:focus,
        .form-control textarea:focus,
        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="password"]:focus,
        input[type="time"]:focus,
        input[type="email"]:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(129, 140, 248, 0.55);
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25);
            outline: none;
        }

        .form-control input::placeholder,
        input::placeholder,
        textarea::placeholder {
            color: rgba(148, 163, 184, 0.6);
        }

        select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding-right: 42px;
            background-repeat: no-repeat;
            background-position: calc(100% - 18px) 50%;
            background-size: 12px 8px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%2394a3b8' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        select:focus {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23818cf8' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        .input-group input::placeholder {
            color: rgba(148, 163, 184, 0.6);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 10px;
            background: rgba(148, 163, 184, 0.12);
            color: var(--text-muted);
        }

        .issues-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .issue-item {
            background: rgba(15, 23, 42, 0.86);
            border: 1px solid rgba(248, 113, 113, 0.25);
            border-radius: 16px;
            padding: 16px;
        }

        .issue-item.warning {
            border-color: rgba(251, 191, 36, 0.25);
        }

        .issue-title {
            font-weight: 600;
            margin: 0 0 6px;
        }

        .issue-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            color: var(--text-muted);
            font-size: 0.78rem;
        }

        .status-bar {
            margin-top: 12px;
            height: 8px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.16);
            overflow: hidden;
            display: flex;
        }

        .status-segment {
            height: 100%;
        }

        .status-segment.success {
            background: rgba(34, 197, 94, 0.5);
        }

        .status-segment.warning {
            background: rgba(234, 179, 8, 0.5);
        }

        .status-segment.error {
            background: rgba(248, 113, 113, 0.6);
        }

        .status-segment.info {
            background: rgba(96, 165, 250, 0.5);
        }

        .stacked-section {
            display: grid;
            gap: 24px;
        }

        .two-column {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }

        .table-link {
            color: inherit;
            text-decoration: none;
            display: block;
        }

        .table-link:hover {
            color: var(--text-primary);
        }

        .pagination {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pagination a,
        .pagination span {
            padding: 6px 12px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            color: var(--text-muted);
            font-size: 0.8rem;
        }

        .pagination a:hover {
            border-color: rgba(129, 140, 248, 0.35);
            color: var(--text-primary);
        }

        .pagination .active {
            background: rgba(129, 140, 248, 0.28);
            border-color: rgba(129, 140, 248, 0.4);
            color: var(--text-primary);
        }

        pre.json {
            margin: 0;
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 12px;
            padding: 14px;
            font-size: 0.78rem;
            color: #cbd5f5;
            overflow-x: auto;
            max-width: 100%;
        }

        .timeline {
            position: relative;
            display: grid;
            gap: 16px;
        }

        .timeline::before {
            content: "";
            position: absolute;
            left: 12px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(148, 163, 184, 0.2);
        }

        .timeline-item {
            position: relative;
            padding-left: 42px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.12);
            border-radius: 16px;
            padding: 16px 18px 18px 46px;
        }

        .timeline-dot {
            position: absolute;
            left: 5px;
            top: 18px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(15, 23, 42, 0.9);
        }

        .timeline-dot.success {
            background: var(--success);
        }

        .timeline-dot.warning {
            background: var(--warning);
        }

        .timeline-dot.error {
            background: var(--error);
        }

        .timeline-dot.info {
            background: var(--info);
        }

        .timeline-dot.muted {
            background: rgba(148, 163, 184, 0.6);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .timestamp {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .message {
            margin: 0;
            font-size: 0.9rem;
        }

        .toast-notice {
            position: fixed;
            top: 28px;
            right: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(15, 23, 42, 0.92);
            color: var(--text-primary);
            box-shadow: 0 24px 52px rgba(15, 23, 42, 0.45);
            opacity: 0;
            transform: translateY(-20px);
            transition: opacity 0.25s ease, transform 0.25s ease;
            z-index: 2000;
        }

        .toast-notice.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .toast-notice.warning {
            border-color: rgba(251, 191, 36, 0.35);
        }

        .toast-notice.success {
            border-color: rgba(52, 211, 153, 0.35);
        }

        .toast-notice.error {
            border-color: rgba(248, 113, 113, 0.35);
        }

        .toast-notice .emoji {
            font-size: 1.2rem;
            line-height: 1;
        }

        @media (max-width: 1080px) {
            .sidebar {
                display: none;
            }

            .main-header,
            .content {
                padding-left: 22px;
                padding-right: 22px;
            }

            .two-column {
                grid-template-columns: 1fr;
            }
        }
    </style>
    @stack('head')
</head>

<body>
    <div class="admin-shell">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-mark">PB</div>
                <div class="brand-text">
                    <span class="title">Predictor Ops</span>
                    <span class="subtitle">Cron analytics</span>
                </div>
            </div>
            <nav class="nav">
                @php
                    $navItems = $adminNav ?? [];
                @endphp
                @foreach ($navItems as $item)
                    @php
                        $active = $item['active'] ?? null;

                        if (!is_bool($active)) {
                            $patterns = (array) ($item['pattern'] ?? []);
                            $active = collect($patterns)->contains(function ($pattern) {
                                return request()->is($pattern);
                            });
                        }

                        $href = $item['href'] ?? '#';
                    @endphp
                    <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $href }}">
                        <span class="nav-dot"></span>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>
        <main class="main">
            <header class="main-header">
                <div>
                    <h1>{{ $pageTitle ?? 'Cron Analytics' }}</h1>
                    @isset($pageIntro)
                        <p>{{ $pageIntro }}</p>
                    @endisset
                </div>
                @auth
                    <form method="POST" action="{{ route('admin.logout') }}" class="logout-form">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Log out</button>
                    </form>
                @endauth
            </header>
            <section class="content">
                @yield('content')
            </section>
        </main>
    </div>
    @if (session('toast'))
        <script>
            window.__adminToast = @json(session('toast'));
        </script>
    @endif
    @stack('scripts')
    <script>
        (function () {
            const toast = typeof window !== 'undefined' ? window.__adminToast : null;
            if (!toast) {
                return;
            }

            const el = document.createElement('div');
            el.className = 'toast-notice ' + (toast.type || 'info');
            el.innerHTML = `<span class="emoji">${toast.emoji || 'ℹ️'}</span><span>${toast.message || ''}</span>`;
            document.body.appendChild(el);

            requestAnimationFrame(() => {
                el.classList.add('visible');
            });

            const remove = () => {
                el.classList.remove('visible');
                setTimeout(() => el.remove(), 500);
            };

            setTimeout(remove, 3600);
            el.addEventListener('click', remove);
        })();
    </script>
    <script>
        (function () {
            const buttons = document.querySelectorAll('.copy-button[data-copy-text]');
            if (!buttons.length) {
                return;
            }

            const writeClipboard = async (text) => {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    return navigator.clipboard.writeText(text);
                }

                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'absolute';
                area.style.left = '-9999px';
                document.body.appendChild(area);
                area.select();
                try {
                    document.execCommand('copy');
                } finally {
                    document.body.removeChild(area);
                }
            };

            buttons.forEach((button) => {
                const defaultLabel = (button.textContent || 'Copy').trim();
                button.dataset.copyDefault = defaultLabel;
                button.addEventListener('click', async () => {
                    const text = button.getAttribute('data-copy-text');
                    if (!text) {
                        return;
                    }

                    try {
                        await writeClipboard(text);
                        button.classList.add('copied');
                        button.textContent = button.dataset.copiedLabel || 'Copied!';
                    } catch (error) {
                        button.classList.remove('copied');
                        button.textContent = button.dataset.copyErrorLabel || 'Copy failed';
                    }

                    window.setTimeout(() => {
                        button.classList.remove('copied');
                        button.textContent = button.dataset.copyDefault || defaultLabel;
                    }, 1800);
                });
            });
        })();
    </script>
</body>

</html>