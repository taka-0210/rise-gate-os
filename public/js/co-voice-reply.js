/* Local Japanese voices only. No provider, audio persistence or automatic playback. */
(() => {
    'use strict';
    const synth = window.speechSynthesis;
    const panels = [...document.querySelectorAll('[data-co-voice-reply]')];
    let active = null;
    let generation = 0;
    const localVoice = () => synth?.getVoices().find(v => v.localService === true && /^ja(?:-|$)/i.test(v.lang));
    const status = (panel, text) => { panel.querySelector('[data-voice-status]').textContent = text; };
    function stop(text = '停止しました。') {
        generation++;
        if (active) {
            clearInterval(active.timer);
            active.abort?.abort();
            active.panel.querySelector('[data-voice-stop]').disabled = true;
            status(active.panel, text);
        }
        active = null;
        synth?.cancel();
    }
    function available() {
        const voice = localVoice();
        panels.forEach(panel => {
            panel.querySelector('[data-voice-start]').disabled = !voice;
            panel.querySelector('[data-voice-replay]').disabled = !voice;
            if (!voice) status(panel, '端末内の日本語音声が利用できません。テキストで確認してください。');
            else if (active?.panel !== panel) status(panel, '端末内日本語音声で再生できます。');
        });
        if (active && !voice) stop('ローカル音声が利用できなくなったため停止しました。');
    }
    async function authorize(run) {
        if (active !== run || document.hidden || !navigator.onLine) throw new Error('unavailable');
        const controller = new AbortController();
        run.abort = controller;
        const timeout = setTimeout(() => controller.abort(), 3000);
        try {
            const response = await fetch(run.panel.dataset.accessUrl, {
                credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: { Accept: 'application/json' }, signal: controller.signal
            });
            if (!response.ok) throw new Error('access');
            const data = await response.json();
            if (active !== run || data.allowed !== true || data.content_hash !== run.panel.dataset.contentHash) throw new Error('access');
        } finally {
            clearTimeout(timeout);
            if (run.abort === controller) run.abort = null;
        }
    }
    async function next(run) {
        try {
            await authorize(run);
            if (active !== run || run.generation !== generation || document.hidden) return;
            const voice = localVoice();
            if (!voice) throw new Error('voice');
            const text = run.parts.shift();
            if (!text) { stop('再生が終了しました。'); return; }
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.voice = voice;
            utterance.lang = voice.lang;
            utterance.rate = 1;
            utterance.onend = () => { if (active === run) next(run); };
            utterance.onerror = () => { if (active === run) stop('音声を再生できません。テキストで確認してください。'); };
            run.utterance = utterance;
            status(run.panel, '読み上げ中（端末内日本語音声）');
            synth.speak(utterance);
        } catch (_) {
            if (active === run) stop('権限または通信を確認できないため停止しました。');
        }
    }
    function start(panel) {
        if (active?.panel === panel) return; // Ignore double taps, including while authorizing.
        stop();
        if (!localVoice() || document.hidden) { available(); return; }
        // The immediately preceding paragraph is the already-authorized answer body only.
        const text = panel.previousElementSibling?.textContent || '';
        const parts = (text.match(/[^。！？\n]+[。！？\n]?/gu) || [text])
            .flatMap(sentence => Array.from(sentence).reduce((chunks, char, index) => {
                if (index % 80 === 0) chunks.push('');
                chunks[chunks.length - 1] += char;
                return chunks;
            }, []));
        const run = { panel, parts, generation, checking: false };
        active = run;
        panel.querySelector('[data-voice-stop]').disabled = false;
        status(panel, '再生権限を確認中…');
        run.timer = setInterval(async () => {
            if (active !== run || run.checking) return;
            run.checking = true;
            try { await authorize(run); }
            catch (_) { if (active === run) stop('権限または通信を確認できないため停止しました。'); }
            finally { run.checking = false; }
        }, 2000);
        next(run);
    }
    panels.forEach(panel => {
        panel.querySelector('[data-voice-start]').addEventListener('click', () => start(panel));
        panel.querySelector('[data-voice-replay]').addEventListener('click', () => { stop(); start(panel); });
        panel.querySelector('[data-voice-stop]').addEventListener('click', () => stop());
    });
    synth?.addEventListener('voiceschanged', available);
    available();
    document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
    window.addEventListener('pagehide', () => stop());
    window.addEventListener('beforeunload', () => stop());
    window.addEventListener('offline', () => stop('通信が切れたため停止しました。'));
    document.addEventListener('submit', () => stop(), true);
    document.addEventListener('click', event => { if (event.target.closest('a[href]')) stop(); }, true);
})();
