// Run with: node tests/JavaScript/ai-file-save.test.mjs
export async function testAiFileSaving(source) {
    const extract = (start, end) => {
        const from = source.indexOf('    const ' + start + ' =');
        const to = source.indexOf('    const ' + end + ' =', from);
        if (from < 0 || to < 0) throw new Error('Missing workspace function: ' + start);
        return source.slice(from, to).replaceAll('@json(csrf_token())', '"test-token"');
    };
    const code = extract('validateAiFilePath', 'getNestedDirectoryHandle')
        + extract('readProposalFile', 'openFileChangeDiff')
        + extract('applyFileChange', 'reviseFileChange');
    const assert = (ok, message) => { if (!ok) throw new Error(message); };
    const setup = (files = {}, permission = 'granted', reportOk = true) => {
        const entries = new Map(Object.entries(files));
        const events = [];
        const missing = () => Object.assign(new Error('missing'), {name:'NotFoundError'});
        const directory = prefix => ({
            queryPermission: async () => permission,
            getDirectoryHandle: async (name, {create} = {}) => {
                const path = prefix + name + '/';
                if (!create && ![...entries.keys()].some(key => key.startsWith(path))) throw missing();
                if (create) events.push('mkdir:' + path);
                return directory(path);
            },
            getFileHandle: async (name, {create} = {}) => {
                const path = prefix + name;
                if (!entries.has(path)) {
                    if (!create) throw missing();
                    entries.set(path, '');
                    events.push('create:' + path);
                }
                return {
                    getFile: async () => ({text:async () => entries.get(path), lastModified:1}),
                    createWritable: async () => ({
                        write:async text => { events.push('write:' + path); entries.set(path, text); },
                        close:async () => { events.push('close:' + path); },
                    }),
                };
            },
        });
        const root = directory('');
        const status = {textContent:''};
        const apply = {dataset:{filePath:'todo/index.html', originalHash:'', applyUrl:'/applied'}, textContent:'Apply'};
        const proposal = {
            dataset:{}, classList:{add: value => events.push(value)},
            querySelector: selector => ({
                '[data-file-change-status]':status,
                '[data-file-change-actions]':{remove:() => events.push('actions-removed')},
                summary:{textContent:''},
            })[selector],
        };
        const dependencies = {
            localDirectoryHandle:root,
            showWorkbenchNotice:() => {},
            requestLocalWritePermission:async () => permission,
            confirm:() => { throw new Error('Automatic save must not ask for confirmation'); },
            workbenchNotice:{},
            matchesFileHash:async (text, expected) => text === expected,
            saveLocalBackup:async (path, content) => events.push('backup:' + content),
            createPhysicalBackup:async (path, content) => events.push('physical-backup:' + content),
            preserveLineEndings: text => text,
            proposalContent:() => '<html>TODO</html>',
            workbench:{querySelectorAll:() => [], querySelector:() => ({dataset:{}})},
            tabs:{querySelector:() => null},
            CSS:{escape: value => value},
            renderCode:() => {},
            setFilePreviewTitle:() => {},
            fetch:async () => { events.push('report'); return {ok:reportOk}; },
            renderLocalDirectory:async () => events.push('refresh'),
            localTree:{},
            markFileUpdated:async () => {},
            refreshLocalChangeHistory:async () => {},
            activeDiffProposal:null,
        };
        const api = new Function(...Object.keys(dependencies), code + '; return {applyFileChange, validateAiFilePath, readProposalFile};')(...Object.values(dependencies));
        return {...api, entries, events, root, apply, proposal, status};
    };
    let t = setup();
    await t.applyFileChange(t.proposal, t.apply, true, t.root);
    assert(t.entries.get('todo/index.html') === '<html>TODO</html>', 'Creates nested new file');
    assert(t.events.includes('mkdir:todo/'), 'Creates parent folder');
    assert(t.events.indexOf('report') > t.events.indexOf('close:todo/index.html'), 'Reports only after close');
    assert(!t.events.some(e => e.startsWith('backup:')), 'No fictional original backup for new file');
    assert(t.status.textContent.includes('新規作成・保存'), 'Shows saved result');

    t = setup({'todo/index.html':'user data'});
    await t.applyFileChange(t.proposal, t.apply, true, t.root);
    assert(t.entries.get('todo/index.html') === 'user data' && !t.events.includes('report'), 'Never overwrites unread existing file');

    t = setup({}, 'denied');
    await t.applyFileChange(t.proposal, t.apply, true, t.root);
    assert(t.entries.size === 0 && !t.events.includes('report'), 'Denied permission prevents writes');
    assert(t.apply.disabled === false, 'Permission failure leaves manual retry');

    t = setup({'todo/index.html':'changed'});
    t.apply.dataset.originalHash = 'original';
    await t.applyFileChange(t.proposal, t.apply, true, t.root);
    assert(t.entries.get('todo/index.html') === 'changed' && !t.events.includes('report'), 'Concurrent edits prevent overwrite');

    t = setup({'todo/index.html':'original'});
    t.apply.dataset.originalHash = 'original';
    await t.applyFileChange(t.proposal, t.apply, true, t.root);
    assert(t.events.indexOf('physical-backup:original') < t.events.indexOf('write:todo/index.html'), 'Backs up before updating');
    assert(t.events.includes('is-applied'), 'Existing update completes');

    t = setup();
    await t.applyFileChange(t.proposal, t.apply, true, {});
    assert(t.entries.size === 0, 'Folder switch prevents save');

    t = setup({}, 'granted', false);
    await t.applyFileChange(t.proposal, t.apply, true, t.root);
    assert(t.entries.get('todo/index.html') === '<html>TODO</html>' && t.apply.disabled, 'History failure does not allow duplicate writes');
    assert(!t.events.includes('is-applied'), 'History failure does not claim recorded completion');

    t = setup();
    for (const path of ['../x', '/x', 'C:/x', 'a\\x', '.env', 'a/.env.local', '.git/config', 'storage/logs/x', 'a/NUL.txt', 'a//x', 'a/../x']) {
        let rejected = false;
        try { t.validateAiFilePath(path); } catch { rejected = true; }
        assert(rejected, 'Rejects unsafe path: ' + path);
    }
    for (const path of ['index.html', 'TODO/一覧.html', 'storage/content/todo.json']) t.validateAiFilePath(path);
    return '8 browser file-saving scenarios passed';
}

// CLI runner
if (typeof process !== 'undefined' && process.argv[1]?.endsWith('ai-file-save.test.mjs')) {
    const {readFile} = await import('node:fs/promises');
    console.log(await testAiFileSaving(await readFile(new URL('../../resources/views/projects/workspace.blade.php', import.meta.url), 'utf8')));
}
