<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/site.css'){header('Content-Type: text/css');echo 'body{background:rgb(12, 34, 56)}';exit;}
if($path==='/index.html'){echo '<!doctype html><link rel="stylesheet" href="site.css"><p>Site preview</p>';exit;}
$source=file_get_contents(dirname(__DIR__,2).'/resources/views/projects/workspace.blade.php');
function section($source,$start,$end){$a=strpos($source,$start);return substr($source,$a,strpos($source,$end,$a)-$a);}
$functions=section($source,'    const localBrowserUrl =','    const formatFileSavedAt =');
$click=section($source,"        const fileButton = event.target.closest('[data-file-name]');",'        const imageSize =');
$click=str_replace('@json($project->name)','"Test"',$click);
$browser=section($source,"        const browserButton = event.target.closest('[data-open-local-browser]');","        if (event.target.closest('[data-usage-toggle]'))");
?>
<!doctype html><meta charset="utf-8"><p id="result">RUNNING</p>
<div data-workbench>
<button data-file-name="index.html">index.html</button><div data-tabs></div>
<h1 data-file-title></h1><div data-file-preview-actions><button data-open-local-browser>ブラウザで表示</button><a data-open-local-external>外部</a></div>
<div data-ai-context></div><input data-chat-context-key><input data-chat-context-label>
<div data-browser-external-notice><a data-browser-external-link></a></div><iframe data-browser-frame hidden></iframe>
</div>
<script>
const workbench=document.querySelector('[data-workbench]'),tabs=workbench.querySelector('[data-tabs]');
let localSiteUrl='',localDevelopmentConnected=true,view='',code='',startCount=0,running=false;
const ensureTab=({id,kind})=>{let tab=tabs.querySelector('[data-workspace-tab="'+id+'"]');if(!tab){tab=document.createElement('button');tab.dataset.workspaceTab=id;tabs.append(tab);}tab.dataset.tabKind=kind;};
const setFilePreviewTitle=path=>workbench.querySelector('[data-file-title]').dataset.filePath=path;
const renderCode=text=>code=text,showViewer=name=>view=name,setChatFileContext=()=>{},showMobilePane=()=>{};
const devStatus=message=>{throw new Error(message);};
const devClient={call:async action=>{if(action==='start'){startCount++;running=true;}return {url:running?location.origin+'/':''};}};
const devShowState=async state=>{localSiteUrl=state.url;};
<?= $functions ?>
const file=workbench.querySelector('[data-file-name]');
file.localFileHandle={getFile:async()=>new File(['<!doctype html><link rel="stylesheet" href="site.css"><p>Source</p>'],'index.html',{type:'text/html'})};
workbench.addEventListener('click',async event=>{
<?= $click ?>
<?= $browser ?>
workbench.dispatchEvent(new Event('test-click-complete'));
});
(async()=>{
const assert=(value,message)=>{if(!value)throw new Error(message);};
const settle=()=>new Promise(r=>setTimeout(r,100));
try{
await new Promise(resolve=>{workbench.addEventListener('test-click-complete',resolve,{once:true});file.click();});
assert(view==='file'&&code.includes('Source'),'file click opens code');
assert(tabs.querySelector('[data-workspace-tab="file:index.html"]').dataset.tabKind==='file','source tab remains a code tab');
assert(!startCount&&!workbench.querySelector('iframe').hasAttribute('src'),'file click does not launch a site');
const button=workbench.querySelector('[data-open-local-browser]');
assert(!button.hidden,'browser button available before server starts');
const frame=workbench.querySelector('iframe');
await new Promise(resolve=>{frame.onload=resolve;button.click();});await settle();
assert(view==='browser'&&startCount===1,'browser button starts server');
assert(frame.src===location.origin+'/index.html','preview uses server URL');
assert(getComputedStyle(frame.contentDocument.body).backgroundColor==='rgb(12, 34, 56)','relative stylesheet loads');
await new Promise(resolve=>{frame.onload=resolve;button.click();});await settle();
assert(startCount===1,'running server is reused');
await new Promise(resolve=>{workbench.addEventListener('test-click-complete',resolve,{once:true});file.click();});assert(view==='file','return to file opens code again');
assert(localBrowserUrl('public/admin.php')===location.origin+'/admin.php','PHP public directory maps to server root');
document.querySelector('#result').textContent='PASS: code first / browser action / server reuse / relative CSS';
}catch(error){document.querySelector('#result').textContent='FAIL: '+error.message;}
})();
</script>