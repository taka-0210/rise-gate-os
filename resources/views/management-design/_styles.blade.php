<style>
    .mdc-shell { width:min(1180px, calc(100% - 40px)); margin:0 auto; color:#17202a; }
    .mdc-shell * { min-width:0; }
    .mdc-kicker { margin:0 0 12px; color:#376573; font-size:12px; font-weight:800; letter-spacing:.16em; text-transform:uppercase; }
    .mdc-lead { max-width:720px; margin:18px 0 0; color:#526873; font-size:16px; line-height:2; }
    .mdc-actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
    .mdc-actions--secondary { justify-content:flex-end; margin-bottom:22px; }
    .mdc-status { display:inline-flex; padding:5px 10px; border:1px solid #bfd0d5; border-radius:999px; color:#315965; background:#f8fbfb; font-size:12px; font-weight:700; }
    .mdc-empty { padding:42px 0; border-top:1px solid #cfdadd; border-bottom:1px solid #cfdadd; }
    .mdc-empty h2 { font-size:clamp(25px, 4vw, 38px); }
    .mdc-empty p { max-width:680px; }
    .mdc-directory__header { padding:34px 0 48px; }
    .mdc-directory__header h1 { max-width:760px; font-size:clamp(40px, 7vw, 74px); line-height:1.08; letter-spacing:-.045em; }
    .mdc-directory__list { display:grid; gap:1px; background:#ced9dc; border-top:1px solid #ced9dc; border-bottom:1px solid #ced9dc; }
    .mdc-directory__item { display:grid; grid-template-columns:110px minmax(0, 1fr) auto; gap:28px; align-items:center; padding:32px 24px; color:inherit; background:#f8faf9; }
    .mdc-directory__item:hover { background:#fff; }
    .mdc-directory__code { color:#55727a; font-size:12px; font-weight:800; letter-spacing:.16em; }
    .mdc-directory__item h2 { margin:0 0 8px; font-size:clamp(24px, 3vw, 36px); }
    .mdc-directory__item p { margin:0; }
    .mdc-directory__arrow { font-size:28px; }
    .mdc-directory__item--locked { opacity:.72; }
    .mdc-read { width:100%; overflow:hidden; }
    .mdc-hero { position:relative; padding:70px max(28px, calc((100% - 1080px) / 2)) 62px; }
    .mdc-hero h1 { margin:0; font-size:clamp(52px, 8vw, 94px); line-height:1.02; letter-spacing:-.055em; }
    .mdc-hero__statement { max-width:900px; margin:34px 0 0; white-space:pre-wrap; overflow-wrap:anywhere; font-size:clamp(24px, 3.4vw, 46px); line-height:1.55; letter-spacing:-.02em; }
    .mdc-hero--long .mdc-hero__statement { max-width:760px; font-size:clamp(18px, 1.8vw, 26px); line-height:1.9; letter-spacing:0; }
    .mdc-hero__horizon { margin:24px 0 0; color:inherit; font-size:14px; letter-spacing:.08em; }
    .mdc-archive { padding:13px 20px; color:#713f12; background:#fff7dc; border-block:1px solid #ead59d; text-align:center; }
    .mdc-sections { width:min(1040px, calc(100% - 40px)); margin:0 auto; padding:64px 0 100px; }
    .mdc-section { overflow-wrap:anywhere; }
    .mdc-section h2 { font-size:clamp(25px, 3.4vw, 42px); line-height:1.35; }
    .mdc-section__body { white-space:pre-wrap; color:#334b55; font-size:clamp(18px, 1.55vw, 21px); line-height:1.9; }
    .mdc-section__horizon { color:#51717a; font-size:13px; letter-spacing:.08em; }
    .mdc-read--philosophy .mdc-hero { min-height:68vh; display:grid; grid-template-columns:minmax(0,1fr); align-content:center; text-align:center; background:#f4f0e8; }
    .mdc-read--philosophy .mdc-hero__statement { width:min(900px, 100%); margin-inline:auto; font-weight:700; }
    .mdc-read--philosophy .mdc-sections { width:min(760px, calc(100% - 40px)); }
    .mdc-read--philosophy .mdc-section { position:relative; padding:54px 0 54px 48px; border-left:1px solid #b9afa1; }
    .mdc-read--philosophy .mdc-section::before { content:''; position:absolute; left:-4px; top:66px; width:7px; height:7px; border-radius:50%; background:#6e6254; }
    .mdc-read--vision .mdc-hero { min-height:62vh; color:#effafa; background:linear-gradient(145deg,#092b34,#0f5360 62%,#177181); }
    .mdc-read--vision .mdc-kicker,.mdc-read--vision .mdc-hero__horizon { color:#a9dadd; }
    .mdc-read--vision .mdc-sections { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0 58px; }
    .mdc-read--vision .mdc-section { padding:42px 0; border-top:1px solid #afcbd0; }
    .mdc-read--vision .mdc-section:nth-child(3n) { grid-column:1 / -1; max-width:760px; }
    .mdc-read--policy .mdc-hero { min-height:52vh; color:#17202a; background:#e8eeeb; border-bottom:8px solid #244f55; }
    .mdc-read--policy .mdc-sections { counter-reset:policy; }
    .mdc-read--policy .mdc-section { counter-increment:policy; display:grid; grid-template-columns:90px minmax(0,1fr); gap:28px; padding:42px 0; border-bottom:1px solid #b9c8cb; }
    .mdc-read--policy .mdc-section::before { content:counter(policy, decimal-leading-zero); color:#315e66; font-size:34px; font-weight:800; }
    .mdc-section--empty { color:#667b83; text-align:center; padding:44px 0; border-block:1px solid #d6dfe1; }
    .mdc-form { display:grid; gap:22px; }
    .mdc-form__intro { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:22px; align-items:end; }
    .mdc-form__section { display:grid; gap:14px; padding:22px; border:1px solid #d3dddf; border-radius:10px; background:#fff; }
    .mdc-form__section-head { display:flex; justify-content:space-between; gap:12px; align-items:center; }
    .mdc-form textarea { min-height:130px; resize:vertical; }
    .mdc-form textarea[name=statement] { min-height:180px; }
    .mdc-history { display:grid; gap:12px; }
    .mdc-history__row { display:grid; grid-template-columns:130px 180px 150px minmax(0,1fr); gap:12px; padding:18px; border:1px solid #d6dfe1; border-radius:8px; color:inherit; background:#fff; }
    .mdc-revision-section { padding:28px 0; border-top:1px solid #d3dddf; }
    .mdc-permissions { display:grid; gap:22px; }
    .mdc-permission-type { padding:24px; border:1px solid #d3dddf; border-radius:10px; background:#fff; }
    .mdc-permission-row { display:grid; grid-template-columns:minmax(0,1fr) 110px 110px; gap:12px; align-items:center; padding:13px 0; border-top:1px solid #e1e7e8; }
    .mdc-check { display:flex; gap:8px; align-items:center; margin:0; font-weight:600; }
    .mdc-check input { width:auto; }
    @media(max-width:600px) {
        .mdc-shell,.mdc-sections { width:min(100% - 28px, 1180px); }
        .mdc-directory__header { padding-top:18px; }
        .mdc-directory__item { grid-template-columns:1fr; gap:10px; padding:25px 18px; }
        .mdc-directory__arrow { display:none; }
        .mdc-hero { padding:48px 22px; min-height:auto!important; }
        .mdc-hero h1 { font-size:44px; }
        .mdc-hero__statement { font-size:25px; }
        .mdc-hero--long .mdc-hero__statement { font-size:18px; line-height:1.9; }
        .mdc-read--philosophy .mdc-section { padding:38px 0 38px 28px; }
        .mdc-read--vision .mdc-sections { grid-template-columns:1fr; }
        .mdc-read--vision .mdc-section:nth-child(3n) { grid-column:auto; }
        .mdc-read--policy .mdc-section { grid-template-columns:48px minmax(0,1fr); gap:14px; }
        .mdc-read--policy .mdc-section::before { font-size:24px; }
        .mdc-form__intro,.mdc-permission-row,.mdc-history__row { grid-template-columns:1fr; }
        .mdc-actions > * { max-width:100%; }
    }
    @media(prefers-reduced-motion:reduce) { .mdc-read * { scroll-behavior:auto!important; } }
    @media print { .mdc-print-hidden,.topbar,.breadcrumbs { display:none!important; } .mdc-hero { min-height:auto!important; color:#000!important; background:#fff!important; border-bottom:1px solid #000; } }
</style>
