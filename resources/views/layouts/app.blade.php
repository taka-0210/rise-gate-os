<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Rise Gate OS' }}</title>
    <style>
        :root {
            --ink: #17202a;
            --muted: #60717e;
            --line: #d8e0e6;
            --paper: #f6f8fa;
            --accent: #1f7a8c;
            --accent-dark: #0f4c5c;
            --danger: #a33a3a;
        }
        html {
            overflow-y: scroll;
            scrollbar-gutter: stable;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans JP", sans-serif;
            color: var(--ink);
            background: var(--paper);
        }
        a { color: var(--accent-dark); text-decoration: none; }
        .shell { min-height: 100vh; display: grid; grid-template-rows: auto 1fr; }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 16px 28px;
            border-bottom: 1px solid var(--line);
            background: #fff;
        }
        .brand { font-weight: 800; color: var(--ink); }
        .workspace-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--muted);
            font-size: 13px;
        }
        .nav { display: flex; align-items: center; gap: 14px; color: var(--muted); font-size: 14px; }
        .main { width: min(1040px, calc(100% - 40px)); margin: 0 auto; padding: 42px 0; }
        .panel {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 28px;
        }
        .stack { display: grid; gap: 18px; }
        h1 { margin: 0; font-size: 34px; letter-spacing: 0; }
        h2 { margin: 0; font-size: 20px; }
        p { color: var(--muted); line-height: 1.8; }
        label { display: block; font-weight: 700; margin-bottom: 7px; }
        input, select, textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 11px 12px;
            font: inherit;
            background: #fff;
        }
        .field { display: grid; gap: 6px; }
        .error { color: var(--danger); font-size: 13px; }
        .button, button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 6px;
            padding: 11px 15px;
            background: var(--accent-dark);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            font: inherit;
        }
        .button.secondary, button.secondary { background: #fff; color: var(--accent-dark); border: 1px solid var(--line); }
        .button.danger, button.danger { background: var(--danger); color: #fff; }
        .actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        .card { border: 1px solid var(--line); border-radius: 8px; padding: 18px; background: #fff; }
        .meta { color: var(--muted); font-size: 13px; }
        .badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            border-radius: 999px;
            padding: 5px 9px;
            background: #e8f4f2;
            color: var(--accent-dark);
            font-size: 12px;
            font-weight: 800;
        }
        .origin-panel { border-color: #a9d6cf; background: #f2fbf9; }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-top: 18px;
            color: var(--muted);
            font-size: 13px;
        }
        .pagination-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .pagination-link, .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            min-height: 34px;
            padding: 7px 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--accent-dark);
            font-weight: 700;
        }
        .pagination-link.active { background: var(--accent-dark); color: #fff; border-color: var(--accent-dark); }
        .pagination-link.disabled { color: #9aabb6; background: #eef2f5; cursor: default; }
        .pagination-summary { white-space: nowrap; }
        @media (max-width: 760px) {
            .topbar { align-items: flex-start; flex-direction: column; }
            .main { width: min(100% - 28px, 1040px); padding-top: 28px; }
            .grid { grid-template-columns: 1fr; }
            .pagination { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('welcome') }}">Rise Gate OS</a>
        <nav class="nav">
            @auth
                @if (session('access_mode') === 'system_admin')
                    <span class="workspace-pill">System Admin Mode</span>
                    <a href="{{ route('system-admin.members.index') }}">Members</a>
                    <a href="{{ route('system-admin.workspaces.index') }}">Workspaces</a>
                    <form method="POST" action="{{ route('system-admin.exit') }}">
                        @csrf
                        <button class="secondary" type="submit">Workspace画面へ</button>
                    </form>
                @else
                    @isset($currentWorkspace)
                        <span class="workspace-pill">{{ $currentWorkspace->name }} / {{ $currentWorkspaceRole }}</span>
                    @endisset
                    <a href="{{ route('clients.index') }}">Clients</a>
                    <a href="{{ route('projects.index') }}">Projects</a>
                    <a href="{{ route('workspaces.index') }}">Workspaces</a>
                    @if (auth()->user()->is_system_admin)
                        <a href="{{ route('system-admin.login') }}">System Admin Login</a>
                    @endif
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="secondary" type="submit">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}">Login</a>
                <a href="{{ route('system-admin.login') }}">System Admin</a>
                <a class="button" href="{{ route('register') }}">Start</a>
            @endauth
        </nav>
    </header>
    <main class="main">
        @yield('content')
    </main>
</div>
</body>
</html>

