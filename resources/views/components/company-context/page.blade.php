@props(['wide' => false])

<section {{ $attributes->class(['company-context-read', 'company-context-read--wide' => $wide]) }}>
    {{ $slot }}
</section>

@once
<style>
.company-context-read {
    --context-ink: #0a2730;
    --context-deep: #061d26;
    --context-teal: #17727b;
    --context-aqua: #8ee1d6;
    --context-warm: #f5f1e9;
    --context-paper: #fffdf8;
    --context-line: rgba(10, 39, 48, .15);
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
    color: #f4ffff;
    background:
        radial-gradient(circle at 80% 20%, rgba(88, 221, 207, .16), transparent 32%),
        radial-gradient(circle at 18% 88%, rgba(47, 135, 149, .28), transparent 42%),
        linear-gradient(145deg, #071820 0%, #092d37 55%, #061c26 100%);
    box-shadow: 0 35px 80px rgba(7, 28, 37, .19);
}
.company-context-hero::after {
    content: "";
    position: absolute;
    z-index: -1;
    inset: 0;
    opacity: .16;
    background-image: linear-gradient(rgba(255,255,255,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.08) 1px, transparent 1px);
    background-size: 72px 72px;
    mask-image: linear-gradient(to bottom, transparent 5%, #000 42%, transparent 100%);
}
.company-context-hero__atmosphere { position: absolute; z-index: -1; inset: 0; pointer-events: none; }
.company-context-hero__atmosphere span { position: absolute; border: 1px solid rgba(149, 237, 225, .18); border-radius: 50%; }
.company-context-hero__atmosphere span:nth-child(1) { width: 660px; height: 660px; right: -250px; top: -290px; }
.company-context-hero__atmosphere span:nth-child(2) { width: 430px; height: 430px; right: -70px; top: -100px; border-style: dashed; animation: company-context-orbit 42s linear infinite; }
.company-context-hero__atmosphere span:nth-child(3) { width: 260px; height: 260px; left: -120px; bottom: -90px; box-shadow: 0 0 100px rgba(64, 213, 203, .1); }
.company-context-hero__topline { align-self: start; display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; }
.company-context-hero__company { margin: 0; color: #a9c8ce; font-size: 12px; font-weight: 750; letter-spacing: .12em; }
.company-context-hero__content { width: min(940px, 100%); align-self: center; padding: clamp(48px, 8vh, 96px) 0; }
.company-context-hero__kicker { margin: 0 0 24px; color: var(--context-aqua); font-size: 11px; font-weight: 800; letter-spacing: .22em; }
.company-context-hero h1 {
    max-width: 1030px;
    margin: 0;
    color: #f7ffff;
    font-size: clamp(54px, 9.2vw, 118px);
    font-weight: 620;
    line-height: .98;
    letter-spacing: -.065em;
    text-wrap: balance;
}
.company-context-hero__lead { max-width: 760px; margin: clamp(32px, 5vw, 58px) 0 0; color: #d4e7e9; font-size: clamp(19px, 2.2vw, 28px); line-height: 1.85; }
.company-context-hero__status { display: inline-flex; margin-top: 22px; padding: 7px 12px; border: 1px solid rgba(255, 203, 154, .45); border-radius: 999px; color: #ffd4ad; font-size: 12px; font-weight: 800; }
.company-context-hero__position { position: absolute; right: clamp(28px, 5vw, 68px); bottom: clamp(28px, 5vw, 64px); width: min(340px, 38%); margin: 0; color: #b6d0d4; font-size: 13px; line-height: 1.75; }
.company-context-hero__position span { display: block; margin-bottom: 8px; color: var(--context-aqua); font-size: 9px; font-weight: 850; letter-spacing: .2em; }
.company-context-hero__continue { align-self: end; width: fit-content; display: inline-flex; align-items: center; gap: 18px; color: #c7e2e3; font-size: 12px; font-weight: 750; letter-spacing: .08em; }
.company-context-hero__continue span:last-child { display: grid; width: 34px; height: 34px; place-items: center; border: 1px solid rgba(169, 226, 222, .4); border-radius: 50%; color: var(--context-aqua); }
.company-context-tools { position: relative; z-index: 5; }
.company-context-tools summary {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    padding: 0 0 7px;
    border: 1px solid rgba(214, 244, 242, .28);
    border-radius: 50%;
    color: #d8eeee;
    background: rgba(255,255,255,.06);
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
    font-weight: 600;
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
.company-context-story-heading h2 { margin: 0; font-size: clamp(38px, 6vw, 70px); font-weight: 620; line-height: 1.08; letter-spacing: -.05em; }
.company-context-story-heading > p:last-child:not(.company-context-story__eyebrow) { margin: 5px 0 0; color: #617276; font-size: 16px; }
/* Rotary card-file interpretation: accessible facets; stacked without JavaScript */
.company-context-perspectives { display: grid; gap: 26px; }
.company-context-perspectives__tabs { display: flex; gap: 8px; overflow-x: auto; padding: 4px 3px 10px; scrollbar-width: thin; }
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
.company-context-perspectives__tabs button[aria-selected="true"] { border-color: #0d6c73; color: #fff; background: #0b5962; }
.company-context-perspectives__tabs button[aria-selected="true"] span { color: #a7e3db; }
.company-context-perspectives__deck { display: grid; gap: 18px; }
.company-context-perspective {
    min-height: 300px;
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    align-items: center;
    gap: clamp(24px, 5vw, 70px);
    padding: clamp(34px, 6vw, 72px);
    border: 1px solid rgba(13, 76, 87, .15);
    border-radius: 24px;
    background: linear-gradient(135deg, rgba(255,255,255,.96), rgba(237,246,243,.9)), var(--context-paper);
    box-shadow: 0 24px 60px rgba(12, 53, 60, .09);
}
.company-context-perspective__number { color: rgba(11, 89, 98, .2); font-size: clamp(54px, 8vw, 96px); font-weight: 300; letter-spacing: -.08em; }
.company-context-perspective__copy { display: grid; gap: 12px; }
.company-context-perspective__code { margin: 0; color: var(--context-teal); font-size: 11px; font-weight: 850; letter-spacing: .2em; }
.company-context-perspective h3 { margin: 0; color: #617276; font-size: 15px; font-weight: 700; }
.company-context-perspective__copy > p:last-child { max-width: 760px; margin: 8px 0 0; color: var(--context-ink); font-size: clamp(21px, 3.2vw, 34px); font-weight: 520; line-height: 1.65; }
.company-context-perspectives.is-enhanced .company-context-perspectives__deck { position: relative; min-height: 390px; padding: 18px 18px 0 0; }
.company-context-perspectives.is-enhanced .company-context-perspectives__deck::before,
.company-context-perspectives.is-enhanced .company-context-perspectives__deck::after {
    content: "";
    position: absolute;
    z-index: -1;
    border: 1px solid rgba(13, 76, 87, .12);
    border-radius: 24px;
    background: #e8f0ed;
}
.company-context-perspectives.is-enhanced .company-context-perspectives__deck::before { inset: 9px; transform: translate(9px, 9px); }
.company-context-perspectives.is-enhanced .company-context-perspectives__deck::after { inset: 18px 0 0 18px; transform: translate(0, 9px); opacity: .55; }
.company-context-perspectives.is-enhanced .company-context-perspective { min-height: 360px; }
.company-context-perspective[hidden] { display: none; }
.company-context-perspectives__hint { margin: 0; color: #78898c; font-size: 11px; letter-spacing: .05em; text-align: right; }
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
    .company-context-hero__content { padding: 56px 0 120px; }
    .company-context-hero h1 { font-size: clamp(48px, 15vw, 72px); line-height: 1.02; }
    .company-context-hero__lead { margin-top: 30px; font-size: 18px; line-height: 1.8; }
    .company-context-hero__position { left: 22px; right: 80px; bottom: 76px; width: auto; font-size: 11px; }
    .company-context-hero__continue { position: absolute; left: 22px; bottom: 23px; }
    .company-context-key-message { width: calc(100% - 32px); min-height: 560px; padding: 100px 0; }
    .company-context-key-message__text { font-size: clamp(34px, 10vw, 48px); line-height: 1.4; }
    .company-context-story-section { width: calc(100% - 32px); padding: 100px 0; }
    .company-context-story-heading { margin-bottom: 44px; }
    .company-context-story-heading h2 { font-size: 42px; }
    .company-context-perspectives__tabs { width: calc(100vw - 38px); margin-right: -6px; }
    .company-context-perspectives__tabs button { padding: 10px 13px; }
    .company-context-perspective { min-height: auto; grid-template-columns: 1fr; gap: 4px; padding: 28px 23px; border-radius: 18px; }
    .company-context-perspective__number { font-size: 48px; }
    .company-context-perspective__copy > p:last-child { font-size: 21px; line-height: 1.7; }
    .company-context-perspectives.is-enhanced .company-context-perspectives__deck { min-height: 420px; padding: 12px 12px 0 0; }
    .company-context-perspectives.is-enhanced .company-context-perspective { min-height: 390px; }
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
    .company-context-hero__atmosphere { display: none; }
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
    .company-context-perspectives__deck { display: grid !important; min-height: 0 !important; padding: 0 !important; gap: 12px; }
    .company-context-perspectives__deck::before,
    .company-context-perspectives__deck::after { display: none; }
    .company-context-perspective,
    .company-context-perspectives.is-enhanced .company-context-perspective,
    .company-context-perspective[hidden] {
        min-height: 0;
        display: grid !important;
        grid-template-columns: 50px 1fr;
        padding: 18px;
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
        if (!tabs.length || tabs.length !== panels.length) return;

        var activate = function (nextIndex, moveFocus) {
            tabs.forEach(function (tab, index) {
                var active = index === nextIndex;
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
                panels[index].hidden = !active;
            });
            if (moveFocus) tabs[nextIndex].focus();
        };

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { activate(index, false); });
            tab.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
                if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (next === null) return;
                event.preventDefault();
                activate(next, true);
            });
        });

        deck.classList.add('is-enhanced');
        activate(0, false);
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
