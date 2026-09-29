(() => {
    'use strict';
    const root = document.getElementById('shared-session-recorder');
    const presence = document.querySelector('[data-shared-room-snapshot]');
    if (!root && !presence) return;

    const clientId = crypto.randomUUID();
    const csrf = root?.dataset.csrf;
    const snapshotUrl = root?.dataset.snapshotUrl || presence?.dataset.sharedRoomSnapshot;
    let cursor = Number(root?.dataset.sharedRoomSequence || presence?.dataset.sharedRoomSequence || 0);
    let state = null;
    let sendChain = Promise.resolve();

    const node = selector => root?.querySelector(selector);
    const controls = {start: node('[data-session-record-start]'), stop: node('[data-session-record-stop]'), cancel: node('[data-session-record-cancel]')};
    const labels = {status: node('[data-session-recorder-status]'), capture: node('[data-realtime-capture]'), relay: node('[data-realtime-relay]'), provider: node('[data-realtime-provider]'), transcript: node('[data-realtime-transcript]'), partial: node('[data-realtime-partial]'), finals: node('[data-realtime-finals]')};
    const post = async (url, body = {}, keepalive = false) => {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', keepalive, headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || `HTTP ${response.status}`);
        return response.json();
    };
    const label = (key, value) => { if (labels[key]) labels[key].textContent = value; };
    const setControls = active => {
        if (!root) return;
        controls.start.disabled = active || root.dataset.realtimeEnabled !== 'true';
        controls.stop.disabled = !active;
        controls.cancel.disabled = !active;
    };
    const stopTracks = () => {
        state?.media?.getTracks().forEach(track => track.stop());
    };
    const cleanup = async (mode, notify = true) => {
        const current = state;
        if (!current || current.closing) return;
        current.closing = true;
        current.processor?.port.postMessage({type: 'active', value: false});
        clearInterval(current.leaseTimer);
        cancelAnimationFrame(current.waveformFrame);
        if (current.socket?.readyState === WebSocket.OPEN) {
            const stopped = new Promise(resolve => {
                const timeout = setTimeout(resolve, mode === 'normal_stop' ? 12000 : 1000);
                current.resolveRelayStop = () => { clearTimeout(timeout); resolve(); };
            });
            current.socket.send(JSON.stringify({type: mode === 'normal_stop' ? 'stop' : 'cancel'}));
            await stopped;
            current.socket.close(1000, mode);
        }
        current.source?.disconnect();
        current.processor?.disconnect();
        current.gain?.disconnect();
        await current.context?.close().catch(() => {});
        stopTracks();
        state = null;
        setControls(false);
        label('capture', 'stopped'); label('relay', 'disconnected'); label('provider', 'not connected');
        if (labels.partial) labels.partial.textContent = '';
        if (notify && current.serverStream) {
            await post(`${root.dataset.streamBase}/${current.serverStream.stream_id}/${mode === 'normal_stop' ? 'stop' : 'cancel'}`, {}, mode !== 'normal_stop');
        }
        label('status', mode === 'normal_stop' ? 'Stopped normally. Final evidence is server-authoritative.' : 'Cancelled. Late events are fenced.');
    };
    const fail = async error => {
        await cleanup('cancel').catch(() => {});
        label('status', `Safe stop: ${error.message}`);
        label('provider', 'unavailable');
    };
    const drawWaveform = current => {
        const canvas = node('[data-session-waveform]');
        if (!canvas || !current.analyser) return;
        const values = new Uint8Array(current.analyser.fftSize);
        const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
        let last = 0;
        const draw = timestamp => {
            if (state !== current || current.closing) return;
            if (!reduced || timestamp - last > 500) {
                last = timestamp;
                current.analyser.getByteTimeDomainData(values);
                const context = canvas.getContext('2d');
                context.fillStyle = '#0f172a'; context.fillRect(0, 0, canvas.width, canvas.height);
                context.strokeStyle = '#38bdf8'; context.lineWidth = 2; context.beginPath();
                values.forEach((value, index) => {
                    const x = index * canvas.width / (values.length - 1);
                    const y = value * canvas.height / 256;
                    if (index === 0) context.moveTo(x, y); else context.lineTo(x, y);
                });
                context.stroke();
            }
            current.waveformFrame = requestAnimationFrame(draw);
        };
        current.waveformFrame = requestAnimationFrame(draw);
    };
    const sha256 = async buffer => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))).map(byte => byte.toString(16).padStart(2, '0')).join('');
    const sendFrame = (current, frame) => {
        sendChain = sendChain.then(async () => {
            if (state !== current || current.closing || current.socket.readyState !== WebSocket.OPEN) throw new Error('Realtime relay is not open.');
            const metadata = {
                type: 'audio_frame', lease_id: current.lease.lease_id, stream_id: current.serverStream.stream_id,
                generation: current.serverStream.generation, sequence: ++current.sequence, client_event_id: crypto.randomUUID(),
                start_sample: frame.startSample, end_sample: frame.endSample, sample_count: frame.endSample - frame.startSample,
                sample_rate: 16000, bit_depth: 16, channels: 1, format: 'pcm_s16le', content_sha256: await sha256(frame.pcm),
            };
            current.socket.send(JSON.stringify(metadata));
            current.socket.send(frame.pcm);
        }).catch(fail);
    };
    const relayMessage = event => {
        if (typeof event.data !== 'string') return;
        let message;
        try { message = JSON.parse(event.data); } catch (_) { return; }
        if (message.type === 'partial') {
            label('transcript', 'partial / ephemeral');
            label('partial', message.content || '');
            document.querySelectorAll('[data-realtime-target-provider-session]').forEach(input => { input.value = message.provider_session_id || ''; });
            document.querySelectorAll('[data-realtime-target-receive-order]').forEach(input => { input.value = String(message.expected_final_receive_order || ''); });
        } else if (message.type === 'final_candidate') {
            label('transcript', 'final validating');
        } else if (message.type === 'durable_final') {
            label('partial', ''); label('transcript', 'durable final');
            if (labels.finals) { const item = document.createElement('li'); item.textContent = message.content || ''; labels.finals.append(item); }
        } else if (message.type === 'provider_state') {
            label('provider', message.state || 'unknown');
        } else if (message.type === 'relay_stopped') {
            state?.resolveRelayStop?.();
        } else if (message.type === 'rejected') {
            label('partial', ''); label('transcript', `rejected: ${message.safe_reason_code || 'unverified'}`);
        }
    };
    const start = async () => {
        if (state || root.dataset.realtimeEnabled !== 'true') return;
        setControls(true); label('status', 'Requesting one continuous microphone stream…');
        const current = {closing: false, sequence: 0, leaseTimer: null, waveformFrame: null};
        state = current;
        try {
            current.media = await navigator.mediaDevices.getUserMedia({audio: {channelCount: 1, echoCancellation: true, noiseSuppression: true}, video: false});
            current.serverStream = await post(root.dataset.startUrl, {operation_id: crypto.randomUUID(), client_instance_id: clientId, mode: 'shared_room'});
            current.lease = await post(`${root.dataset.streamBase}/${current.serverStream.stream_id}/lease`);
            current.socket = new WebSocket(current.lease.relay_url);
            current.socket.binaryType = 'arraybuffer';
            current.socket.addEventListener('message', relayMessage);
            current.socket.addEventListener('close', event => {
                if (!current.closing) fail(new Error('Realtime relay closed unexpectedly (' + event.code + ').'));
            });
            current.media.getTracks().forEach(track => track.addEventListener('ended', () => {
                if (!current.closing) fail(new Error('Microphone device ended or permission was revoked.'));
            }, {once: true}));
            await new Promise((resolve, reject) => {
                current.socket.addEventListener('open', resolve, {once: true});
                current.socket.addEventListener('error', () => reject(new Error('Authorized WSS relay connection failed.')), {once: true});
            });
            current.context = new AudioContext({sampleRate: 16000, latencyHint: 'interactive'});
            await current.context.audioWorklet.addModule(root.dataset.workletUrl);
            current.source = current.context.createMediaStreamSource(current.media);
            current.analyser = current.context.createAnalyser(); current.analyser.fftSize = 256;
            current.processor = new AudioWorkletNode(current.context, 'company-os-realtime-processor');
            current.gain = current.context.createGain(); current.gain.gain.value = 0;
            current.source.connect(current.analyser); current.source.connect(current.processor); current.processor.connect(current.gain).connect(current.context.destination);
            current.processor.port.onmessage = event => { if (event.data?.type === 'frame') sendFrame(current, event.data); };
            current.processor.port.postMessage({type: 'active', value: true});
            current.leaseTimer = setInterval(() => post(`${root.dataset.leasesBase}/${current.lease.lease_id}/refresh`).catch(fail), current.lease.refresh_seconds * 1000);
            label('capture', 'continuous'); label('relay', 'authorized WSS'); label('provider', 'connecting'); label('transcript', 'listening'); label('status', 'Realtime capture active.');
            drawWaveform(current);
        } catch (error) { await fail(error); }
    };

    document.querySelectorAll('[data-realtime-co-form]').forEach(form => form.addEventListener('submit', () => {
        const provider = form.querySelector('[data-realtime-target-provider-session]');
        const order = form.querySelector('[data-realtime-target-receive-order]');
        const hasTarget = Boolean(provider?.value && order?.value);
        if (provider) provider.disabled = !hasTarget;
        if (order) order.disabled = !hasTarget;
        label('partial', '');
        label('transcript', hasTarget ? 'waiting for target final' : 'fixed durable snapshot');
    }));
    controls.start?.addEventListener('click', start);
    controls.stop?.addEventListener('click', () => cleanup('normal_stop').catch(fail));
    controls.cancel?.addEventListener('click', () => cleanup('cancel').catch(fail));
    const abandon = () => { if (state) cleanup('cancel', true).catch(() => {}); };
    window.addEventListener('pagehide', abandon);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') abandon(); });

    const refresh = async () => {
        if (!snapshotUrl || document.visibilityState !== 'visible') return;
        try {
            const url = new URL(snapshotUrl, location.origin); url.searchParams.set('client_instance_id', clientId); url.searchParams.set('cursor', String(cursor));
            const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok) return;
            const snapshot = await response.json(); cursor = Number(snapshot.sequence || cursor);
            if (presence) {
                presence.querySelector('[data-presence-state]').textContent = snapshot.presence;
                presence.querySelector('[data-capture-state]').textContent = snapshot.capture;
                presence.querySelector('[data-asr-state]').textContent = snapshot.asr;
                presence.querySelector('[data-context-state]').textContent = snapshot.context;
                presence.querySelector('[data-context-watermark]').textContent = `Context watermark: segment ${snapshot.context_watermark_segment_id ?? 'none'}`;
            }
        } catch (_) { if (!state) label('status', 'Server state unavailable; capture remains disabled until verified.'); }
    };
    refresh(); setInterval(refresh, 5000);
})();
