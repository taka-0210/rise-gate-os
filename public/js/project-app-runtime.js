/* The trusted host keeps cookies/CSRF outside the sandboxed application. */
export function installAppBridge({frame, endpoint, csrf, status, hostWindow = window, request = fetch}) {
    let queue = Promise.resolve();
    const listener = event => {
        const message = event.data;
        if (event.source !== frame.contentWindow || event.origin !== 'null'
            || message?.channel !== 'rise-gate-app' || typeof message.id !== 'string'
            || message.id.length > 80 || !['load', 'save'].includes(message.method)) return;
        const source = event.source;
        queue = queue.then(async () => {
            try {
                const options = {credentials:'same-origin', headers:{Accept:'application/json'}};
                if (message.method === 'save') {
                    options.method = 'PUT';
                    options.headers['Content-Type'] = 'application/json';
                    options.headers['X-CSRF-TOKEN'] = csrf;
                    options.body = JSON.stringify({data:message.data, revision:message.revision});
                    if (options.body.length > 500000) throw new Error('保存データが大きすぎます。');
                    status.textContent = 'サーバーへ保存中…';
                }
                const response = await request(endpoint, options);
                let result;
                try { result = await response.json(); } catch { throw new Error('ログイン状態を確認してアプリを開き直してください。'); }
                if (!response.ok) throw new Error(result.message || 'サーバーに接続できませんでした。');
                if (message.method === 'save') {
                    status.textContent = '保存済み ' + new Intl.DateTimeFormat('ja-JP', {timeZone:'Asia/Tokyo', hour:'2-digit', minute:'2-digit', second:'2-digit'}).format(new Date(result.saved_at)) + '（JST）';
                } else {
                    status.textContent = 'サーバーのデータを読み込みました';
                }
                source.postMessage({channel:'rise-gate-app-result', id:message.id, result}, '*');
            } catch (error) {
                status.textContent = error.message;
                source.postMessage({channel:'rise-gate-app-result', id:message.id, error:error.message}, '*');
            }
        });
    };
    hostWindow.addEventListener('message', listener);
    return () => hostWindow.removeEventListener('message', listener);
}

export function appSdk() {
    const pending = new Map();
    let revision = null;
    let sequence = 0;
    let queue = Promise.resolve();
    const call = (method, data) => new Promise((resolve, reject) => {
        const id = String(++sequence);
        const timer = setTimeout(() => { pending.delete(id); reject(new Error('通信を確認してください。保存結果が不明なため、再読込して確認してください。')); }, 30000);
        pending.set(id, {resolve, reject, timer});
        parent.postMessage({channel:'rise-gate-app', id, method, data, revision}, '*');
    });
    addEventListener('message', event => {
        if (event.source !== parent || event.data?.channel !== 'rise-gate-app-result') return;
        const item = pending.get(event.data.id);
        if (!item) return;
        pending.delete(event.data.id);
        clearTimeout(item.timer);
        if (event.data.error) item.reject(new Error(event.data.error));
        else item.resolve(event.data.result);
    });
    const enqueue = operation => {
        const result = queue.then(operation);
        queue = result.catch(() => {});
        return result;
    };
    window.riseGateApp = Object.freeze({
        load: () => enqueue(async () => {
            const result = await call('load');
            revision = result.revision;
            return result;
        }),
        save: data => {
            // Snapshot when called so later UI changes cannot change an already queued save.
            const snapshot = JSON.parse(JSON.stringify(data));
            return enqueue(async () => {
                if (revision === null) throw new Error('最初にサーバーのデータを読み込んでください。');
                try {
                    const result = await call('save', snapshot);
                    revision = result.revision;
                    return result;
                } catch (error) {
                    revision = null;
                    throw error;
                }
            });
        },
    });
}

export function mountProjectApp(frame, html) {
    const policy = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; connect-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
    frame.srcdoc = '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' + policy + '"><script>(' + appSdk.toString() + ')();<\/script>' + html;
}
