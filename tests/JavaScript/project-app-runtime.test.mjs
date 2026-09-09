// Run: node tests/JavaScript/project-app-runtime.test.mjs
export async function testProjectAppRuntime(source) {
    const {installAppBridge, appSdk, mountProjectApp} = new Function(source.replace(/^export /gm, '') + '; return {installAppBridge, appSdk, mountProjectApp};')();
    const assert = (condition, message) => { if (!condition) throw new Error(message); };
    const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };
    const replies = [], requests = [];
    const child = {postMessage: (message, origin) => replies.push({message, origin})};
    let listener, removed = false, fail = false;
    const host = {addEventListener:(name, fn) => { listener = fn; }, removeEventListener:() => { removed = true; }};
    const status = {};
    const stop = installAppBridge({
        frame:{contentWindow:child}, endpoint:'/apps/test/data?account=7', csrf:'host-only-secret', status,
        hostWindow:host,
        request:async (url, options) => {
            requests.push({url, options});
            return {ok:!fail, json:async () => fail ? {message:'conflict'} : {revision:1, saved_at:'2026-09-09T12:00:00+09:00', data:{todos:[]}}};
        },
    });
    const send = (data, source = child, origin = 'null') => listener({source, origin, data});
    const message = {channel:'rise-gate-app', id:'1', method:'load'};
    send(message, {}, 'null'); send(message, child, 'https://evil.example'); send({...message, method:'delete'});
    await flush(); assert(requests.length === 0, 'Rejects foreign windows, non-sandbox origins and unsupported methods');
    send(message); await flush();
    assert(requests.length === 1 && requests[0].url === '/apps/test/data?account=7', 'Reads only fixed app/account endpoint');
    assert(!JSON.stringify(replies).includes('host-only-secret'), 'CSRF token is never passed into generated app');
    send({...message, id:'2', method:'save', data:{todos:['one']}, revision:0, url:'/company/finance'});
    await flush();
    assert(requests[1].url === '/apps/test/data?account=7', 'Caller cannot replace endpoint');
    assert(requests[1].options.headers['X-CSRF-TOKEN'] === 'host-only-secret', 'Host authenticates state-changing call');
    assert(status.textContent.includes('12:00:00') && status.textContent.includes('JST'), 'Saved time is displayed in JST');
    fail = true;
    send({...message, id:'3', method:'save', data:{}, revision:0}); await flush();
    assert(replies.at(-1).message.error === 'conflict' && status.textContent === 'conflict', 'Conflict is reported as failure');
    const count = requests.length;
    send({...message, id:'4', method:'save', data:{text:'x'.repeat(500001)}, revision:0}); await flush();
    assert(requests.length === count, 'Oversized payload never reaches network');
    stop(); assert(removed, 'Bridge listener can be removed');

    const frame = {}; mountProjectApp(frame, '<h1>App</h1>');
    assert(frame.srcdoc.includes("connect-src 'none'") && frame.srcdoc.includes("form-action 'none'"), 'Sandbox document blocks direct network and forms');
    assert(frame.srcdoc.indexOf('riseGateApp') < frame.srcdoc.indexOf('<h1>App</h1>'), 'SDK is installed before generated script');

    const sent = [], timers = new Map(), appWindow = {};
    const parent = {postMessage: message => sent.push(message)};
    let receiver, timerId = 0;
    new Function('window', 'parent', 'addEventListener', 'setTimeout', 'clearTimeout', '(' + appSdk.toString() + ')();')(
        appWindow, parent, (name, callback) => { receiver = callback; },
        callback => { timers.set(++timerId, callback); return timerId; }, id => timers.delete(id),
    );
    const api = appWindow.riseGateApp;
    let failed = false;
    await api.save({todos:[]}).catch(() => { failed = true; });
    assert(failed && sent.length === 0, 'Save requires a successful load');
    const load = api.load(); await flush();
    const answer = (result, error) => receiver({source:parent, data:{channel:'rise-gate-app-result', id:sent.at(-1).id, result, error}});
    receiver({source:{}, data:{channel:'rise-gate-app-result', id:sent.at(-1).id, result:{revision:99}}});
    answer({revision:3, data:{todos:[]}}); await load;
    const input = {todos:['original']};
    const first = api.save(input); input.todos[0] = 'changed after call';
    const second = api.save({todos:['next']}); await flush();
    assert(sent.at(-1).data.todos[0] === 'original' && sent.at(-1).revision === 3, 'Save snapshots data and uses loaded revision');
    const before = sent.length;
    answer({revision:4}); await first; await flush();
    assert(sent.length === before + 1 && sent.at(-1).revision === 4, 'Rapid saves are serialized with latest revision');
    const secondResult = second.catch(error => error.message);
    answer(null, 'stale'); assert(await secondResult === 'stale', 'SDK exposes save failure');
    const after = sent.length;
    await api.save({todos:[]}).catch(() => {});
    assert(sent.length === after, 'Failed write requires reload before more writes');
    assert(timers.size === 0, 'Completed request timers are cleared');
    return '12 independent app runtime scenarios passed';
}

// CLI runner
if (typeof process !== 'undefined' && process.argv[1]?.endsWith('project-app-runtime.test.mjs')) {
    const {readFile} = await import('node:fs/promises');
    console.log(await testProjectAppRuntime(await readFile(new URL('../../public/js/project-app-runtime.js', import.meta.url), 'utf8')));
}
