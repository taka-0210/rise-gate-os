@extends('layouts.app', ['title' => 'RISE GATE OS | AI駆動のプロジェクトOS'])

@section('content')
    <section class="os-hero">
        <div class="os-hero__grid" aria-hidden="true"></div>
        <div class="os-hero__glow os-hero__glow--one" aria-hidden="true"></div>
        <div class="os-hero__glow os-hero__glow--two" aria-hidden="true"></div>

        <div class="os-hero__content">
            <div class="os-hero__eyebrow">
                <span class="os-hero__pulse"></span>
                AI-DRIVEN PROJECT OPERATING SYSTEM
            </div>
            <h1>会社の前進を、<br><span>AIと人で動かす。</span></h1>
            <p class="os-hero__lead">
                RISE GATE OSは、プロジェクト、ロードマップ、改善、タスク、社内知識をひとつにつなぐAI駆動の経営・業務OSです。
                AIが状況を読み取り、次の計画や改善を提案。人が判断・承認し、実行可能な仕事としてOSへ反映します。
            </p>

            <div class="actions os-hero__actions">
                @auth
                    <a class="button os-hero__primary" href="{{ route('dashboard') }}">
                        ダッシュボードへ
                        <span aria-hidden="true">→</span>
                    </a>
                    <a class="button os-hero__secondary" href="{{ route('projects.index') }}">プロジェクトを見る</a>
                @else
                    <a class="button os-hero__primary" href="{{ route('register') }}">
                        RISE GATE OSを始める
                        <span aria-hidden="true">→</span>
                    </a>
                    <a class="button os-hero__secondary" href="{{ route('login') }}">ログイン</a>
                @endauth
            </div>
        </div>

        <div class="os-cosmos" aria-label="AIを中心に、プロジェクトと業務情報がつながるイメージ" role="img">
            <div class="os-cosmos__stars os-cosmos__stars--near" aria-hidden="true"></div>
            <div class="os-cosmos__stars os-cosmos__stars--far" aria-hidden="true"></div>
            <div class="os-cosmos__halo" aria-hidden="true"></div>
            <div class="os-cosmos__orbit" aria-hidden="true"></div>

            <div class="os-cosmos__planet" aria-hidden="true">
                <div class="os-cosmos__planet-light"></div>
                <div class="os-cosmos__planet-copy">
                    <strong>RISE GATE</strong>
                    <span>AI OS</span>
                </div>
            </div>

            <div class="os-cosmos__projects" aria-hidden="true">
                <div class="os-cosmos__satellite os-cosmos__satellite--one"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--two"><span>KNOWLEDGE</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--three"><span>ROADMAP</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--four"><span>IMPROVE</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--five"><span>CLIENT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--six"><span>APPROVAL</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--seven"><span>TASK</span></div>
            </div>
        </div>
    </section>

    <section class="os-pillars" aria-label="RISE GATE OSのAI駆動サイクル">
        <article class="os-pillar">
            <span class="os-pillar__number">01</span>
            <div>
                <h2>AIが理解する</h2>
                <p>プロジェクトの目的、現状、履歴、社内知識をつなぎ、AIが仕事の背景まで把握します。</p>
            </div>
        </article>
        <article class="os-pillar">
            <span class="os-pillar__number">02</span>
            <div>
                <h2>AIが提案する</h2>
                <p>次に必要なロードマップ、改善、タスクを構造化し、根拠とともに実行案を提示します。</p>
            </div>
        </article>
        <article class="os-pillar">
            <span class="os-pillar__number">03</span>
            <div>
                <h2>人が決めて、OSが動かす</h2>
                <p>人が内容をレビューして承認。確定した計画をOSへ反映し、実行と改善を次の提案へつなげます。</p>
            </div>
        </article>
    </section>
@endsection
