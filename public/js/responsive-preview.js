(() => {
    document.querySelectorAll('[data-responsive-preview]').forEach(preview => {
        const frame=preview.querySelector('[data-browser-frame]');
        const width=preview.querySelector('[data-preview-width]');
        const slider=preview.querySelector('[data-preview-slider]');
        const toolbar=preview.querySelector('[data-responsive-toolbar]');
        const label=preview.querySelector('[data-preview-size]');
        const stage=preview.querySelector('.responsive-stage');
        let developmentUrl='', enabled=false, selected='fit';
        const syncFit=()=>{
            if(selected!=='fit')return;
            const actual=Math.round(stage.clientWidth);
            if(!actual)return;
            width.value=String(Math.max(280,Math.min(2560,actual)));
            slider.value=width.value;
            label.textContent='全幅 '+actual+'px';
        };
        const apply=value=>{
            const pixels=Number(value);
            if(value!=='fit' && (!Number.isInteger(pixels)||pixels<280||pixels>2560)){
                width.setCustomValidity('幅は280〜2560pxで指定してください。');width.reportValidity();return;
            }
            width.setCustomValidity('');selected=String(value);
            frame.style.width=value==='fit'?'100%':pixels+'px';
            frame.style.maxWidth='none';
            preview.querySelectorAll('[data-preview-preset]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.previewPreset===String(value))));
            if(value!=='fit'){width.value=String(pixels);slider.value=String(pixels);label.textContent=pixels+'px';}
            else syncFit();
        };
        const updateVisibility=()=>{
            let matches=false;
            try { matches=!!developmentUrl && new URL(frame.src).origin===new URL(developmentUrl).origin; } catch {}
            enabled=matches;toolbar.hidden=!enabled;
            if(!enabled)apply('fit');
        };
        preview.addEventListener('development-preview-state',event=>{
            developmentUrl=event.detail?.url || '';updateVisibility();
        });
        new MutationObserver(updateVisibility).observe(frame,{attributes:true,attributeFilter:['src']});
        new ResizeObserver(syncFit).observe(stage);
        preview.querySelectorAll('[data-preview-preset]').forEach(button=>button.addEventListener('click',()=>{if(enabled)apply(button.dataset.previewPreset);}));
        width.addEventListener('change',()=>{if(enabled)apply(width.value);});
        slider.addEventListener('input',()=>{if(enabled)apply(slider.value);});
        preview.querySelector('[data-preview-refresh]').addEventListener('click',()=>{if(enabled && frame.getAttribute('src'))frame.src=frame.src;});
        apply('fit');updateVisibility();
    });
})();