@props(['wide' => false])

<section {{ $attributes->class(['company-context-read', 'company-context-read--wide' => $wide]) }}>
    {{ $slot }}
</section>

@once
<style>
.company-context-read {
    --context-ink: #17202a;
    --context-deep: #0b2635;
    --context-teal: #0f5565;
    --context-progress: #3f956f;
    --context-aqua: #a9d6d8;
    --context-warm: #f5f1e9;
    --context-paper: #f6f8fa;
    --context-line: rgba(96, 113, 126, .22);
    width: min(100%, 900px);
    min-width: 0;
    margin: 0 auto;
    display: grid;
    gap: 34px;
    color: var(--context-ink);
}
.company-context-read--wide { width: min(100%, 980px); }
.company-context-read p { white-space: pre-wrap; overflow-wrap: anywhere; }
.company-context-read h1,
.company-context-read h2,
.company-context-read h3 { overflow-wrap: anywhere; }
.company-context-read a:focus-visible,
.company-context-read button:focus-visible,
.company-context-read summary:focus-visible {
    outline: 3px solid rgba(70, 205, 195, .58);
    outline-offset: 4px;
}
.company-context-visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.company-context-title {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 28px;
    align-items: end;
    padding: 14px 0 32px;
    border-bottom: 1px solid #cfd9df;
}
.company-context-title__company { margin: 0 0 12px; color: #60717e; font-size: 13px; font-weight: 700; letter-spacing: .08em; }
.company-context-title__kicker { margin: 0 0 10px; color: #1f7a8c; font-size: 11px; font-weight: 800; letter-spacing: .19em; }
.company-context-title h1 { max-width: 780px; font-size: clamp(36px, 6vw, 64px); font-weight: 650; line-height: 1.13; letter-spacing: -.04em; }
.company-context-title__status { display: inline-flex; margin-top: 18px; padding: 6px 10px; border: 1px solid #bfd4d7; border-radius: 999px; color: #0f4c5c; background: #f3fbfa; font-size: 12px; font-weight: 800; }
.company-context-statement { max-width: 760px; margin: 0; padding: 0; border: 0; background: transparent; }
.company-context-statement p { margin: 0; color: #344753; font-size: clamp(17px, 2.4vw, 22px); line-height: 1.95; }
.company-context-section { display: grid; gap: 20px; padding: 31px 0; border-top: 1px solid #d8e0e6; }
.company-context-section__heading { display: grid; gap: 6px; }
.company-context-section__eyebrow { color: #1f7a8c; font-size: 11px; font-weight: 800; letter-spacing: .17em; }
.company-context-section h2 { font-size: clamp(23px, 3.5vw, 32px); font-weight: 650; letter-spacing: -.025em; }
.company-context-section__body { display: grid; gap: 18px; }
.company-context-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.company-context-actions a { font-weight: 700; }
.company-context-actions a:not(.button) { padding: 8px 0; border-bottom: 1px solid currentColor; }
.company-context-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
.company-context-tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 12px; border: 1px solid #d8e0e6; border-radius: 999px; background: #fff; color: #60717e; font-weight: 700; }
.company-context-tab span { display: inline-grid; min-width: 22px; height: 22px; place-items: center; padding: 0 6px; border-radius: 999px; background: #eef2f5; color: #17202a; font-size: 12px; }
.company-context-tab.is-active { border-color: #1f7a8c; color: #0f4c5c; }
.company-context-list { display: grid; gap: 14px; }
.company-context-card { display: grid; gap: 12px; padding: 25px 26px; border: 1px solid #d8e0e6; background: #fff; color: inherit; transition: border-color .15s ease, transform .15s ease; }
.company-context-card:hover { border-color: #7eb2b9; transform: translateY(-1px); }
.company-context-card__meta { display: flex; flex-wrap: wrap; gap: 10px; color: #60717e; font-size: 12px; }
.company-context-card h2 { font-size: 24px; }
.company-context-card p { margin: 0; color: #526673; line-height: 1.75; }
.company-context-empty { display: grid; gap: 12px; padding: 38px; border: 1px solid #d8e0e6; background: #fff; }
.company-context-empty p { margin: 0; }
.company-context-archive-notice { width: min(900px, calc(100% - 32px)); margin: 0 auto; padding: 14px 18px; border: 1px solid rgba(255, 178, 105, .55); background: #fff7ed; color: #75451d; }
/* Immersive Company Context story */
.company-context-story {
    width: min(1240px, calc(100vw - 32px));
    max-width: none;
    position: relative;
    left: 50%;
    transform: translateX(-50%);
    gap: 0;
    padding-bottom: 28px;
}
.company-context-hero {
    position: relative;
    isolation: isolate;
    min-height: clamp(620px, 78vh, 850px);
    overflow: hidden;
    display: grid;
    grid-template-rows: auto 1fr auto;
    align-items: center;
    padding: clamp(28px, 5vw, 68px);
    border-radius: 30px;
    color: var(--context-ink);
    background: #eef2f5;
    box-shadow: 0 35px 80px rgba(15, 85, 101, .1);
}
.company-context-hero::after {
    content: "";
    position: absolute;
    z-index: -1;
    inset: 0;
    background: linear-gradient(90deg, rgba(238,242,245,.99) 0%, rgba(238,242,245,.96) 35%, rgba(238,242,245,.58) 53%, rgba(238,242,245,.08) 76%);
    pointer-events: none;
}
.company-context-hero__brand-visual {
    position: absolute;
    z-index: -2;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    -webkit-mask-image: linear-gradient(90deg, transparent 8%, #000 40%, #000);
    mask-image: linear-gradient(90deg, transparent 8%, #000 40%, #000);
}
.company-context-hero__brand-visual img { width: 100%; height: 100%; display: block; object-fit: cover; object-position: center; }
.company-context-hero__atmosphere { display: none; }
.company-context-hero__atmosphere span { position: absolute; border: 1px solid rgba(149, 237, 225, .18); border-radius: 50%; }
.company-context-hero__atmosphere span:nth-child(1) { width: 660px; height: 660px; right: -250px; top: -290px; }
.company-context-hero__atmosphere span:nth-child(2) { width: 430px; height: 430px; right: -70px; top: -100px; border-style: dashed; animation: company-context-orbit 42s linear infinite; }
.company-context-hero__atmosphere span:nth-child(3) { width: 260px; height: 260px; left: -120px; bottom: -90px; box-shadow: 0 0 100px rgba(64, 213, 203, .1); }
.company-context-hero__topline { align-self: start; display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; }
.company-context-hero__company { margin: 0; color: #60717e; font-size: 12px; font-weight: 750; letter-spacing: .12em; }
.company-context-hero__content { width: min(650px, 61%); align-self: center; padding: clamp(48px, 8vh, 96px) 0; }
.company-context-hero__kicker { margin: 0 0 24px; color: var(--context-teal); font-size: 11px; font-weight: 800; letter-spacing: .22em; }
.company-context-hero h1 {
    max-width: 1030px;
    margin: 0;
    color: var(--context-ink);
    font-size: clamp(54px, 9.2vw, 118px);
    font-weight: 450;
    line-height: .98;
    letter-spacing: -.065em;
    text-wrap: balance;
}
.company-context-hero__lead { max-width: 660px; margin: clamp(32px, 5vw, 58px) 0 0; color: #405866; font-size: clamp(19px, 2.2vw, 28px); line-height: 1.85; }
.company-context-hero__status { display: inline-flex; margin-top: 22px; padding: 7px 12px; border: 1px solid rgba(255, 203, 154, .45); border-radius: 999px; color: #ffd4ad; font-size: 12px; font-weight: 800; }
.company-context-hero__position { position: absolute; right: clamp(28px, 5vw, 68px); bottom: clamp(28px, 5vw, 64px); width: min(340px, 38%); margin: 0; color: #526673; font-size: 13px; line-height: 1.75; }
.company-context-hero__position span { display: block; margin-bottom: 8px; color: var(--context-progress); font-size: 9px; font-weight: 850; letter-spacing: .2em; }
.company-context-hero__continue { align-self: end; width: fit-content; display: inline-flex; align-items: center; gap: 18px; color: var(--context-ink); font-size: 12px; font-weight: 750; letter-spacing: .08em; }
.company-context-hero__continue span:last-child { display: grid; width: 34px; height: 34px; place-items: center; border: 1px solid rgba(15, 85, 101, .35); border-radius: 50%; color: var(--context-teal); }
.company-context-tools { position: relative; z-index: 5; }
.company-context-tools summary {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    padding: 0 0 7px;
    border: 1px solid rgba(15, 85, 101, .22);
    border-radius: 50%;
    color: var(--context-ink);
    background: rgba(246,248,250,.72);
    cursor: pointer;
    font-size: 17px;
    letter-spacing: .12em;
    list-style: none;
}
.company-context-tools summary::-webkit-details-marker { display: none; }
.company-context-tools[open] summary { color: #082b35; background: #d7f4ef; }
.company-context-tools__menu {
    position: absolute;
    right: 0;
    top: calc(100% + 10px);
    width: 210px;
    display: grid;
    padding: 8px;
    border: 1px solid rgba(178, 216, 214, .35);
    border-radius: 12px;
    background: rgba(7, 31, 39, .96);
    box-shadow: 0 18px 50px rgba(0,0,0,.3);
    backdrop-filter: blur(16px);
}
.company-context-tools__menu a { padding: 11px 12px; border-radius: 7px; color: #dceff0; font-size: 13px; font-weight: 700; }
.company-context-tools__menu a:hover { background: rgba(139, 226, 216, .11); }
.company-context-story__body { display: grid; }
.company-context-story__eyebrow { margin: 0; color: var(--context-teal); font-size: 10px; font-weight: 850; letter-spacing: .22em; }
.company-context-key-message {
    min-height: clamp(480px, 68vh, 720px);
    display: grid;
    align-content: center;
    gap: 34px;
    width: min(940px, calc(100% - 44px));
    margin: 0 auto;
    padding: clamp(90px, 14vw, 180px) 0;
}
.company-context-key-message__text {
    max-width: 980px;
    margin: 0;
    color: var(--context-ink);
    font-size: clamp(36px, 6.2vw, 78px);
    font-weight: 450;
    line-height: 1.3;
    letter-spacing: -.045em;
    text-wrap: balance;
}
.company-context-story-section {
    width: min(1040px, calc(100% - 44px));
    margin: 0 auto;
    padding: clamp(90px, 12vw, 160px) 0;
}
.company-context-story-heading { display: grid; gap: 14px; max-width: 680px; margin-bottom: clamp(45px, 7vw, 80px); }
.company-context-story-heading h2 { margin: 0; font-size: clamp(38px, 6vw, 70px); font-weight: 450; line-height: 1.08; letter-spacing: -.05em; }
.company-context-story-heading > p:last-child:not(.company-context-story__eyebrow) { margin: 5px 0 0; color: #617276; font-size: 16px; }
/* Company OS official ring language, translated into a scroll-linked five-axis map */
.company-context-perspectives {
    position: relative;
    display: grid;
    grid-template-columns: minmax(320px, .86fr) minmax(0, 1.14fr);
    gap: clamp(42px, 7vw, 92px);
    align-items: start;
}
.company-context-perspectives__visual-column {
    position: sticky;
    top: 24px;
    display: grid;
    gap: 18px;
}
.company-context-orbit-visual {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    aspect-ratio: 1;
    border: 1px solid rgba(96, 113, 126, .13);
    border-radius: 28px;
    background:
        radial-gradient(circle at 50% 50%, rgba(169, 214, 216, .62), rgba(169, 214, 216, .15) 28%, transparent 58%),
        #eef2f5;
    box-shadow: 0 26px 70px rgba(15, 85, 101, .08);
}
.company-context-orbit-visual::before {
    content: "";
    position: absolute;
    inset: 0;
    opacity: .55;
    background: radial-gradient(circle, rgba(245, 251, 252, .9), transparent 48%);
    transform: scale(.42);
}
.company-context-orbit-visual svg { position: relative; z-index: 1; width: 100%; height: 100%; display: block; overflow: visible; }
.company-context-orbit-visual__field { fill: rgba(246, 248, 250, .48); stroke: rgba(96, 113, 126, .14); stroke-width: 1; }
.company-context-orbit-visual__ring { fill: none; vector-effect: non-scaling-stroke; }
.company-context-orbit-visual__ring--outer { stroke: rgba(15, 85, 101, .25); stroke-width: 10; stroke-dasharray: 17 4 8 5 13 6 21 5; transform-origin: 280px 280px; animation: company-context-orbit-spin 120s linear infinite; }
.company-context-orbit-visual__ring--middle { stroke: rgba(111, 156, 165, .46); stroke-width: 7; stroke-dasharray: 7 5 18 4 10 7 23 6; transform-origin: 280px 280px; animation: company-context-orbit-spin-reverse 88s linear infinite; }
.company-context-orbit-visual__ring--inner { stroke: rgba(169, 214, 216, .72); stroke-width: 5; stroke-dasharray: 12 7 4 7; opacity: .9; }
.company-context-orbit-visual__focus {
    transform: rotate(-90deg);
    transform-origin: 280px 280px;
    transition: transform .75s cubic-bezier(.22,.75,.24,1);
}
.company-context-orbit-visual__focus circle { fill: none; stroke: var(--context-progress); stroke-width: 3.5; stroke-linecap: round; stroke-dasharray: 108 1224; filter: drop-shadow(0 0 5px rgba(63, 149, 111, .3)); }
.company-context-orbit-visual__line { stroke: rgba(15, 85, 101, .17); stroke-width: 1; vector-effect: non-scaling-stroke; transition: stroke .45s ease, stroke-width .45s ease; }
.company-context-orbit-visual__line.is-active { stroke: rgba(63, 149, 111, .78); stroke-width: 1.5; }
.company-context-orbit-visual__node-halo { fill: rgba(246, 248, 250, .9); stroke: rgba(15, 85, 101, .23); stroke-width: 1; transition: fill .45s ease, stroke .45s ease, r .45s ease; }
.company-context-orbit-visual__node-dot { fill: #6f9ca5; transition: fill .45s ease, r .45s ease; }
.company-context-orbit-visual__node text { fill: #60717e; font-size: 10px; font-weight: 800; letter-spacing: 1.6px; transition: fill .45s ease; }
.company-context-orbit-visual__node.is-active .company-context-orbit-visual__node-halo { r: 36px; fill: rgba(245, 251, 252, .96); stroke: rgba(63, 149, 111, .72); }
.company-context-orbit-visual__node.is-active .company-context-orbit-visual__node-dot { r: 7px; fill: var(--context-progress); filter: drop-shadow(0 0 8px rgba(63, 149, 111, .42)); }
.company-context-orbit-visual__node.is-active text { fill: var(--context-ink); }
.company-context-orbit-visual__core > circle:last-of-type { fill: none; stroke: rgba(15, 85, 101, .42); stroke-width: 1; }
.company-context-orbit-visual__core text { fill: #60717e; font-size: 11px; font-weight: 650; letter-spacing: 3px; }
.company-context-orbit-visual__core text:last-child { fill: var(--context-teal); font-size: 17px; font-weight: 400; letter-spacing: 4px; }
.company-context-orbit-visual__active {
    position: absolute;
    z-index: 2;
    left: 24px;
    right: 24px;
    bottom: 20px;
    display: grid;
    grid-template-columns: auto auto 1fr;
    align-items: baseline;
    gap: 10px;
    color: var(--context-ink);
}
.company-context-orbit-visual__active span { color: #6caaad; font-size: 9px; font-weight: 800; letter-spacing: .12em; }
.company-context-orbit-visual__active strong { color: var(--context-progress); font-size: 11px; letter-spacing: .16em; }
.company-context-orbit-visual__active small { overflow: hidden; color: #60717e; font-size: 10px; text-align: right; text-overflow: ellipsis; white-space: nowrap; }
.company-context-perspectives__progress {
    position: absolute;
    right: -28px;
    top: 0;
    width: 1px;
    height: 100%;
    background: rgba(96, 113, 126, .18);
}
.company-context-perspectives__progress span {
    display: block;
    width: 1px;
    height: 100%;
    background: var(--context-teal);
    transform: scaleY(.2);
    transform-origin: top;
    transition: transform .7s cubic-bezier(.2,.75,.25,1);
}
.company-context-perspectives__tabs { display: flex; flex-wrap: wrap; gap: 7px; }
.company-context-perspectives__tabs button {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding: 11px 16px;
    border: 1px solid rgba(10,39,48,.16);
    border-radius: 999px;
    color: #52696e;
    background: rgba(255,255,255,.65);
    box-shadow: none;
    font: inherit;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .08em;
}
.company-context-perspectives__tabs button span { color: #8a999c; font-size: 9px; }
.company-context-perspectives__tabs button[aria-current="true"] { border-color: #0d6c73; color: #fff; background: #0b5962; }
.company-context-perspectives__tabs button[aria-current="true"] span { color: #a7e3db; }
.company-context-perspectives__panels { display: grid; gap: 32px; }
.company-context-perspective {
    min-height: clamp(400px, 54vh, 540px);
    display: grid;
    grid-template-columns: 84px minmax(0, 1fr);
    align-items: center;
    gap: clamp(20px, 4vw, 50px);
    padding: clamp(30px, 5vw, 58px);
    border: 1px solid rgba(13, 76, 87, .12);
    border-left: 2px solid rgba(12, 89, 98, .14);
    border-radius: 20px;
    opacity: 1;
    background: linear-gradient(135deg, rgba(255,255,255,.9), rgba(239,246,243,.62));
    transform: none;
    transform-origin: left center;
    transition: opacity .55s ease, transform .55s ease, border-color .55s ease, box-shadow .55s ease;
}
.company-context-perspectives.is-enhanced .company-context-perspective {
    opacity: .48;
    transform: scale(.975);
}
.company-context-perspective.is-active {
    opacity: 1;
    border-color: rgba(15, 85, 101, .28);
    border-left-color: var(--context-progress);
    box-shadow: 0 24px 58px rgba(12, 53, 60, .09);
    transform: scale(1);
}
.company-context-perspective__number { color: rgba(11, 89, 98, .2); font-size: clamp(54px, 8vw, 96px); font-weight: 300; letter-spacing: -.08em; }
.company-context-perspective__copy { display: grid; gap: 12px; }
.company-context-perspective__code { margin: 0; color: var(--context-teal); font-size: 11px; font-weight: 850; letter-spacing: .2em; }
.company-context-perspective h3 { margin: 0; color: #617276; font-size: 15px; font-weight: 700; }
.company-context-perspective__copy > p:last-child { max-width: 760px; margin: 8px 0 0; color: var(--context-ink); font-size: clamp(21px, 3.2vw, 34px); font-weight: 520; line-height: 1.65; }
.company-context-strength {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    width: min(1160px, calc(100% - 32px));
    min-height: clamp(500px, 68vh, 720px);
    margin: 30px auto;
    display: grid;
    align-content: center;
    gap: 30px;
    padding: clamp(54px, 9vw, 118px);
    border-radius: 28px;
    color: #f2ffff;
    background: linear-gradient(145deg, #0c5961, #06343d 64%, #08262f);
}
.company-context-strength::after { content: "“"; position: absolute; z-index: -1; right: 5%; top: -22%; color: rgba(145,229,216,.1); font-family: Georgia, serif; font-size: min(56vw, 620px); line-height: 1; }
.company-context-strength .company-context-story__eyebrow { color: #9ee3d9; }
.company-context-strength h2 { max-width: 660px; margin: 0; color: #cce4e4; font-size: clamp(18px, 2.2vw, 26px); font-weight: 550; }
.company-context-strength__statement { max-width: 940px; margin: 0; color: #fff; font-size: clamp(32px, 5.4vw, 66px); font-weight: 580; line-height: 1.35; letter-spacing: -.04em; text-wrap: balance; }
.company-context-items { display: grid; }
.company-context-item {
    display: grid;
    grid-template-columns: 82px minmax(0, 1fr);
    gap: clamp(22px, 5vw, 74px);
    padding: clamp(40px, 6vw, 74px) 0;
    border-top: 1px solid var(--context-line);
    background: transparent;
}
.company-context-item:last-child { border-bottom: 1px solid var(--context-line); }
.company-context-item__index { color: #9aa7a8; font-size: 11px; font-weight: 800; letter-spacing: .12em; }
.company-context-item__content { display: grid; grid-template-columns: minmax(0, 1fr) minmax(220px, .55fr); column-gap: clamp(30px, 6vw, 90px); }
.company-context-item__theme { grid-column: 1; margin: 0 0 12px; color: var(--context-teal); font-size: 10px; font-weight: 850; letter-spacing: .18em; }
.company-context-item__kind { grid-column: 2; grid-row: 1; justify-self: end; margin: 0; color: #697d80; font-size: 12px; font-weight: 750; }
.company-context-item h3 { grid-column: 1; margin: 0; font-size: clamp(28px, 4vw, 48px); font-weight: 610; line-height: 1.15; letter-spacing: -.035em; }
.company-context-item__description { grid-column: 2; grid-row: 2; margin: 0; color: #52676b; font-size: 15px; line-height: 1.9; }
.company-context-attributes { grid-column: 1 / -1; display: grid; gap: 0; margin: 32px 0 0; padding: 0; }
.company-context-attributes > div { display: grid; grid-template-columns: minmax(170px, .42fr) minmax(0, 1fr); gap: 30px; padding: 18px 0; border-top: 1px solid rgba(10,39,48,.1); }
.company-context-attributes dt { color: #52676b; font-size: 12px; font-weight: 800; overflow-wrap: anywhere; }
.company-context-attributes dd { margin: 0; color: #233f46; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.75; }
.company-context-next {
    position: relative;
    overflow: hidden;
    width: min(1160px, calc(100% - 32px));
    min-height: clamp(520px, 72vh, 760px);
    margin: 30px auto 0;
    display: grid;
    align-content: center;
    gap: 22px;
    padding: clamp(54px, 9vw, 120px);
    border-radius: 28px;
    color: #f5ffff;
    background: radial-gradient(circle at 84% 20%, rgba(105, 230, 214, .18), transparent 30%), linear-gradient(145deg, #061c25, #0a3440);
}
.company-context-next::after { content: "→"; position: absolute; right: 5%; bottom: -20%; color: rgba(143, 226, 216, .09); font-size: min(48vw, 500px); font-weight: 200; line-height: 1; }
.company-context-next .company-context-story__eyebrow { color: #99e0d6; }
.company-context-next__label { position: relative; z-index: 1; margin: 12px 0 0; color: #fff; font-size: clamp(50px, 9vw, 112px); font-weight: 570; line-height: 1; letter-spacing: -.065em; }
.company-context-next__description { position: relative; z-index: 1; max-width: 700px; margin: 6px 0 0; color: #9fbfc3; font-size: 15px; }
.company-context-next__memo { position: relative; z-index: 1; max-width: 850px; margin: 34px 0 0; padding-top: 28px; border-top: 1px solid rgba(183, 227, 224, .22); color: #e2f0f0; font-size: clamp(19px, 2.6vw, 30px); line-height: 1.75; }
@keyframes company-context-orbit { to { transform: rotate(360deg); } }
@keyframes company-context-reveal {
    from { opacity: .24; transform: translateY(38px) scale(.988); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes company-context-orbit-spin {
    to { transform: rotate(360deg); }
}
@keyframes company-context-orbit-spin-reverse {
    to { transform: rotate(-360deg); }
}
@media (prefers-reduced-motion: no-preference) {
    html { scroll-behavior: smooth; }
    .company-context-reveal-active { animation: company-context-reveal .82s cubic-bezier(.22,.75,.24,1) both; }
}
@media (prefers-reduced-motion: reduce) {
    .company-context-read *,
    .company-context-read *::before,
    .company-context-read *::after { scroll-behavior: auto !important; animation: none !important; transition-duration: .001ms !important; }
}
@media (max-width: 760px) {
    .company-context-read { gap: 25px; }
    .company-context-title { grid-template-columns: 1fr; gap: 18px; padding-top: 2px; }
    .company-context-title h1 { font-size: 38px; }
    .company-context-empty { padding: 26px 22px; }
    .company-context-actions { align-items: stretch; }
    .company-context-actions .button { width: 100%; }
    .company-context-story { width: calc(100vw - 20px); }
    .company-context-hero {
        min-height: max(650px, calc(100svh - 120px));
        padding: 22px;
        border-radius: 21px;
        grid-template-rows: auto 1fr auto;
    }
    .company-context-hero::after { background: linear-gradient(to bottom, rgba(238,242,245,.12), rgba(238,242,245,.46) 36%, #eef2f5 62%); }
    .company-context-hero__brand-visual {
        -webkit-mask-image: linear-gradient(to bottom, #000 0%, rgba(0,0,0,.82) 47%, transparent 76%);
        mask-image: linear-gradient(to bottom, #000 0%, rgba(0,0,0,.82) 47%, transparent 76%);
    }
    .company-context-hero__brand-visual img { width: 165%; max-width: none; object-position: 62% center; transform: translateX(-18%); }
    .company-context-hero__content { width: 100%; align-self: end; padding: 270px 0 120px; }
    .company-context-hero h1 { font-size: clamp(48px, 15vw, 72px); line-height: 1.02; }
    .company-context-hero__lead { margin-top: 30px; font-size: 18px; line-height: 1.8; }
    .company-context-hero__position { left: 22px; right: 80px; bottom: 76px; width: auto; font-size: 11px; }
    .company-context-hero__continue { position: absolute; left: 22px; bottom: 23px; }
    .company-context-key-message { width: calc(100% - 32px); min-height: 560px; padding: 100px 0; }
    .company-context-key-message__text { font-size: clamp(34px, 10vw, 48px); line-height: 1.4; }
    .company-context-story-section { width: calc(100% - 32px); padding: 100px 0; }
    .company-context-story-heading { margin-bottom: 44px; }
    .company-context-story-heading h2 { font-size: 42px; }
    .company-context-perspectives { grid-template-columns: 1fr; gap: 30px; }
    .company-context-perspectives__visual-column { position: relative; top: auto; gap: 14px; }
    .company-context-orbit-visual { width: min(100%, 350px); margin-inline: auto; border-radius: 22px; }
    .company-context-orbit-visual__active { left: 18px; right: 18px; bottom: 16px; }
    .company-context-perspectives__tabs {
        width: calc(100vw - 38px);
        flex-wrap: nowrap;
        margin-right: -6px;
        padding-bottom: 7px;
        overflow-x: auto;
        scrollbar-width: thin;
    }
    .company-context-perspectives__tabs button { padding: 10px 13px; }
    .company-context-perspectives__panels { gap: 18px; }
    .company-context-perspectives__progress { display: none; }
    .company-context-perspective { min-height: 360px; grid-template-columns: 1fr; gap: 4px; padding: 28px 23px; border-radius: 18px; }
    .company-context-perspective__number { font-size: 48px; }
    .company-context-perspective__copy > p:last-child { font-size: 21px; line-height: 1.7; }
    .company-context-strength,
    .company-context-next { width: calc(100% - 12px); min-height: 620px; margin-block: 10px; padding: 38px 28px; border-radius: 22px; }
    .company-context-strength__statement { font-size: 34px; line-height: 1.45; }
    .company-context-item { grid-template-columns: 34px minmax(0, 1fr); gap: 14px; padding: 42px 0; }
    .company-context-item__content { grid-template-columns: 1fr; gap: 0; }
    .company-context-item__theme,
    .company-context-item__kind,
    .company-context-item h3,
    .company-context-item__description { grid-column: 1; grid-row: auto; justify-self: start; }
    .company-context-item__kind { margin: 0 0 12px; }
    .company-context-item h3 { margin-bottom: 22px; font-size: 31px; }
    .company-context-item__description { font-size: 14px; }
    .company-context-attributes { grid-column: 1; margin-top: 28px; }
    .company-context-attributes > div { grid-template-columns: 1fr; gap: 7px; }
    .company-context-next__label { font-size: clamp(52px, 16vw, 78px); }
    .company-context-next__memo { font-size: 20px; }
}
@media print {
    @page { size: A4; margin: 16mm; }
    .company-context-read-page { background: #fff; }
    .company-context-read-page .topbar,
    .company-context-read-page .breadcrumbs,
    .company-context-read-page .company-context-print-hidden { display: none !important; }
    .company-context-read-page .shell { display: block; min-height: 0; }
    .company-context-read-page .main { width: auto; margin: 0; padding: 0; }
    .company-context-read { width: 100%; left: auto; transform: none; gap: 20px; color: #000; }
    .company-context-title { padding: 0 0 18px; }
    .company-context-title h1 { font-size: 32px; }
    .company-context-statement p { font-size: 16px; }
    .company-context-hero {
        min-height: auto;
        display: block;
        padding: 0 0 24px;
        border-radius: 0;
        color: #000;
        background: #fff;
        box-shadow: none;
    }
    .company-context-hero::after,
    .company-context-hero__atmosphere,
    .company-context-hero__brand-visual { display: none; }
    .company-context-hero__company,
    .company-context-hero__kicker,
    .company-context-hero__lead,
    .company-context-hero__position,
    .company-context-hero h1 { color: #000; }
    .company-context-hero h1 { font-size: 38px; }
    .company-context-hero__content { padding: 18px 0; }
    .company-context-hero__lead { margin-top: 16px; font-size: 15px; }
    .company-context-hero__position { position: static; width: auto; margin-top: 14px; }
    .company-context-story__body { display: block; }
    .company-context-key-message,
    .company-context-story-section,
    .company-context-strength,
    .company-context-next {
        width: 100%;
        min-height: 0;
        margin: 0;
        padding: 24px 0;
        border-radius: 0;
        color: #000;
        background: #fff;
        box-shadow: none;
    }
    .company-context-key-message__text { color: #000; font-size: 24px; }
    .company-context-story-heading { margin-bottom: 18px; }
    .company-context-story-heading h2 { font-size: 24px; }
    .company-context-perspectives { display: block; }
    .company-context-perspectives__visual-column { display: none; }
    .company-context-perspectives__panels { display: grid !important; gap: 12px; }
    .company-context-perspective {
        min-height: 0;
        display: grid !important;
        grid-template-columns: 50px 1fr;
        padding: 18px;
        opacity: 1;
        transform: none;
        break-inside: avoid;
        box-shadow: none;
    }
    .company-context-perspective__number { font-size: 30px; }
    .company-context-perspective__copy > p:last-child { font-size: 15px; }
    .company-context-strength h2,
    .company-context-strength__statement,
    .company-context-next .company-context-story__eyebrow,
    .company-context-next__label,
    .company-context-next__description,
    .company-context-next__memo { color: #000; }
    .company-context-strength__statement,
    .company-context-next__label { font-size: 28px; }
    .company-context-item { break-inside: avoid; }
    .company-context-read a { color: #000; text-decoration: none; }
    .company-context-archive-notice { display: block !important; break-inside: avoid; }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-context-deck]').forEach(function (deck) {
        var tabs = Array.from(deck.querySelectorAll('[data-context-tab]'));
        var panels = Array.from(deck.querySelectorAll('[data-context-panel]'));
        var nodes = Array.from(deck.querySelectorAll('[data-context-orbit-node]'));
        var lines = Array.from(deck.querySelectorAll('[data-context-orbit-line]'));
        var focus = deck.querySelector('[data-context-orbit-focus]');
        var activeNumber = deck.querySelector('[data-context-active-number]');
        var activeCode = deck.querySelector('[data-context-active-code]');
        var activeLabel = deck.querySelector('[data-context-active-label]');
        var axisProgress = deck.querySelector('[data-context-axis-progress]');
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!tabs.length || tabs.length !== panels.length) return;

        var activate = function (nextIndex, moveFocus) {
            tabs.forEach(function (tab, index) {
                var active = index === nextIndex;
                if (active) tab.setAttribute('aria-current', 'true');
                else tab.removeAttribute('aria-current');
                panels[index].classList.toggle('is-active', active);
                if (nodes[index]) nodes[index].classList.toggle('is-active', active);
                if (lines[index]) lines[index].classList.toggle('is-active', active);
            });

            if (focus) focus.style.transform = 'rotate(' + (Number(tabs[nextIndex].dataset.contextAngle) || -90) + 'deg)';
            if (activeNumber) activeNumber.textContent = String(nextIndex + 1).padStart(2, '0');
            if (activeCode) activeCode.textContent = tabs[nextIndex].dataset.contextCode || '';
            if (activeLabel) activeLabel.textContent = tabs[nextIndex].dataset.contextLabel || '';
            if (axisProgress) axisProgress.style.transform = 'scaleY(' + ((nextIndex + 1) / tabs.length) + ')';
            if (moveFocus) tabs[nextIndex].focus();
        };

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activate(index, false);
                panels[index].scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
            });
            tab.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === null) return;
                event.preventDefault();
                activate(next, true);
                panels[next].scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
            });
        });

        deck.classList.add('is-enhanced');
        activate(0, false);

        if ('IntersectionObserver' in window) {
            var visiblePanels = new Set();
            var perspectiveObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) visiblePanels.add(entry.target);
                    else visiblePanels.delete(entry.target);
                });

                var viewportFocus = window.innerHeight * .42;
                var nearest = Array.from(visiblePanels).sort(function (left, right) {
                    var leftRect = left.getBoundingClientRect();
                    var rightRect = right.getBoundingClientRect();
                    var leftDistance = Math.abs((leftRect.top + (leftRect.height / 2)) - viewportFocus);
                    var rightDistance = Math.abs((rightRect.top + (rightRect.height / 2)) - viewportFocus);
                    return leftDistance - rightDistance;
                })[0];
                if (nearest) activate(panels.indexOf(nearest), false);
            }, { threshold: [0, .18, .5], rootMargin: '-12% 0px -30% 0px' });

            panels.forEach(function (panel) { perspectiveObserver.observe(panel); });
        }
    });

    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('company-context-reveal-active');
                observer.unobserve(entry.target);
            });
        }, { threshold: .12, rootMargin: '0px 0px -6% 0px' });
        document.querySelectorAll('[data-context-reveal]').forEach(function (element) { observer.observe(element); });
    }
});
</script>
@endonce
