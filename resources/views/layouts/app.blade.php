<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Rise Gate OS' }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('favicon.png') }}">
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
        .brand { display:inline-flex; align-items:center; flex:0 0 auto; }
        .brand-logo { display:block; width:194px; max-width:100%; height:auto; }
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
        .os-hero {
            position: relative;
            min-height: 560px;
            display: grid;
            grid-template-columns: minmax(0, 1.08fr) minmax(350px, .92fr);
            align-items: center;
            gap: 28px;
            overflow: hidden;
            padding: 64px 58px;
            border: 1px solid rgba(85, 156, 168, .24);
            border-radius: 24px;
            color: #f5fbfc;
            background:
                radial-gradient(circle at 78% 46%, rgba(24, 185, 194, .14), transparent 27%),
                linear-gradient(135deg, #071a29 0%, #0b2635 52%, #07313a 100%);
            box-shadow: 0 30px 80px rgba(12, 37, 52, .16);
        }
        .os-hero__grid {
            position: absolute;
            inset: 0;
            opacity: .16;
            background-image:
                linear-gradient(rgba(131, 218, 221, .18) 1px, transparent 1px),
                linear-gradient(90deg, rgba(131, 218, 221, .18) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(90deg, transparent, #000 50%, #000);
        }
        .os-hero__glow { position: absolute; border-radius: 50%; filter: blur(2px); }
        .os-hero__glow--one { width: 270px; height: 270px; right: -110px; top: -130px; background: rgba(57, 214, 210, .12); }
        .os-hero__glow--two { width: 180px; height: 180px; left: 32%; bottom: -130px; background: rgba(54, 154, 255, .11); }
        .os-hero__content { position: relative; z-index: 2; }
        .os-hero__eyebrow {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            color: #8ed7d8;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
        }
        .os-hero__pulse { width: 7px; height: 7px; border-radius: 50%; background: #57e1d7; box-shadow: 0 0 0 6px rgba(87, 225, 215, .1), 0 0 18px #57e1d7; }
        .os-hero h1 { font-size: clamp(42px, 5vw, 66px); line-height: 1.16; letter-spacing: -.045em; }
        .os-hero h1 span { color: #83d9d7; }
        .os-hero__lead { max-width: 620px; margin: 26px 0 0; color: #b8cbd2; font-size: 16px; line-height: 2; }
        .os-hero__actions { margin-top: 34px; }
        .os-hero__primary { gap: 28px; padding: 14px 19px; border: 1px solid #77d5d2; color: #08232d; background: #83d9d7; box-shadow: 0 10px 30px rgba(61, 200, 198, .18); }
        .os-hero__secondary { padding: 14px 19px; border: 1px solid rgba(169, 215, 219, .28); color: #d6e7ea; background: rgba(255, 255, 255, .04); }
        .os-network { position: relative; z-index: 1; width: 390px; height: 390px; justify-self: center; }
        .os-network__orbit { position: absolute; border: 1px solid rgba(120, 220, 217, .22); border-radius: 50%; }
        .os-network__orbit--outer { inset: 15px; }
        .os-network__orbit--inner { inset: 76px; border-style: dashed; animation: os-spin 36s linear infinite; }
        .os-network__lines { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
        .os-network__lines path { stroke: rgba(108, 220, 216, .27); stroke-width: 1; vector-effect: non-scaling-stroke; }
        .os-network__lines path:nth-child(2) { stroke: rgba(130, 230, 225, .4); stroke-dasharray: 3 6; }
        .os-network__lines path:nth-child(3) { stroke: rgba(91, 171, 190, .2); }
        .os-network__lines circle { stroke: rgba(105, 218, 214, .12); stroke-width: 1; stroke-dasharray: 2 9; }
        .os-network__core { position: absolute; inset: 130px; display: grid; place-content: center; text-align: center; border: 1px solid rgba(128, 232, 225, .55); border-radius: 50%; background: radial-gradient(circle, rgba(80, 215, 209, .27), rgba(17, 78, 89, .48) 58%, rgba(7, 31, 45, .88)); box-shadow: 0 0 55px rgba(71, 214, 208, .22), inset 0 0 30px rgba(88, 223, 218, .12); }
        .os-network__core span { font-size: 30px; font-weight: 300; letter-spacing: .16em; text-indent: .16em; color: #d9ffff; }
        .os-network__core small { margin-top: 4px; color: #78c9c9; font-size: 7px; letter-spacing: .22em; }
        .os-network__node { position: absolute; z-index: 2; padding: 7px 10px; border: 1px solid rgba(115, 215, 212, .28); border-radius: 4px; color: #9ed9d9; background: rgba(8, 37, 49, .86); font-size: 8px; font-weight: 800; letter-spacing: .14em; box-shadow: 0 5px 18px rgba(0, 0, 0, .18); }
        .os-network__node--project { top: 42px; left: 70px; }
        .os-network__node--improvement { top: 105px; right: 48px; }
        .os-network__node--workspace { top: 184px; left: -8px; }
        .os-network__node--task { right: 72px; top: 238px; color: #d5ffff; border-color: rgba(140, 239, 232, .5); }
        .os-network__node--knowledge { bottom: 42px; right: 24px; }
        .os-network__node--ai { bottom: 105px; left: 89px; color: #d5ffff; border-color: rgba(140, 239, 232, .5); }
        .os-network__node--roadmap { top: 101px; left: 142px; color: #d5ffff; border-color: rgba(140, 239, 232, .5); }
        .os-network__node--member { top: 82px; left: -12px; }
        .os-network__node--client { top: 157px; right: -14px; }
        .os-network__node--output { right: -2px; bottom: 10px; }
        .os-network__dot { position: absolute; z-index: 3; width: 8px; height: 8px; border: 2px solid #a8f5ef; border-radius: 50%; background: #0d5360; box-shadow: 0 0 15px #69ded8; }
        .os-network__dot--one { top: 64px; left: 210px; }
        .os-network__dot--two { right: 36px; top: 226px; }
        .os-network__dot--three { left: 105px; bottom: 36px; }
        .os-cosmos { position: relative; z-index: 1; width: 420px; height: 420px; justify-self: center; isolation: isolate; }
        .os-cosmos__stars { position: absolute; inset: 0; border-radius: 50%; }
        .os-cosmos__stars--far {
            opacity: .45;
            background-image:
                radial-gradient(circle at 12% 24%, #bafcff 0 1px, transparent 1.5px),
                radial-gradient(circle at 82% 18%, #fff 0 1px, transparent 1.5px),
                radial-gradient(circle at 72% 77%, #89d9e2 0 1px, transparent 1.5px),
                radial-gradient(circle at 26% 83%, #fff 0 1px, transparent 1.5px),
                radial-gradient(circle at 91% 53%, #fff 0 1px, transparent 1.5px);
        }
        .os-cosmos__stars--near {
            background-image:
                radial-gradient(circle at 8% 57%, #8ceae5 0 1.5px, transparent 2px),
                radial-gradient(circle at 36% 12%, #fff 0 1px, transparent 2px),
                radial-gradient(circle at 63% 7%, #8ceae5 0 1px, transparent 2px),
                radial-gradient(circle at 95% 34%, #fff 0 1.5px, transparent 2px),
                radial-gradient(circle at 58% 94%, #fff 0 1px, transparent 2px);
            filter: drop-shadow(0 0 5px rgba(131, 231, 228, .8));
        }
        .os-cosmos__halo { position: absolute; inset: 72px; border-radius: 50%; background: rgba(56, 211, 207, .08); filter: blur(35px); box-shadow: 0 0 75px rgba(63, 218, 211, .14); }
        .os-cosmos__orbit { position: absolute; inset: 45px 18px; border: 1px solid rgba(130, 224, 220, .3); border-radius: 50%; transform: rotate(-17deg); box-shadow: 0 0 18px rgba(99, 215, 211, .05), inset 0 0 18px rgba(99, 215, 211, .04); }
        .os-cosmos__orbit::after { content: ""; position: absolute; inset: 34px; border: 1px dashed rgba(111, 201, 204, .12); border-radius: 50%; }
        .os-cosmos__planet { position: absolute; z-index: 3; width: 218px; height: 218px; left: 101px; top: 101px; overflow: hidden; display: grid; place-items: center; border: 1px solid rgba(154, 239, 232, .5); border-radius: 50%; background: radial-gradient(circle at 32% 28%, #397d86 0%, #174c5a 25%, #0b2c3b 57%, #061722 100%); box-shadow: -20px 25px 55px rgba(0, 0, 0, .42), 0 0 55px rgba(56, 213, 207, .2), inset -32px -26px 42px rgba(0, 0, 0, .36); }
        .os-cosmos__planet::before { content: ""; position: absolute; inset: -30%; opacity: .24; background: repeating-linear-gradient(112deg, transparent 0 23px, rgba(133, 238, 232, .22) 24px, transparent 25px 39px); }
        .os-cosmos__planet-light { position: absolute; width: 110px; height: 110px; left: 17px; top: 12px; border-radius: 50%; background: rgba(182, 255, 246, .14); filter: blur(18px); }
        .os-cosmos__planet-copy { position: relative; z-index: 2; display: grid; text-align: center; text-shadow: 0 2px 14px rgba(0, 0, 0, .5); }
        .os-cosmos__planet-copy strong { color: #efffff; font-size: 16px; letter-spacing: .19em; text-indent: .19em; }
        .os-cosmos__planet-copy span { margin-top: 3px; color: #8de2de; font-size: 34px; font-weight: 200; letter-spacing: .22em; text-indent: .22em; }
        .os-cosmos__projects { position: absolute; z-index: 4; inset: 0; }
        .os-cosmos__satellite { position: absolute; display: grid; place-items: center; width: 66px; height: 66px; border: 1px solid rgba(157, 235, 230, .42); border-radius: 50%; background: radial-gradient(circle at 34% 30%, #39717a, #123845 60%, #081d29); box-shadow: 0 10px 25px rgba(0, 0, 0, .4), 0 0 22px rgba(89, 218, 211, .13); }
        .os-cosmos__satellite span { color: #d9f7f5; font-size: 8px; font-weight: 800; letter-spacing: .13em; text-indent: .13em; }
        .os-cosmos__satellite--one { width: 72px; height: 72px; left: 38px; top: 42px; }
        .os-cosmos__satellite--two { width: 56px; height: 56px; right: 52px; top: 26px; opacity: .82; }
        .os-cosmos__satellite--three { width: 62px; height: 62px; right: 1px; top: 156px; }
        .os-cosmos__satellite--four { width: 76px; height: 76px; right: 42px; bottom: 24px; }
        .os-cosmos__satellite--five { width: 54px; height: 54px; left: 157px; bottom: 2px; opacity: .76; }
        .os-cosmos__satellite--six { width: 64px; height: 64px; left: 14px; bottom: 74px; }
        .os-cosmos__satellite--seven { width: 48px; height: 48px; left: 2px; top: 157px; opacity: .7; }
        .os-cosmos__satellite--two span, .os-cosmos__satellite--five span, .os-cosmos__satellite--seven span { font-size: 6px; }
        .os-pillars { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 18px; }
        .os-pillar { display: flex; gap: 18px; min-height: 126px; padding: 24px; border: 1px solid var(--line); border-radius: 14px; background: rgba(255, 255, 255, .86); }
        .os-pillar__number { color: #70aeb2; font-size: 11px; font-weight: 800; letter-spacing: .12em; }
        .os-pillar h2 { margin-bottom: 7px; font-size: 17px; }
        .os-pillar p { margin: 0; font-size: 13px; line-height: 1.7; }
        @keyframes os-spin { to { transform: rotate(360deg); } }
        @keyframes os-orbit { to { transform: rotate(343deg); } }
        @keyframes os-counter-orbit { to { transform: rotate(-343deg); } }
        @keyframes os-counter-ai { to { transform: translateX(-50%) rotate(-343deg); } }
        @keyframes os-planet-turn { to { transform: translateX(28%); } }
        @keyframes os-stars-drift { to { transform: translate3d(5px, -7px, 0) scale(1.02); opacity: .75; } }
        @media (prefers-reduced-motion: reduce) {
            .os-network__orbit--inner, .os-cosmos__satellite-track, .os-cosmos__satellite, .os-cosmos__ai-track, .os-cosmos__ai-signal, .os-cosmos__planet::before, .os-cosmos__stars { animation: none; }
        }
        @media (max-width: 760px) {
            .topbar { align-items: flex-start; flex-direction: column; }
            .brand-logo { width:174px; }
            .main { width: min(100% - 28px, 1040px); padding-top: 28px; }
            .grid { grid-template-columns: 1fr; }
            .pagination { align-items: flex-start; flex-direction: column; }
            .os-hero { min-height: auto; grid-template-columns: 1fr; padding: 42px 28px; border-radius: 18px; }
            .os-hero h1 { font-size: 40px; }
            .os-network, .os-cosmos { width: 300px; height: 300px; margin: -8px auto -28px; transform: scale(.72); transform-origin: top center; }
            .os-pillars { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a class="brand" href="{{ route('welcome') }}" aria-label="RISE GATE OS トップへ">
            <img class="brand-logo" src="{{ asset('images/rise-gate-os-logo.png') }}" alt="RISE GATE OS">
        </a>
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
                    @isset($currentCompany)
                        <a class="workspace-pill" href="{{ route('company.home') }}">{{ $currentCompany->name }}</a>
                        @if (($availableCompanyCount ?? 0) > 1)
                            <a href="{{ route('companies.index') }}">会社切替</a>
                        @endif
                    @endisset
                    @isset($currentWorkspace)
                        <span class="workspace-pill">› {{ $currentWorkspace->name }} / {{ $currentWorkspaceRole }}</span>
                    @endisset
                    @isset($currentCompany)
                        @if ($canViewCompanyFinance ?? false)
                            <a href="{{ route('company-finance.index') }}">経営数値</a>
                        @endif
                        @if ($canViewCompanyDebt ?? false)
                            <a href="{{ route('company-loans.index') }}">借入管理</a>
                        @endif
                        @if ($canManageCompanyMembers ?? false)
                            <a href="{{ route('company-members.index') }}">会社設定</a>
                        @endif
                        <a href="{{ route('workspaces.index') }}">Workspaces</a>
                    @endisset
                    @isset($currentWorkspace)
                        <a href="{{ route('clients.index') }}">Clients</a>
                        <a href="{{ route('projects.index') }}">Projects</a>
                        <a href="{{ route('development-guide') }}">開発の進め方</a>
                        <a href="{{ route('documents.index') }}">帳票管理</a>
                        <a href="{{ route('ai-connections.index') }}">AI接続</a>
                        <a href="{{ route('ai-settings.edit') }}">AI設定</a>
                        <a href="{{ route('workspace-business-profile.edit') }}">事業者情報</a>
                    @endisset
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

