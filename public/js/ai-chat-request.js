(() => {
    const expired = 'ログインの有効期限が切れたか、ログイン状態が変わりました。入力文をコピーしてから画面を再読み込みし、ログインを確認して再送してください。';
    const stale = '送信の認証を更新できませんでした。入力文をコピーしてから画面を再読み込みし、再送してください。';
    window.RiseGateChatRequest = {
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
                if (!refresh.ok) throw new Error(stale);
                const session = await refresh.json();
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