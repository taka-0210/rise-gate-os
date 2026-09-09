(() => {
    const expired = 'ログインの有効期限が切れたか、ログイン状態が変わりました。入力文をコピーしてから画面を再読み込みし、ログインを確認して再送してください。';
    const stale = '送信の認証を更新できませんでした。入力文をコピーしてから画面を再読み込みし、再送してください。';
    const responseError = response => {
        const status = response.status;
        if (status === 413) return '送信内容がサーバーの上限を超えています（HTTP 413）。添付画像や参照ファイルを減らしてください。';
        if (status === 404) return 'AI送信に必要な接続先が見つかりません（HTTP 404）。最新版のデプロイを確認し、入力文をコピーしてから画面を再読み込みしてください。';
        if ([502, 503, 504].includes(status)) return 'サーバーから正常な応答を取得できませんでした（HTTP '+status+'）。処理が続いている可能性があるため、再送前に会話履歴を確認してください。';
        return 'サーバーから想定外の応答が返りました（HTTP '+status+'）。入力文は残しています。再送前に会話履歴を確認してください。';
    };
    const readJson = async response => {
        if (!/\bapplication\/(?:[\w.+-]+\+)?json\b/i.test(response.headers.get('Content-Type') || '')) throw new Error(responseError(response));
        try {
            const body = await response.json();
            if (!body || typeof body !== 'object' || Array.isArray(body)) throw new Error('Invalid response');
            return body;
        } catch { throw new Error(responseError(response)); }
    };
    window.RiseGateChatRequest = {
        readJson,
        async send({url, tokenUrl, userId, payload, token, onToken}, request = fetch) {
            const post = csrf => request(url, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept':'application/json', 'X-CSRF-TOKEN':csrf},
                body: payload,
            });
            let response = await post(token);
            if (response.status === 419) {
                const refresh = await request(tokenUrl, {
                    credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'},
                });
                if (refresh.status === 401 || refresh.redirected) throw new Error(expired);
                if (!refresh.ok) throw new Error(responseError(refresh));
                const session = await readJson(refresh);
                if (String(session.user_id) !== String(userId)) throw new Error(expired);
                if (typeof session.token !== 'string' || !session.token) throw new Error(stale);
                // Laravel checks a form _token before the header; update both.
                payload.set('_token', session.token);
                onToken(session.token);
                response = await post(session.token);
            }
            if (response.status === 401 || response.redirected) throw new Error(expired);
            if (response.status === 419) throw new Error(stale);
            return response;
        },
    };
})();