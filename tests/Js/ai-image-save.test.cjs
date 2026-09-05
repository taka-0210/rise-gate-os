const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const png = Uint8Array.from([137,80,78,71,13,10,26,10,1,2,3]);
function setup({permission = 'granted', granted = 'granted', ok = true, body = png, failWrite = false} = {}) {
    const calls = {fetch:0, request:0, writes:0, aborted:0};
    const directory = () => ({
        directories:new Map(), files:new Map(),
        async queryPermission() { return permission; },
        async requestPermission() { calls.request++; return granted; },
        async getDirectoryHandle(name, {create} = {}) {
            if (!this.directories.has(name)) {
                if (!create) throw Object.assign(new Error(), {name:'NotFoundError'});
                this.directories.set(name, directory());
            }
            return this.directories.get(name);
        },
        async getFileHandle(name, {create} = {}) {
            if (!this.files.has(name)) {
                if (!create) throw Object.assign(new Error(), {name:'NotFoundError'});
                this.files.set(name, {bytes:null});
            }
            const file = this.files.get(name);
            return {async createWritable() {
                let pending;
                return {
                    async write(bytes) { calls.writes++; if (failWrite) throw new Error('disk full'); pending = bytes; },
                    async close() { file.bytes = pending; },
                    async abort() { calls.aborted++; },
                };
            }};
        },
    });
    const root = directory();
    const context = vm.createContext({Uint8Array, fetch:async () => {
        calls.fetch++;
        return {ok, arrayBuffer:async () => body.buffer};
    }});
    vm.runInContext(fs.readFileSync('public/js/ai-image-save.js', 'utf8'), context);
    return {api:context.RiseGateImageSave, root, calls};
}

test('creates Japanese folders and writes original image bytes', async () => {
    const {api,root,calls} = setup();
    assert.equal(await api.save(root, 'デザイン案/第1案.png', '/private-image'), 'デザイン案/第1案.png');
    assert.deepEqual(root.directories.get('デザイン案').files.get('第1案.png').bytes, png);
    assert.equal(calls.writes, 1);
});
test('existing image is never overwritten', async () => {
    const {api,root,calls} = setup();
    await api.save(root, '画像/第1案.png', '/private-image');
    await assert.rejects(api.save(root, '画像/第1案.png', '/private-image'), /同名/);
    assert.equal(calls.writes, 1);
});
test('unsafe paths cannot fetch or create directories', async () => {
    const {api,root,calls} = setup();
    for (const path of ['../x.png','/x.png','C:\\x.png','a/../x.png','.git/x.png','a/.env/x.png',
        'vendor/x.png','storage/app/x.png','a//x.png','a/x.svg','a/CON.png','a/x?.png','a /x.png','a./x.png']) {
        await assert.rejects(api.save(root, path, '/image'));
    }
    assert.equal(calls.fetch, 0);
    assert.equal(root.directories.size, 0);
});
test('automatic save waits for permission and click can grant it', async () => {
    const {api,root,calls} = setup({permission:'prompt'});
    await assert.rejects(api.save(root, '画像/a.png', '/image'), /書き込み許可/);
    assert.equal(calls.request, 0);
    assert.equal(calls.fetch, 0);
    await api.save(root, '画像/a.png', '/image', true);
    assert.equal(calls.request, 1);
    assert.equal(calls.writes, 1);
});
test('denied permission makes no filesystem changes', async () => {
    const {api,root,calls} = setup({permission:'prompt', granted:'denied'});
    await assert.rejects(api.save(root, '画像/a.png', '/image', true), /書き込み許可/);
    assert.equal(calls.fetch, 0);
    assert.equal(root.directories.size, 0);
});
test('missing folder gives reconnect guidance', async () => {
    const {api,calls} = setup();
    await assert.rejects(api.save(null, '画像/a.png', '/image'), /Project設定/);
    assert.equal(calls.fetch, 0);
});
test('failed download or non-PNG does not create folders', async () => {
    for (const options of [{ok:false}, {body:Uint8Array.from([1,2,3])}]) {
        const {api,root} = setup(options);
        await assert.rejects(api.save(root, '画像/a.png', '/image'));
        assert.equal(root.directories.size, 0);
    }
});
test('failed write aborts and is not reported as saved', async () => {
    const {api,root,calls} = setup({failWrite:true});
    await assert.rejects(api.save(root, '画像/a.png', '/image'), /書き込みが完了/);
    assert.equal(calls.aborted, 1);
    assert.equal(root.directories.get('画像').files.get('a.png').bytes, null);
});
test('workspace script syntax including direct save button integration', () => {
    const blade = fs.readFileSync('resources/views/projects/workspace.blade.php', 'utf8');
    let script = blade.split('<script>')[1].split('</script>')[0];
    // Blade @json expressions are server values; replace balanced expressions for syntax checking.
    let pos;
    while ((pos = script.indexOf('@json(')) !== -1) {
        let depth = 1, end = pos + 6, quote = null;
        for (; depth && end < script.length; end++) {
            const char = script[end];
            if (quote) { if (char === '\\') end++; else if (char === quote) quote = null; }
            else if (char === "'" || char === '"') quote = char;
            else if (char === '(') depth++;
            else if (char === ')') depth--;
        }
        script = script.slice(0, pos) + 'null' + script.slice(end);
    }
    new vm.Script(script);
    assert.match(script, /appendDirectImageSave\(assistantArticle, message.image_url, message.image_save_url\)/);
});
