<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $attachment->original_name }}</title>
    <style>
        :root { color-scheme:light; font-family:"Yu Gothic",Meiryo,sans-serif; color:#17202a; background:#f4f7f8; }
        * { box-sizing:border-box; }
        body { margin:0; padding:14px; }
        .viewer { display:grid; gap:12px; min-width:0; }
        .bar { position:sticky; top:0; z-index:2; display:grid; grid-template-columns:minmax(180px,1fr) auto auto; gap:12px; align-items:center; padding:12px; border:1px solid #d6dee2; border-radius:9px; background:#fff; box-shadow:0 5px 18px rgba(28,52,62,.08); }
        .title { min-width:0; overflow:hidden; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
        label { display:flex; align-items:center; gap:7px; font-size:13px; }
        select,.button { min-height:38px; padding:7px 11px; border:1px solid #b9c8ce; border-radius:7px; background:#fff; color:#194c5d; font:inherit; }
        .button { display:inline-flex; align-items:center; text-decoration:none; }
        .meta { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; color:#63747c; font-size:13px; }
        .table-wrap { height:calc(100vh - 130px); min-height:320px; overflow:auto; border:1px solid #cdd8dd; border-radius:9px; background:#fff; }
        table { width:max-content; min-width:100%; border-collapse:collapse; font-size:13px; }
        th,td { min-width:96px; max-width:320px; padding:7px 9px; border:1px solid #d7dde5; text-align:left; vertical-align:top; white-space:pre-wrap; overflow-wrap:anywhere; }
        th { position:sticky; top:0; z-index:1; background:#eef3f7; }
        [hidden] { display:none !important; }
        @media(max-width:700px) { body{padding:8px}.bar{grid-template-columns:1fr}.table-wrap{height:calc(100vh - 210px)} }
    </style>
</head>
<body>
<main class="viewer" data-excel-viewer data-file-url="{{ $fileUrl }}">
    <header class="bar">
        <div class="title">{{ $attachment->original_name }}</div>
        <label>シート <select data-sheet aria-label="シート選択"></select></label>
        <a class="button" href="{{ $downloadUrl }}">ダウンロード</a>
    </header>
    <div class="meta"><span data-status>Excelを読み込んでいます…</span><button class="button" type="button" data-more hidden>さらに表示</button></div>
    <div class="table-wrap" data-table></div>
</main>
<script src="{{ asset('assets/js/xlsx.full.min.js') }}"></script>
<script>
(() => {
    const viewer = document.querySelector('[data-excel-viewer]');
    const sheetSelect = document.querySelector('[data-sheet]');
    const status = document.querySelector('[data-status]');
    const table = document.querySelector('[data-table]');
    const more = document.querySelector('[data-more]');
    const pageSize = 300;
    const maxRows = 3000;
    const maxColumns = 100;
    let workbook;
    let rows = [];
    let visible = pageSize;
    let wasLimited = false;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const render = () => {
        if (!rows.length) { status.textContent = '表示できるデータがありません。'; table.innerHTML = ''; more.hidden = true; return; }
        const shownRows = rows.slice(0, visible);
        const columns = Math.min(maxColumns, shownRows.reduce((maximum, row) => Math.max(maximum, row.length), 0));
        table.innerHTML = `<table><tbody>${shownRows.map((row, rowIndex) => `<tr>${Array.from({length:columns}, (_, columnIndex) => `<${rowIndex === 0 ? 'th' : 'td'}>${escapeHtml(row[columnIndex])}</${rowIndex === 0 ? 'th' : 'td'}>`).join('')}</tr>`).join('')}</tbody></table>`;
        const shown = Math.min(visible, rows.length);
        status.textContent = `${rows.length}行中 ${shown}行を表示${wasLimited ? '（安全のため最大3,000行・100列まで）' : ''}`;
        more.hidden = shown >= rows.length;
    };
    const renderSheet = name => {
        const sheet = workbook?.Sheets[name];
        if (!sheet) return;
        const original = XLSX.utils.decode_range(sheet['!ref'] || 'A1:A1');
        const range = {s:original.s, e:{r:Math.min(original.e.r, original.s.r + maxRows - 1), c:Math.min(original.e.c, original.s.c + maxColumns - 1)}};
        wasLimited = original.e.r > range.e.r || original.e.c > range.e.c;
        rows = XLSX.utils.sheet_to_json(sheet, {header:1, blankrows:false, defval:'', range});
        visible = pageSize;
        render();
    };
    if (!viewer || typeof XLSX === 'undefined') { status.textContent = 'Excelビューワーを読み込めませんでした。ダウンロードして確認してください。'; return; }
    fetch(viewer.dataset.fileUrl, {credentials:'same-origin'})
        .then(response => { if (!response.ok) throw new Error(); return response.arrayBuffer(); })
        .then(buffer => {
            workbook = XLSX.read(buffer, {type:'array', cellFormula:false, cellHTML:false, cellStyles:false, bookVBA:false});
            sheetSelect.replaceChildren(...workbook.SheetNames.map(name => Object.assign(document.createElement('option'), {value:name, textContent:name})));
            renderSheet(workbook.SheetNames[0]);
        })
        .catch(() => { status.textContent = 'Excelを表示できませんでした。ダウンロードして確認してください。'; });
    sheetSelect.addEventListener('change', event => renderSheet(event.target.value));
    more.addEventListener('click', () => { visible += pageSize; render(); });
})();
</script>
</body>
</html>
