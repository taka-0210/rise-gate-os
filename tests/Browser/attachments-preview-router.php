<?php
$root=dirname(__DIR__,2);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/responsive.js'){header('Content-Type: text/javascript');readfile($root.'/public/js/responsive-preview.js');exit;}
if($path==='/frame'){echo '<!doctype html><style>body{background:blue}@media(max-width:500px){body{background:red}}</style>Viewport';exit;}
$source=file_get_contents($root.'/resources/views/projects/workspace.blade.php');
$start=strpos($source, '    const chatImageInput =');
$end=strpos($source, '    const appendDirectImageSave', $start);
$script=substr($source,$start,$end-$start);
$start=strpos($source, '<div class="browser-preview"');
$end=strpos($source, '            <div class="viewer-panel" data-viewer-panel="pdf">',$start);
$preview=substr($source,$start,$end-$start);
preg_match('~<style>(.*?)</style>~s',$source,$styles);
echo '<!doctype html><meta charset="utf-8"><style>'.($styles[1]??'').'</style><p id="result">RUNNING</p>';
echo '<form id="chat"><textarea name="content"></textarea><input type="file" multiple data-chat-image-input><button type="button" data-chat-image-select>選択</button><div data-chat-image-preview></div></form><p id="error"></p><div style="width:600px">'.$preview.'</div>';
?>
<script>
const chatForm=document.querySelector('#chat'),chatError=document.querySelector('#error');
</script>
<script><?= $script ?></script>
<script src="/responsive.js"></script>
<script>
(async()=>{
const assert=(value,message)=>{if(!value)throw new Error(message);};
const frame=document.querySelector('[data-browser-frame]');
try{
const image=new File(['synthetic'],'one.png',{type:'image/png'});
addChatImages([image,image]);
assert(chatImages.length===2&&document.querySelectorAll('[data-chat-image-preview] img').length===2,'multiple normal AI previews');
chatImagePreview.querySelector('button').click();assert(chatImages.length===1,'remove one');
const transfer=new DataTransfer();transfer.items.add(image);
chatForm.elements.content.dispatchEvent(new ClipboardEvent('paste',{clipboardData:transfer,bubbles:true,cancelable:true}));
assert(chatImages.length===2,'paste appends');
addChatImages([image,image]);assert(chatImages.length===2,'limit preserves existing attachments');
addChatImages([new File(['x'],'bad.svg',{type:'image/svg+xml'})]);assert(chatImages.length===2,'invalid format rejected');
chatForm.dataset.sending='true';addChatImages([image]);assert(chatImages.length===2,'attachments locked during send');
chatForm.dataset.sending='false';clearChatImages();assert(!chatImages.length&&!chatImagePreview.children.length,'clear');
frame.hidden=false;
await new Promise(resolve=>{frame.onload=resolve;frame.src='/frame';});
const preset=value=>document.querySelector('[data-preview-preset="'+value+'"]').click();
preset('390');await new Promise(r=>setTimeout(r,50));
assert(frame.contentWindow.innerWidth===390&&frame.contentWindow.matchMedia('(max-width:500px)').matches,'mobile actual viewport');
preset('1280');await new Promise(r=>setTimeout(r,50));
assert(frame.contentWindow.innerWidth===1280&&!frame.contentWindow.matchMedia('(max-width:500px)').matches,'desktop viewport not clamped to pane');
preset('768');assert(frame.style.width==='768px','tablet');
const width=document.querySelector('[data-preview-width]');width.value=420;width.dispatchEvent(new Event('change'));
assert(frame.style.width==='420px','custom width');
width.value=200;width.dispatchEvent(new Event('change'));assert(frame.style.width==='420px','invalid width rejected');
preset('fit');assert(frame.style.width==='100%','fit');
document.querySelector('#result').textContent='PASS: normal AI attachments / paste / limits / actual responsive viewport';
}catch(error){document.querySelector('#result').textContent='FAIL: '+error.message;}
})();
</script>