    const devControls = workbench.querySelector('[data-development-controls]');
    let devClient = null;
    const devKey = 'rise-gate-dev-' + @json($project->public_id);
    const devStatus = text => { if (devControls) devControls.querySelector('[data-dev-status]').textContent = text; };
    const devShowState = async state => {
        const changedFolder = !localDevelopmentConnected || devClient.workspace !== state.workspace;
        if (changedFolder) {
            for (const tab of tabs.querySelectorAll('[data-workspace-tab]')) {
                if (tab.dataset.tabKind !== 'document') {
                    if (tab.dataset.tabUrl?.startsWith('blob:')) URL.revokeObjectURL(tab.dataset.tabUrl);
                    tab.remove();
                }
            }
            tabs.querySelector('[data-workspace-tab="project"]')?.click();
            setChatFileContext('', '');
        }
        devClient = new RiseGateLocalDev.Client(devClient.project, devClient.token, state.workspace);
        devControls.querySelector('[data-dev-connected]').hidden = false;
        devControls.querySelector('[data-dev-folder]').textContent = state.folder || '保存フォルダ未選択';
        const repository = workbench.querySelector('.file-repository');
        if (repository) { repository.firstChild.textContent = '▣ ' + (state.folder || '保存フォルダ未選択'); repository.querySelector('span').textContent = 'PC接続・PHP / SQLite'; }
        localDevelopmentConnected = true;
        localSiteUrl = state.url || '';
        if (state.folder) {
            localDirectoryHandle = devClient.directory(state.folder);
            await renderLocalDirectory(localDirectoryHandle, localTree);
        } else { localDirectoryHandle = null; localTree.replaceChildren(); }
        const open = devControls.querySelector('[data-dev-open]');
        open.hidden = !state.url;
        if (state.url) open.href = state.url;
        devStatus('接続済み：PHP ' + state.php + ' / SQLite / JST' + (state.url ? '（起動中）' : ''));
    };
    const devConnect = async token => {
        if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('接続コードを確認してください。');
        const candidate = new RiseGateLocalDev.Client(@json($project->public_id), token);
        const state = await candidate.call('status');
        if (!state.sqlite) throw new Error('SQLiteが利用できません。セットアップを再実行してください。');
        devClient = candidate;
        await devShowState(state);
        sessionStorage.setItem(devKey, token);
    };
    devControls?.addEventListener('click', async event => {
        const button = event.target.closest('[data-dev-action]');
        if (!button) return;
        const action = button.dataset.devAction;
        const dialog = devControls.querySelector('[data-dev-dialog]');
        if (action === 'connect' || action === 'seed') {
            dialog.dataset.action = action;
            devControls.querySelector('[data-dev-title]').textContent = action === 'connect' ? '開発用ツールに接続' : '独立したTODOを作成';
            devControls.querySelector('[data-dev-code-label]').hidden = action !== 'connect';
            devControls.querySelector('[data-dev-admin]').hidden = action !== 'seed';
            const form = devControls.querySelector('[data-dev-form]');
            form.reset();
            form.elements.code.required = action === 'connect';
            form.elements.login.required = form.elements.password.required = action === 'seed';
            devControls.querySelector('[data-dev-dialog-error]').textContent = '';
            dialog.showModal();
            return;
        }
        button.disabled = true;
        try {
            if (!devClient) throw new Error('先に開発用ツールへ接続してください。');
            if (action === 'disconnect') {
                await devClient.call('stop');
                sessionStorage.removeItem(devKey);
                devClient = null; localDirectoryHandle = null; localSiteUrl = ''; localDevelopmentConnected = false;
                localTree.replaceChildren();
                devControls.querySelector('[data-dev-connected]').hidden = true;
                devStatus('接続を解除しました。ファイルとDBは残っています。');
                return;
            }
            if (action === 'ask') {
                const text = devControls.querySelector('[data-dev-logs]').textContent.slice(-3000);
                chatForm.elements.content.value = 'ローカル実行時の次のエラーを、プロジェクトのファイルを確認して修正してください。接続情報やパスワードを新たに書き込まないでください。\n' + text;
                chatForm.elements.content.focus();
                devStatus('修正依頼を入力欄に入れました。ログの内容を確認して送信してください。');
                return;
            }
            devStatus(action === 'select' ? 'Windowsのフォルダ選択画面で保存先を選んでください…' : '処理しています…');
            const result = await devClient.call(action);
            if (action === 'logs') {
                devControls.querySelector('[data-dev-logs-panel]').hidden = false;
                devControls.querySelector('[data-dev-logs-panel]').open = true;
                devControls.querySelector('[data-dev-logs]').textContent = result.text;
                devStatus('実行ログを取得しました。');
                return;
            }
            if (action === 'export') {
                const url = URL.createObjectURL(new Blob([RiseGateLocalDev.decode(result.content)], {type:'application/zip'}));
                const a = document.createElement('a'); a.href = url; a.download = result.name; a.click();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
                devStatus('公開用ZIPを出力しました。DB・アカウントは含みません。SETUP.mdで公開手順を確認してください。');
                return;
            }
            await devShowState(await devClient.call('status'));
            if (action === 'start') showBrowserPreview(result.url, 'ローカルアプリ');
        } catch (error) {
            devStatus(error.message === 'Failed to fetch' ? '開発用ツールを起動し、ブラウザのローカルネットワーク接続を許可してください。' : error.message);
        } finally { button.disabled = false; }
    });
    devControls?.querySelector('[data-dev-form]').addEventListener('submit', async event => {
        if (event.submitter?.value === 'cancel') return;
        event.preventDefault();
        const form = event.currentTarget;
        const dialog = form.closest('dialog');
        const button = event.submitter;
        button.disabled = true;
        try {
            if (dialog.dataset.action === 'connect') await devConnect(form.elements.code.value.trim());
            else {
                if (!devClient) throw new Error('先に開発用ツールへ接続してください。');
                await devClient.call('seed', {login:form.elements.login.value, password:form.elements.password.value});
                await devShowState(await devClient.call('status'));
                devStatus('TODOのひな形と管理者を作成しました。「起動」で確認できます。');
            }
            form.reset(); dialog.close();
        } catch (error) {
            devControls.querySelector('[data-dev-dialog-error]').textContent = error.message === 'Failed to fetch' ? '開発用ツールを起動し、ブラウザのローカルネットワーク接続を許可してください。' : error.message;
        } finally { button.disabled = false; }
    });
    if (devControls && sessionStorage.getItem(devKey)) devConnect(sessionStorage.getItem(devKey)).catch(() => devStatus('開発用ツールを起動して「接続」を押してください。'));
