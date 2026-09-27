(() => {
    const form = document.querySelector('[data-voice-recorder]');
    if (!form) return;

    const file = form.querySelector('[data-voice-file]');
    const start = form.querySelector('[data-voice-start]');
    const stop = form.querySelector('[data-voice-stop]');
    const cancel = form.querySelector('[data-voice-cancel]');
    const status = form.querySelector('[data-voice-status]');
    let recorder = null;
    let stream = null;
    let chunks = [];
    let limitTimer = null;

    const stopTracks = () => {
        if (stream) stream.getTracks().forEach((track) => track.stop());
        stream = null;
        clearTimeout(limitTimer);
        limitTimer = null;
    };
    const reset = (message) => {
        stopTracks();
        recorder = null;
        chunks = [];
        start.disabled = false;
        stop.disabled = true;
        cancel.disabled = true;
        status.textContent = message;
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
        try {
            stream = await navigator.mediaDevices.getUserMedia({audio: true});
            chunks = [];
            recorder = new MediaRecorder(stream, {mimeType});
            recorder.addEventListener('dataavailable', (event) => {
                if (event.data.size) chunks.push(event.data);
            });
            recorder.addEventListener('stop', () => {
                if (!chunks.length) return reset('録音Dataを取得できませんでした。');
                const blob = new Blob(chunks, {type: mimeType});
                const extension = mimeType.startsWith('audio/mp4') ? 'm4a' : 'webm';
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], `voice.${extension}`, {type: mimeType}));
                file.files = transfer.files;
                reset('録音を準備しました。保存ボタンを押すまで送信されません。');
            });
            recorder.start(1000);
            start.disabled = true;
            stop.disabled = false;
            cancel.disabled = false;
            status.textContent = '録音中です。3分で自動停止します。';
            limitTimer = setTimeout(() => recorder?.state === 'recording' && recorder.stop(), 180000);
        } catch {
            reset('マイクは許可されませんでした。Text入力はそのまま利用できます。');
        }
    });
    stop.addEventListener('click', () => recorder?.state === 'recording' && recorder.stop());
    cancel.addEventListener('click', () => {
        if (recorder?.state === 'recording') {
            recorder.ondataavailable = null;
            recorder.onstop = null;
            recorder.stop();
        }
        file.value = '';
        reset('録音を取り消しました。');
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && recorder?.state === 'recording') {
            recorder.stop();
            status.textContent = '画面が非表示になったため録音を停止しました。';
        }
    });
    window.addEventListener('pagehide', stopTracks);
})();
