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

        .logout-button {
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(15, 118, 110, 0.25);
            color: var(--text-primary);
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 0.85rem;
            cursor: pointer;
        }

        .content {
            padding: 28px 36px 48px;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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

        .metric {
            display: flex;
            flex-direction: column;
            gap: 6px;
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
            background: rgba(15, 23, 42, 0.95);
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            padding: 2px 12px;
        }

        .input-group select,
        .input-group input {
            background: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 0.85rem;
            padding: 8px 6px;
            outline: none;
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

        .status-segment.success { background: rgba(34, 197, 94, 0.5); }
        .status-segment.warning { background: rgba(234, 179, 8, 0.5); }
        .status-segment.error { background: rgba(248, 113, 113, 0.6); }
        .status-segment.info { background: rgba(96, 165, 250, 0.5); }

        .pill {
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.18);
            font-size: 0.75rem;
            color: var(--text-muted);
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

        .timeline-dot.success { background: var(--success); }
        .timeline-dot.warning { background: var(--warning); }
        .timeline-dot.error { background: var(--error); }
        .timeline-dot.info { background: var(--info); }
        .timeline-dot.muted { background: rgba(148, 163, 184, 0.6); }

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
            @php($navItems = $adminNav ?? [])
            @foreach($navItems as $item)
                @php(
                    $active = collect($item['pattern'] ?? [])->contains(function ($pattern) {
                        return request()->is($pattern);
                    })
                )
                <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $item['href'] }}">
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
                    <button type="submit" class="logout-button">Log out</button>
                </form>
            @endauth
        </header>
        <section class="content">
            @yield('content')
        </section>
    </main>
</div>
@stack('scripts')
</body>
</html>
