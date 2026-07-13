<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rise Gate OS</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #17202a;
            --muted: #5f6f7c;
            --line: #d8e0e6;
            --paper: #f7f9fb;
            --accent: #1f7a8c;
            --accent-strong: #0f4c5c;
            --warm: #f2b84b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, "Noto Sans JP", sans-serif;
            color: var(--ink);
            background: linear-gradient(180deg, #ffffff 0%, var(--paper) 100%);
        }

        main {
            width: min(1040px, calc(100% - 40px));
            margin: 0 auto;
            padding: 72px 0 56px;
        }

        .eyebrow {
            color: var(--accent-strong);
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0;
            font-size: clamp(42px, 8vw, 88px);
            line-height: 0.95;
            letter-spacing: 0;
        }

        .lead {
            max-width: 720px;
            margin: 28px 0 0;
            color: var(--muted);
            font-size: 19px;
            line-height: 1.9;
        }

        .statement {
            margin-top: 42px;
            padding-top: 30px;
            border-top: 1px solid var(--line);
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }

        .item {
            min-height: 132px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.82);
            border-radius: 8px;
            padding: 22px;
        }

        .item strong {
            display: block;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .item span {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .status {
            margin-top: 34px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--muted);
            font-size: 14px;
        }

        .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--warm);
        }

        @media (max-width: 760px) {
            main {
                width: min(100% - 28px, 1040px);
                padding-top: 48px;
            }

            .statement {
                grid-template-columns: 1fr;
            }

            .lead {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="eyebrow">Company Operating System</div>
        <h1>Rise Gate OS</h1>
        <p class="lead">
            改善を、文化に。Rise Gate OS は、案件を管理するだけのシステムではありません。
            Project を中心に、改善、文書、判断、進捗を蓄積し、知識として育てるための土台です。
        </p>

        <section class="statement" aria-label="Rise Gate OS principles">
            <div class="item">
                <strong>Project</strong>
                <span>社内メンバーとお客様が、改善プロジェクトを共有する場所。</span>
            </div>
            <div class="item">
                <strong>Improvement</strong>
                <span>現場で生まれた改善を、会社の資産として蓄積する。</span>
            </div>
            <div class="item">
                <strong>Documents</strong>
                <span>議事録、仕様、納品物を、AIが活用できる知識の器にする。</span>
            </div>
        </section>

        <div class="status">
            <span class="dot" aria-hidden="true"></span>
            Phase 1-1: Laravel foundation is running.
        </div>
    </main>
</body>
</html>
