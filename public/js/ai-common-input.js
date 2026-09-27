(() => {
    const form = document.querySelector('[data-voice-recorder]');
    if (!form) return;

    const file = form.querySelector('[data-voice-file]');
    const start = form.querySelector('[data-voice-start]');
    const stop = form.querySelector('[data-voice-stop]');
    const cancel = form.querySelector('[data-voice-cancel]');
    const status = form.querySelector('[data-voice-status]');
    let generation = 0;
    let activeSession = null;

    const stopSession = (session) => {
        if (!session) return;
        session.stream?.getTracks().forEach((track) => track.stop());
        session.stream = null;
        clearTimeout(session.limitTimer);
        session.limitTimer = null;
    };
    const reset = (message, session = null) => {
        if (session && activeSession !== session) return;
        stopSession(session);
        if (!session || activeSession === session) activeSession = null;
        start.disabled = false;
        stop.disabled = true;
        cancel.disabled = true;
        status.textContent = message;
    };
    const isCurrent = (session) => activeSession === session && !session.cancelled && !session.finished;
    const cancelSession = (message = null) => {
        const session = activeSession;
        if (session) {
            session.cancelled = true;
            activeSession = null;
            clearTimeout(session.limitTimer);
            session.limitTimer = null;
            if (session.recorder?.state === 'recording') session.recorder.stop();
            stopSession(session);
        }
        file.value = '';
        start.disabled = false;
        stop.disabled = true;
        cancel.disabled = true;
        if (message !== null) status.textContent = message;
    };
    const supportedType = () => [
        'audio/webm;codecs=opus',
        'audio/mp4;codecs=mp4a.40.2',
        'audio/webm',
        'audio/mp4',
    ].find((type) => window.MediaRecorder?.isTypeSupported(type));

    start.addEventListener('click', async () => {
        const mimeType = supportedType();
        if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder || !mimeType) {
            status.textContent = 'このBrowserでは録音を利用できません。Voice Fileを選択してください。';
            return;
        }
        const session = {
            generation: ++generation,
            recorder: null,
            stream: null,
            chunks: [],
            limitTimer: null,
            cancelled: false,
            finished: false,
        };
        activeSession = session;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({audio: true});
            if (!isCurrent(session)) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }
            session.stream = stream;
            session.recorder = new MediaRecorder(stream, {mimeType});
            session.recorder.addEventListener('dataavailable', (event) => {
                if (isCurrent(session) && event.data.size) session.chunks.push(event.data);
            });
            session.recorder.addEventListener('stop', () => {
                if (!isCurrent(session)) return;
                session.finished = true;
                if (!session.chunks.length) return reset('録音Dataを取得できませんでした。', session);
                const blob = new Blob(session.chunks, {type: mimeType});
                const extension = mimeType.startsWith('audio/mp4') ? 'm4a' : 'webm';
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], `voice.${extension}`, {type: mimeType}));
                file.files = transfer.files;
                reset('録音を準備しました。保存ボタンを押すまで送信されません。', session);
            });
            session.recorder.start(1000);
            start.disabled = true;
            stop.disabled = false;
            cancel.disabled = false;
            status.textContent = '録音中です。3分で自動停止します。';
            session.limitTimer = setTimeout(() => {
                if (isCurrent(session) && session.recorder?.state === 'recording') session.recorder.stop();
            }, 180000);
        } catch {
            reset('マイクは許可されませんでした。Text入力はそのまま利用できます。', session);
        }
    });
    stop.addEventListener('click', () => {
        const session = activeSession;
        if (isCurrent(session) && session.recorder?.state === 'recording') session.recorder.stop();
    });
    cancel.addEventListener('click', () => cancelSession('録音を取り消しました。'));
    document.addEventListener('visibilitychange', () => {
        const session = activeSession;
        if (document.hidden && isCurrent(session) && session.recorder?.state === 'recording') {
            session.recorder.stop();
            stopSession(session);
            status.textContent = '画面が非表示になったため録音を停止しました。';
        }
    });
    window.addEventListener('pagehide', () => cancelSession(null));
})();
