<?php
$root=dirname(__DIR__,2);
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/script.js'){
    header('Content-Type: text/javascript; charset=utf-8');
    echo file_get_contents($root.'/resources/views/development/codex-script.blade.php');exit;
}
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)!=='/'){http_response_code(404);exit;}
$panel=preg_replace('/@can[^\n]*|@endcan/','',file_get_contents($root.'/resources/views/development/codex-panel.blade.php'));
echo '<!doctype html><meta charset="utf-8"><p id="result">RUNNING</p><div id="workbench"><div data-ai-standard>会話・資料</div>'.$panel.'</div>';
?>
<script>
const workbench=document.querySelector('#workbench'),localTree=document.createElement('div');
let localDirectoryHandle={},refreshes=0;
const renderLocalDirectory=async()=>{refreshes++;};
const calls=[]; let supportsImages=true, sendFails=false;
let state={phase:'ready',authenticated:true,history:[],approvals:[],error:''};
window.fetch=()=>{throw new Error('OS server must not receive development chat');};
window.RiseGateLocalDev={Client:class{
constructor(project,token,workspace){Object.assign(this,{project,token,workspace});}
async call(action,args={}){
calls.push({action,args});
if(action==='status')return {version:'2.1.0',codexImages:supportsImages};
if(action==='codex_send' && sendFails)throw new Error('Test send failure');
if(action==='codex_send')state={...state,phase:'running',history:[{id:'user',role:'user',text:args.prompt,time:'2026-09-09T10:00:00+09:00'}]};
if(action==='codex_approve')state={...state,approvals:[],phase:'ready'};
if(action==='codex_interrupt')state={...state,phase:'ready'};
return state;
}
}};
const devClient=new RiseGateLocalDev.Client('project','secret','workspace');
</script>
<script src="/script.js"></script>
<script>
(async()=>{
const assert=(value,message)=>{if(!value)throw new Error(message);};
const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
try{
openCodex();
assert(!codexPanel.hidden&&workbench.querySelector('[data-ai-standard]').style.display==='none','separate development view');
codexPanel.querySelector('[data-codex-connect]').click();await tick();
assert(calls.some(c=>c.action==='codex_connect'),'connects local Codex');
const form=codexPanel.querySelector('[data-codex-form]');
form.elements.prompt.value='車両管理を作って';
form.querySelector('button').click();await tick();
const sent=calls.find(c=>c.action==='codex_send');
assert(sent.args.prompt==='車両管理を作って'&&!('project_files' in sent.args),'sends intent without uploading files');
assert(form.elements.prompt.value==='','accepted prompt cleared');
const activity=()=>codexPanel.querySelector('[data-codex-activity-label]').textContent;
assert(activity().includes('Thinking'),'thinking after accepted request');
codexRender({...state,history:[...state.history,{role:'status',text:'実行中：php -l index.php',time:'2026-09-09T10:00:01+09:00'}]});
assert(activity()==='実行中…','command running');
codexRender({...state,history:[...state.history,{role:'assistant',text:'確認しています',time:'2026-09-09T10:00:02+09:00'}]});
assert(activity()==='回答を作成中…','reply streaming');
codexRender(state);
codexProgressAt=Date.now()-61000;codexRender(state);
assert(activity()==='進捗の更新待ち','stale progress is not claimed as working');
codexActivitySignature='';codexRender(state);
const originalClient=codexClient;
codexClient={call:async()=>{throw new Error('Test connection loss');}};
await codexPoll();
assert(activity()==='通信を確認できません'&&form.querySelector('button').disabled,'poll failure visible and send disabled');
codexClient=originalClient;await codexPoll();
assert(activity().includes('Thinking'),'poll recovery restores activity');
assert(codexPanel.querySelector('[data-codex-history]').textContent.includes('JST'),'JST shown');
codexRender({...state,approvals:[{id:3,method:'item/commandExecution/requestApproval',params:{command:'php -l index.php',reason:'<img src=x onerror=alert(1)>'}}]});
assert(activity()==='あなたの確認待ち','approval waiting');
assert(!codexPanel.querySelector('[data-codex-approvals] img'),'approval displayed as text');
codexPanel.querySelector('[data-codex-approvals] button').click();await tick();
assert(calls.some(c=>c.action==='codex_approve'&&c.args.decision==='accept'),'explicit approval sent');
assert(refreshes>0,'file list refreshed after completion');
assert(!codexPanel.querySelector('[data-codex-activity]').classList.contains('is-busy'),'finished spinner stops');
codexRender({...state,phase:'running'});
codexPanel.querySelector('[data-codex-stop]').click();await tick();
assert(calls.some(c=>c.action==='codex_interrupt'),'stop sent');
state={...state,phase:'ready'};codexRender(state);
const png=new File([Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aR9sAAAAASUVORK5CYII='),c=>c.charCodeAt(0))],'first.png',{type:'image/png'});
codexAddImages([png,png]);
assert(codexImages.length===2&&codexPanel.querySelectorAll('[data-codex-image-previews] img').length===2,'multiple previews');
codexPanel.querySelector('[data-codex-image-previews] button').click();
assert(codexImages.length===1,'individual removal');
const clipboard=new DataTransfer();clipboard.items.add(png);
form.elements.prompt.dispatchEvent(new ClipboardEvent('paste',{clipboardData:clipboard,bubbles:true,cancelable:true}));
assert(codexImages.length===2,'paste appends');
codexAddImages([png,png]);assert(codexImages.length===2,'too many rejected');
supportsImages=false;
form.querySelector('button[type="submit"]').click();
for(let i=0;i<100&&form.dataset.sending==='true';i++)await tick();
assert(codexImages.length===2&&codexPanel.querySelector('[data-codex-error]').textContent.includes('更新'),'old helper retains attachments');
supportsImages=true;sendFails=true;
form.querySelector('button[type="submit"]').click();
for(let i=0;i<100&&form.dataset.sending==='true';i++)await tick();
assert(codexImages.length===2&&codexPanel.querySelector('[data-codex-error]').textContent.includes('failure'),'failed send retains attachments');
sendFails=false;form.querySelector('button[type="submit"]').click();
for(let i=0;i<100&&form.dataset.sending==='true';i++)await tick();
const imageRequest=calls.filter(c=>c.action==='codex_send').at(-1);
assert(imageRequest.args.images.length===2&&imageRequest.args.images.every(img=>img.url.startsWith('data:image/png;base64,')),'all images reach local helper');
assert(codexImages.length===0,'accepted attachments cleared');
codexAddImages([png]);codexPanel.querySelector('[data-codex-back]').click();
assert(codexPanel.hidden&&workbench.querySelector('[data-ai-standard]').style.display==='','returns to regular AI');
workbench.dispatchEvent(new CustomEvent('development-folder-changed'));
assert(!codexClient&&codexPanel.querySelector('[data-codex-history]').children.length===0,'folder histories separated');
clearInterval(codexTimer);
document.querySelector('#result').textContent='PASS: local routing / approval / stop / JST / folder separation / view switching';
}catch(error){document.querySelector('#result').textContent='FAIL: '+error.message;clearInterval(codexTimer);}
})();
</script>