    const canManageApps = @json(auth()->user()->can('update', $project));
    const appsUrl = @json(route('projects.apps.index', $project));
    const appList = workbench.querySelector('[data-project-app-list]');
    const appStatus = workbench.querySelector('[data-project-app-status]');
    const appDialog = workbench.querySelector('[data-project-app-dialog]');
    const appForm = workbench.querySelector('[data-project-app-form]');
    let projectApps = [];
    let activeServerApp = null;
    const serverAppSources = new Map();
    const getActiveServerApp = () => {
        const path = workbench.querySelector('[data-chat-file-path]').value;
        const id = path.match(/^server-apps\/([^/]+)\/index\.html$/)?.[1];
        return id ? serverAppSources.get(id) || null : null;
    };
    let appDraft = null;
    const appRequest = async (url, options = {}) => {
        const response = await fetch(url, {
            ...options, headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':@json(csrf_token())},
        });
        let body;
        try { body = await response.json(); } catch { throw new Error('ログイン状態を確認して画面を再読込してください。'); }
        if (!response.ok) throw new Error(body.message || 'アプリを保存できませんでした。');
        return body;
    };
    const openServerApp = async app => {
        activeServerApp = null; setChatFileContext();
        ensureTab({id:'app:' + app.id, kind:'browser', key:app.name, label:app.name, url:app.run_url});
        showBrowserPreview(app.run_url, app.name);
        if (canManageApps) {
            try {
                const {app:source} = await appRequest(app.source_url);
                if (!tabs.querySelector('[data-workspace-tab="app:' + app.id + '"]')?.classList.contains('is-current')) return;
                activeServerApp = source; serverAppSources.set(source.id, source);
                setChatFileContext('server-apps/' + source.id + '/index.html', source.html);
                workbench.querySelector('[data-chat-context-key]').value = 'app:' + source.id;
                workbench.querySelector('[data-chat-context-label]').value = source.name + ' / サーバー保存アプリ';
                workbench.querySelector('[data-ai-context]').textContent = source.name + ' / サーバー保存アプリ';
            } catch (error) { appStatus.textContent = error.message; }
        }
    };
    const refreshProjectApps = async () => {
        const body = await appRequest(appsUrl);
        projectApps = body.apps;
        appList.replaceChildren();
        for (const app of projectApps) {
            const row = document.createElement('div'); row.style.marginBottom = '10px';
            const open = document.createElement('button'); open.type = 'button'; open.textContent = app.name;
            open.onclick = () => openServerApp(app);
            const link = document.createElement('a'); link.href = app.run_url; link.target = '_blank'; link.rel = 'noopener'; link.textContent = '別タブで開く';
            row.append(open, document.createTextNode(' '), link);
            if (canManageApps) {
                const edit = document.createElement('button'); edit.type = 'button'; edit.textContent = 'ソースを開く';
                edit.onclick = async () => {
                    try {
                        const {app:source} = await appRequest(app.source_url);
                        activeServerApp = source;
                        serverAppSources.set(source.id, source);
                        const path = 'server-apps/' + source.id + '/index.html';
                        ensureTab({id:'file:' + path, kind:'file', key:path, label:source.name + '.html', content:source.html});
                        setChatFileContext(path, source.html); setFilePreviewTitle(path);
                        setLocalBrowserActions(path); renderCode(source.html); showViewer('file');
                        workbench.querySelector('[data-chat-context-key]').value = 'file:' + path;
                        workbench.querySelector('[data-chat-context-label]').value = source.name + ' / サーバー保存アプリ';
                        workbench.querySelector('[data-ai-context]').textContent = source.name + ' / サーバー保存アプリ';
                    } catch (error) { appStatus.textContent = error.message; }
                };
                row.append(document.createTextNode(' '), edit);
            }
            appList.append(row);
        }
    };
    const updateAppTarget = () => {
        const target = projectApps.find(app => app.id === appForm.elements.target.value);
        const existing = !!target;
        workbench.querySelector('[data-app-admin-fields]').hidden = existing;
        for (const name of ['admin_login', 'admin_password']) {
            appForm.elements[name].required = !existing;
            appForm.elements[name].disabled = existing;
        }
        if (target) appForm.elements.name.value = target.name;
        appDraft.target = target ? {...target, version:appDraft.source?.id === target.id ? appDraft.source.version : target.version} : null;
    };
    const openAppRegistration = (html, source = null, template = null) => {
        if (!canManageApps) return;
        appDraft = {html, source, template};
        appForm.reset();
        const select = appForm.elements.target; select.replaceChildren(new Option('新しいアプリ', ''));
        for (const app of projectApps) select.add(new Option(app.name, app.id));
        select.disabled = !!template;
        select.value = source?.id || '';
        appForm.elements.name.value = source?.name || (template ? 'TODO管理' : '新しいアプリ');
        updateAppTarget();
        workbench.querySelector('[data-app-register-status]').textContent = '';
        appDialog.showModal();
    };
    appForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = appForm.querySelector('[type=submit]');
        if (submit.disabled) return;
        submit.disabled = true;
        const status = workbench.querySelector('[data-app-register-status]');
        status.textContent = '保存中…';
        try {
            const target = appDraft.target;
            const payload = {name:appForm.elements.name.value};
            if (appDraft.template) payload.template = appDraft.template;
            else payload.html = appDraft.html;
            if (target) {
                payload.version = target.version;
                if (appDraft.source?.id === target.id && appDraft.source.original_hash) payload.original_hash = appDraft.source.original_hash;
            }
            else {
                payload.admin_login = appForm.elements.admin_login.value;
                payload.admin_password = appForm.elements.admin_password.value;
            }
            const body = await appRequest(target?.update_url || appsUrl, {method:target ? 'PUT' : 'POST', body:JSON.stringify(payload)});
            appForm.elements.admin_password.value = '';
            appDialog.close();
            if (activeServerApp?.id === body.app.id) activeServerApp = {...body.app, html:appDraft.html};
            appStatus.textContent = 'アプリを保存しました。「別タブで開く」から専用アカウントで利用できます。';
            await refreshProjectApps();
            await openServerApp(body.app);
        } catch (error) { status.textContent = error.message; }
        finally { submit.disabled = false; }
    });
    appForm?.elements.target.addEventListener('change', updateAppTarget);
    appDialog?.addEventListener('close', () => { appForm.elements.admin_password.value = ''; });
    workbench.addEventListener('click', event => {
        if (event.target.closest('[data-create-todo-app]')) openAppRegistration('', null, 'todo');
        if (event.target.closest('[data-cancel-app-register]')) appDialog.close();
        if (event.target.closest('[data-register-app]')) {
            const path = workbench.querySelector('[data-file-title]').dataset.filePath;
            if (!/\.html?$/i.test(path)) return;
            const content = workbench.querySelector('[data-chat-file-content]').value;
            openAppRegistration(content, getActiveServerApp());
        }
        const proposal = event.target.closest('[data-file-change]');
        if (event.target.closest('[data-register-proposal-app]') && proposal) {
            const id = proposalPath(proposal).match(/^server-apps\/([^/]+)\/index\.html$/)?.[1];
            const app = projectApps.find(item => item.id === id);
            const source = proposal.serverAppSource || (app ? {...app, original_hash:proposal.querySelector('[data-file-change-apply]')?.dataset.originalHash} : null);
            openAppRegistration(proposalContent(proposal), source);
        }
    });
    refreshProjectApps().catch(error => { appStatus.textContent = error.message; });
