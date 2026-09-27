(() => {
    'use strict';
    const root = document.getElementById('shared-session-recorder');
    const presence = document.querySelector('[data-shared-room-snapshot]');
    if (presence) {
        const client = crypto.randomUUID();
        let cursor = Number(presence.dataset.sharedRoomSequence || 0);
        const refresh = async () => {
            if (document.visibilityState !== 'visible') return;
            try {
                const url = new URL(presence.dataset.sharedRoomSnapshot, window.location.origin);
                url.searchParams.set('client_instance_id', client);
                url.searchParams.set('cursor', String(cursor));
                const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
                if (!response.ok) return;
                const state = await response.json();
                cursor = Number(state.sequence || cursor);
                presence.querySelector('[data-presence-state]').textContent = state.presence;
                presence.querySelector('[data-capture-state]').textContent = state.capture;
                presence.querySelector('[data-asr-state]').textContent = state.asr;
                presence.querySelector('[data-context-state]').textContent = state.context;
                presence.querySelector('[data-context-watermark]').textContent = `Context watermark: segment ${state.context_watermark_segment_id ?? 'none'}`;
            } catch (_) {
                // Network loss leaves the last truthful server snapshot visible; no fake progress is inferred.
            }
        };
        refresh();
        window.setInterval(refresh, 5000);
    }
    if (!root) return;

    const startButton = root.querySelector('[data-session-record-start]');
    const stopButton = root.querySelector('[data-session-record-stop]');
    const cancelButton = root.querySelector('[data-session-record-cancel]');
    const status = root.querySelector('[data-session-recorder-status]');
    const csrf = root.dataset.csrf;
    const clientInstanceId = crypto.randomUUID();
    let mediaStream = null;
    let recorder = null;
    let serverStream = null;
    let generationToken = 0;
    let windowSequence = 0;
    let active = false;
    let normalStop = false;
    let timer = null;

    const setStatus = message => { status.textContent = message; };
    const postJson = async (url, body = {}, keepalive = false) => {
        const response = await fetch(url, {
            method: 'POST', credentials: 'same-origin', keepalive,
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || `HTTP ${response.status}`);
        return response.json();
    };
    const postForm = async (url, form) => {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: form});
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || `HTTP ${response.status}`);
        return response.json();
    };
    const stopTracks = () => {
        if (mediaStream) mediaStream.getTracks().forEach(track => track.stop());
        mediaStream = null;
    };
    const streamUrl = action => `${root.dataset.streamBase}/${serverStream.stream_id}/${action}`;

    const startWindow = token => {
        if (!active || token !== generationToken || !mediaStream) return;
        const mimeType = ['audio/webm;codecs=opus', 'audio/mp4'].find(type => MediaRecorder.isTypeSupported(type)) || '';
        const current = new MediaRecorder(mediaStream, mimeType ? {mimeType} : undefined);
        recorder = current;
        const chunks = [];
        current.addEventListener('dataavailable', event => {
            if (token === generationToken && event.data.size > 0) chunks.push(event.data);
        });
        current.addEventListener('stop', async () => {
            clearTimeout(timer);
            if (token !== generationToken) return;
            const shouldContinue = active;
            try {
                if (chunks.length > 0) {
                    windowSequence += 1;
                    const blob = new Blob(chunks, {type: current.mimeType || chunks[0].type || 'audio/webm'});
                    const extension = blob.type.includes('mp4') ? 'm4a' : 'webm';
                    const form = new FormData();
                    form.append('operation_id', crypto.randomUUID());
                    form.append('generation', String(serverStream.generation));
                    form.append('sequence', String(windowSequence));
                    form.append('audio', blob, `shared-window-${windowSequence}.${extension}`);
                    const saved = await postForm(`${streamUrl('windows')}`, form);
                    await postJson(`${root.dataset.windowBase}/${saved.window_id}/transcribe`, {operation_id: crypto.randomUUID()});
                }
                if (shouldContinue && token === generationToken) {
                    setStatus('Recording next bounded window');
                    startWindow(token);
                } else if (normalStop && token === generationToken) {
                    await postJson(streamUrl('stop'));
                    setStatus('Stopped normally. Reload to view the authorized transcript.');
                    stopTracks();
                    normalStop = false;
                    startButton.disabled = false;
                }
            } catch (error) {
                active = false;
                stopTracks();
                startButton.disabled = false;
                stopButton.disabled = true;
                cancelButton.disabled = true;
                setStatus(`Safe stop: ${error.message}`);
            }
        });
        current.start();
        timer = setTimeout(() => { if (current.state === 'recording') current.stop(); }, 55_000);
    };

    startButton.addEventListener('click', async () => {
        try {
            mediaStream = await navigator.mediaDevices.getUserMedia({audio: true});
            serverStream = await postJson(root.dataset.startUrl, {operation_id: crypto.randomUUID(), client_instance_id: clientInstanceId, mode: 'shared_room'});
            generationToken += 1;
            active = true;
            normalStop = false;
            windowSequence = 0;
            startButton.disabled = true;
            stopButton.disabled = false;
            cancelButton.disabled = false;
            setStatus('Recording a bounded window');
            startWindow(generationToken);
        } catch (error) {
            stopTracks();
            setStatus(`Cannot start: ${error.message}`);
        }
    });
    stopButton.addEventListener('click', () => {
        active = false;
        normalStop = true;
        stopButton.disabled = true;
        cancelButton.disabled = true;
        if (recorder?.state === 'recording') recorder.stop();
    });
    cancelButton.addEventListener('click', async () => {
        active = false;
        normalStop = false;
        generationToken += 1;
        clearTimeout(timer);
        if (recorder?.state === 'recording') recorder.stop();
        stopTracks();
        stopButton.disabled = true;
        cancelButton.disabled = true;
        startButton.disabled = false;
        try { await postJson(streamUrl('cancel')); setStatus('Cancelled. Late events are fenced.'); }
        catch (error) { setStatus(`Cancelled locally; server confirmation required: ${error.message}`); }
    });
    const safelyCancel = () => {
        if (!active || !serverStream) return;
        active = false;
        generationToken += 1;
        clearTimeout(timer);
        if (recorder?.state === 'recording') recorder.stop();
        stopTracks();
        postJson(streamUrl('cancel'), {}, true).catch(() => {});
    };
    window.addEventListener('pagehide', safelyCancel);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') safelyCancel(); });
})();
