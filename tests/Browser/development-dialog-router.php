<?php
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/workspace.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    echo str_replace('@json($project->public_id)', '"test-project"', file_get_contents($root.'/resources/views/development/workspace-script.blade.php'));
    exit;
}
if ($path !== '/') { http_response_code(404); exit; }
header('Content-Type: text/html; charset=utf-8');
$controls = file_get_contents($root.'/resources/views/development/workspace-controls.blade.php');
$controls = preg_replace('/@can[^\n]*|@endcan/', '', $controls);
$controls = preg_replace('/\{\{.*?\}\}/', '/setup', $controls);
echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>Development dialog test</title><p id="result">RUNNING</p><div id="workbench"><div class="file-repository">folder<span></span></div><div id="tree"></div><div id="tabs"><button data-workspace-tab="project" data-tab-kind="document"></button></div>'.$controls.'</div>';
?>
<script>
sessionStorage.clear();
const workbench = document.querySelector('#workbench');
const tabs = document.querySelector('#tabs');
const localTree = document.querySelector('#tree');
let localDevelopmentConnected = false, localDirectoryHandle = null, localSiteUrl = '';
const setChatFileContext = () => {};
const renderLocalDirectory = async () => {};
const chatForm = {elements:{content:{value:"車両管理アプリを作って", focus(){ this.focused = true; }}}};
const showBrowserPreview = () => {};
let requests = [];
window.RiseGateLocalDev = {Client:class {
    constructor(project,token,workspace) { Object.assign(this,{project,token,workspace}); }
    call(action) { return new Promise((resolve,reject) => requests.push({action,resolve,reject})); }
}};
</script>
<script src="/workspace.js"></script>
<script>
(async () => {
    const assert = (test,message) => { if (!test) throw new Error(message); };
    const tick = () => new Promise(resolve => setTimeout(resolve,0));
    try {
        const dialog = document.querySelector('[data-dev-dialog]');
        const form = document.querySelector('[data-dev-form]');
        const save = form.querySelector('[value=save]');
        document.querySelector('[data-dev-action=connect]').click();
        assert(dialog.open, 'dialog opened');
        form.elements.code.value = 'a'.repeat(63);
        save.click();
        await tick();
        assert(requests.length === 0 && form.querySelector('[data-dev-dialog-error]').textContent.includes('現在63文字'), 'length error');
        form.elements.code.value = '  ' + 'a'.repeat(64) + '  ';
        form.elements.code.dispatchEvent(new Event('input', {bubbles:true}));
        assert(form.querySelector('[data-dev-code-count]').textContent.includes('64 / 64'), 'length indicator trims whitespace');
        form.querySelector('[data-dev-show-code]').click();
        assert(form.elements.code.type === 'text', 'optional code visibility');
        save.click();
        assert(requests.length === 1, 'decision starts request');
        assert(save.disabled && save.textContent === '接続中…', 'busy feedback');
        assert(form.querySelector('[data-dev-dialog-progress]').textContent.includes('接続許可'), 'permission hint');
        requests[0].reject(new Error('Failed to fetch'));
        await tick();
        assert(!save.disabled && dialog.open, 'failed request allows retry');
        assert(form.querySelector('[data-dev-dialog-error]').textContent.includes('ローカルネットワーク'), 'error visible');
        save.click();
        assert(requests.length === 2, 'retry starts request');
        requests[1].resolve({sqlite:true,php:'8.4.25',folder:null,workspace:null,url:null});
        await tick();
        assert(!dialog.open && localDevelopmentConnected, 'successful connection closes dialog');
        assert(document.querySelector('[data-dev-status]').textContent.includes('接続済み'), 'success visible');
        document.querySelector('[data-dev-action=create]').click();
        await tick();
        assert(document.querySelector('[data-dev-status]').textContent.includes('保存フォルダ'), 'creation requires folder');
        localDirectoryHandle = {};
        document.querySelector('[data-dev-action=create]').click();
        await tick();
        assert(chatForm.elements.content.focused, 'creation focuses AI input');
        assert(chatForm.elements.content.value === '車両管理アプリを作って', 'existing draft preserved');
        assert(requests.length === 2, 'creation does not invoke a template API');
        assert(!document.querySelector('[data-dev-action=seed]'), 'no TODO-only control');
        document.querySelector('#result').textContent = 'PASS: connect / pending / failure / retry / generic creation / draft preserved';
    } catch (error) {
        document.querySelector('#result').textContent = 'FAIL: ' + error.message;
    }
})();
</script></html>
