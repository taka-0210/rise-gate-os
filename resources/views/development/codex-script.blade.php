    const codexPanel = workbench.querySelector('[data-codex-panel]');
    let codexClient = null, codexTimer = null, codexPolling = false, codexLastHistory = '', codexLastApprovals = '';
    let codexWasRunning = false;
    const codexError = text => {
        const node = codexPanel.querySelector('[data-codex-error]');
        node.textContent = text; node.hidden = !text;
    };
    const codexRender = state => {
        const labels = {connecting:'Codexへ接続しています…',login:'Codexへのログインが必要です。',ready:'Codexに接続済み',running:'Codexが作業しています…',failed:'Codexへの再接続が必要です。'};
        codexPanel.querySelector('[data-codex-status]').textContent = (labels[state.phase] || state.phase) + (state.authenticated ? '（' + (state.accountType === 'chatgpt' ? 'ChatGPT' : state.accountType === 'apiKey' ? 'OpenAI API' : state.accountType) + '）' : '');
        codexPanel.querySelector('[data-codex-auth]').hidden = state.authenticated || state.phase === 'connecting';
        codexPanel.querySelector('[data-codex-stop]').disabled = state.phase !== 'running';
        codexPanel.querySelector('[data-codex-form] button').disabled = state.phase !== 'ready';
        codexPanel.querySelector('[data-codex-connect]').disabled = ['connecting','running'].includes(state.phase);
        codexError(state.error || '');
        const link = codexPanel.querySelector('[data-codex-login-link]');
        const loginUrl = state.authUrl ? new URL(state.authUrl) : null;
        const validLogin = loginUrl?.protocol === 'https:' && (loginUrl.hostname === 'auth.openai.com' || loginUrl.hostname === 'auth0.openai.com' || loginUrl.hostname === 'chatgpt.com');
        link.hidden = !validLogin;
        if (validLogin) link.href = loginUrl.href;
        const serialized = JSON.stringify(state.history);
        if (serialized !== codexLastHistory) {
            codexLastHistory = serialized;
            const list = codexPanel.querySelector('[data-codex-history]');
            list.replaceChildren();
            for (const item of state.history || []) {
                const node = document.createElement(item.role === 'tool' ? 'details' : 'article');
                node.style.cssText = 'padding:10px;margin:8px 0;border:1px solid #d6e1e6;border-radius:8px;font-size:12px';
                const label = document.createElement(item.role === 'tool' ? 'summary' : 'strong');
                label.textContent = ({user:'あなた',assistant:'Codex',tool:'実行・ファイル変更',status:'作業状況'})[item.role] || 'Codex';
                const text = document.createElement('div'); text.style.whiteSpace='pre-wrap'; text.textContent=item.text;
                const time = document.createElement('small');
                time.textContent = new Date(item.time).toLocaleString('ja-JP',{timeZone:'Asia/Tokyo'})+' JST';
                node.append(label,text,time); list.append(node);
            }
            list.scrollTop=list.scrollHeight;
        }
        const approvals = JSON.stringify(state.approvals);
        if (approvals !== codexLastApprovals) {
            codexLastApprovals=approvals;
            const list=codexPanel.querySelector('[data-codex-approvals]'); list.replaceChildren();
            for (const approval of state.approvals || []) {
                const card=document.createElement('section'); card.className='ai-context';
                const title=document.createElement('strong');title.textContent='Codexからの確認';card.append(title);
                if (approval.method === 'item/tool/requestUserInput') {
                    const form=document.createElement('form');
                    for(const question of approval.params.questions || []) {
                        const label=document.createElement('label');label.textContent=question.question;
                        const input=document.createElement('input');input.name=question.id;input.type=question.isSecret?'password':'text';input.required=true;input.maxLength=4000;
                        label.append(input);form.append(label);
                    }
                    const submit=document.createElement('button');submit.textContent='回答する';form.append(submit);
                    form.addEventListener('submit',async event=>{
                        event.preventDefault();submit.disabled=true;
                        try {codexRender(await codexClient.call('codex_approve',{id:approval.id,answers:Object.fromEntries(new FormData(form))}));}
                        catch(error){codexError(error.message);submit.disabled=false;}
                    });card.append(form);
                } else {
                    const text=document.createElement('pre');text.style.cssText='white-space:pre-wrap;overflow-wrap:anywhere';
                    text.textContent = [approval.params.reason,approval.params.command || approval.preview?.command,approval.params.cwd,approval.params.grantRoot,approval.preview?.changes ? JSON.stringify(approval.preview.changes,null,2) : ''].filter(Boolean).join('\n');
                    card.append(text);
                    for(const [decision,label] of [['accept','今回の操作を許可'],['decline','許可しない']]) {
                        const button=document.createElement('button');button.textContent=label;button.type='button';
                        button.addEventListener('click',async()=>{
                            card.querySelectorAll('button').forEach(b=>b.disabled=true);
                            try{codexRender(await codexClient.call('codex_approve',{id:approval.id,decision}));}
                            catch(error){codexError(error.message);card.querySelectorAll('button').forEach(b=>b.disabled=false);}
                        });card.append(button);
                    }
                }
                list.append(card);
            }
        }
        if(codexWasRunning && state.phase==='ready' && localDirectoryHandle) {
            renderLocalDirectory(localDirectoryHandle,localTree).catch(error=>codexError(error.message));
        }
        codexWasRunning=state.phase==='running';
    };
    const codexPoll=async()=>{
        if(codexPolling || !codexClient || (codexPanel.hidden && !codexWasRunning)) return;
        codexPolling=true;
        const client=codexClient;
        try{const state=await client.call('codex_poll');if(client===codexClient)codexRender(state);}
        catch(error){codexError(error.message);}
        finally{codexPolling=false;}
    };
    const openCodex=()=>{
        if(!codexPanel) return;
        codexPanel.hidden=false;codexPanel.style.display='';
        workbench.querySelector('[data-ai-standard]').style.display='none';
        workbench.querySelector('[data-mobile-pane="ai"]')?.click();
        codexPanel.querySelector('[name="prompt"]').focus();
    };
    codexPanel?.querySelector('[data-codex-back]').addEventListener('click',()=>{
        codexPanel.hidden=true;codexPanel.style.display='none';
        workbench.querySelector('[data-ai-standard]').style.display='';
    });
    codexPanel?.querySelector('[data-codex-connect]').addEventListener('click',async()=>{
        try{
            if(!devClient || !localDirectoryHandle) throw new Error('FILESの開発ツールで、接続と保存フォルダの選択を行ってください。');
            const helperState=await devClient.call('status');
            if(!String(helperState.version).startsWith('2.'))throw new Error('PCの開発ツールを更新してください。最新のセットアップを実行し、起動し直してください。');
            codexClient=new RiseGateLocalDev.Client(devClient.project,devClient.token,devClient.workspace);
            codexRender(await codexClient.call('codex_connect'));
            clearInterval(codexTimer);codexTimer=setInterval(codexPoll,1500);
        }catch(error){codexError(error.message);}
    });
    codexPanel?.querySelector('[data-codex-login]').addEventListener('click',async()=>{
        try{codexRender(await codexClient.call('codex_login',{type:'chatgpt'}));}
        catch(error){codexError(error.message);}
    });
    codexPanel?.querySelector('[data-codex-key-form]').addEventListener('submit',async event=>{
        event.preventDefault();const input=event.currentTarget.elements.apiKey;const apiKey=input.value;input.value='';
        try{codexRender(await codexClient.call('codex_login',{type:'apiKey',apiKey}));}
        catch(error){codexError(error.message);}
    });
    codexPanel?.querySelector('[data-codex-stop]').addEventListener('click',async()=>{
        try{codexRender(await codexClient.call('codex_interrupt'));}
        catch(error){codexError(error.message);}
    });
    codexPanel?.querySelector('[data-codex-form]').addEventListener('submit',async event=>{
        event.preventDefault();const form=event.currentTarget;
        if(form.dataset.sending==='true')return;
        form.dataset.sending='true';form.querySelector('button').disabled=true;
        const prompt=form.elements.prompt.value.trim();
        try{
            if(!codexClient)throw new Error('先にCodexへ接続してください。');
            codexRender(await codexClient.call('codex_send',{prompt,requestId:crypto.randomUUID()}));
            form.elements.prompt.value='';
        }catch(error){codexError(error.message);form.querySelector('button').disabled=false;}
        finally{delete form.dataset.sending;}
    });
    workbench.addEventListener('development-folder-changed',()=>{
        clearInterval(codexTimer);codexClient=null;codexWasRunning=false;codexLastHistory='';codexLastApprovals='';
        if(!codexPanel)return;
        codexPanel.querySelector('[data-codex-history]').replaceChildren();
        codexPanel.querySelector('[data-codex-approvals]').replaceChildren();
        codexPanel.querySelector('[data-codex-form] button').disabled=true;
        codexPanel.querySelector('[data-codex-status]').textContent='選択したフォルダでCodexに接続してください。';
    });