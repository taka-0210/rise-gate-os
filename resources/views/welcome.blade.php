@extends('layouts.app', ['title' => 'Rise Gate OS'])

@section('content')
    <section class="os-hero">
        <div class="os-hero__grid" aria-hidden="true"></div>
        <div class="os-hero__glow os-hero__glow--one" aria-hidden="true"></div>
        <div class="os-hero__glow os-hero__glow--two" aria-hidden="true"></div>

        <div class="os-hero__content">
            <div class="os-hero__eyebrow">
                <span class="os-hero__pulse"></span>
                Project Operating System
            </div>
            <h1>改善と知識が、<br><span>次の行動をつくる。</span></h1>
            <p class="os-hero__lead">
                Rise Gate OSは、Projectを中心に、現場の改善・計画・進捗をつなぎ、
                AIと人の力で次の行動へ変えるProject Operating Systemです。
            </p>

            <div class="actions os-hero__actions">
                @auth
                    <a class="button os-hero__primary" href="{{ route('dashboard') }}">
                        Dashboardへ
                        <span aria-hidden="true">→</span>
                    </a>
                    <a class="button os-hero__secondary" href="{{ route('projects.index') }}">Projectsを見る</a>
                @else
                    <a class="button os-hero__primary" href="{{ route('register') }}">
                        Rise Gate OSを始める
                        <span aria-hidden="true">→</span>
                    </a>
                    <a class="button os-hero__secondary" href="{{ route('login') }}">Login</a>
                @endauth
            </div>
        </div>

        <div class="os-cosmos" aria-hidden="true">
            <div class="os-cosmos__stars os-cosmos__stars--near"></div>
            <div class="os-cosmos__stars os-cosmos__stars--far"></div>
            <div class="os-cosmos__halo"></div>
            <div class="os-cosmos__orbit"></div>

            <div class="os-cosmos__planet">
                <div class="os-cosmos__planet-light"></div>
                <div class="os-cosmos__planet-copy">
                    <strong>RISE GATE</strong>
                    <span>OS</span>
                </div>
            </div>

            <div class="os-cosmos__projects">
                <div class="os-cosmos__satellite os-cosmos__satellite--one"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--two"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--three"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--four"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--five"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--six"><span>PROJECT</span></div>
                <div class="os-cosmos__satellite os-cosmos__satellite--seven"><span>PROJECT</span></div>
            </div>
        </div>
    </section>

    <section class="os-pillars" aria-label="Rise Gate OSの主要機能">
        <article class="os-pillar">
            <span class="os-pillar__number">01</span>
            <div>
                <h2>Project</h2>
                <p>人と目的をつなぎ、改善を前へ進める。</p>
            </div>
        </article>
        <article class="os-pillar">
            <span class="os-pillar__number">02</span>
            <div>
                <h2>Improvement</h2>
                <p>現場の気づきと実践を、会社の資産にする。</p>
            </div>
        </article>
        <article class="os-pillar">
            <span class="os-pillar__number">03</span>
            <div>
                <h2>AI & Knowledge</h2>
                <p>蓄積した知識から、次の行動を導き出す。</p>
            </div>
        </article>
    </section>
@endsection
