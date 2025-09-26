<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login | Predictor</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
            background: radial-gradient(circle at top left, rgba(129, 140, 248, 0.3), transparent 55%),
                        radial-gradient(circle at bottom, rgba(20, 184, 166, 0.25), transparent 40%),
                        #0f172a;
            color: #e2e8f0;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: rgba(15, 23, 42, 0.88);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 22px;
            padding: 32px;
            box-shadow: 0 30px 80px rgba(15, 118, 110, 0.25);
        }

        h1 {
            margin: 0 0 24px;
            font-size: 1.75rem;
            letter-spacing: -0.02em;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.85rem;
            color: rgba(226, 232, 240, 0.8);
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(15, 23, 42, 0.7);
            color: inherit;
            font-size: 0.95rem;
        }

        input:focus {
            outline: none;
            border-color: rgba(129, 140, 248, 0.55);
            box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.2);
        }

        .actions {
            margin-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        button {
            flex: 1;
            padding: 12px 16px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(135deg, rgba(129, 140, 248, 0.8), rgba(56, 189, 248, 0.75));
            color: #0f172a;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
        }

        .error {
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.3);
            padding: 12px 14px;
            border-radius: 14px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            color: #fecaca;
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            color: rgba(226, 232, 240, 0.75);
        }

        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: rgba(129, 140, 248, 0.85);
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Predictor Admin</h1>

    @if ($errors->any())
        <div class="error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf
        <div style="margin-bottom: 18px;">
            <label for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email', 'yash@admin.com') }}" autocomplete="email" required>
        </div>
        <div style="margin-bottom: 18px;">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <div class="remember">
            <input id="remember" type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
            <label for="remember">Remember me</label>
        </div>
        <div class="actions">
            <button type="submit">Log in</button>
        </div>
    </form>
</div>
</body>
</html>
