(() => {
    document.querySelectorAll('[data-responsive-preview]').forEach(preview => {
        const frame=preview.querySelector('[data-browser-frame]');
        const width=preview.querySelector('[data-preview-width]');
        const label=preview.querySelector('[data-preview-size]');
        const apply=value=>{
            const pixels=Number(value);
            if(value!=='fit' && (!Number.isInteger(pixels)||pixels<280||pixels>2560)){
                width.setCustomValidity('幅は280〜2560pxで指定してください。');width.reportValidity();return;
            }
            width.setCustomValidity('');
            frame.style.width=value==='fit'?'100%':pixels+'px';
            frame.style.maxWidth='none';
            preview.querySelectorAll('[data-preview-preset]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.previewPreset===String(value))));
            if(value!=='fit')width.value=String(pixels);
            label.textContent=value==='fit'?'ペインに合わせる':pixels+'px';
        };
        preview.querySelectorAll('[data-preview-preset]').forEach(button=>button.addEventListener('click',()=>apply(button.dataset.previewPreset)));
        width.addEventListener('change',()=>apply(width.value));
        preview.querySelector('[data-preview-refresh]').addEventListener('click',()=>{if(frame.getAttribute('src'))frame.src=frame.src;});
        apply('fit');
    });
})();