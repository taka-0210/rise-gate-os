/* PC helper bridge. The connection code stays in this browser tab, never in AI requests. */
(() => {
    const encode = bytes => {
        let text = '';
        for (let i = 0; i < bytes.length; i += 8192) text += String.fromCharCode(...bytes.subarray(i, i + 8192));
        return btoa(text);
    };
    const decode = value => Uint8Array.from(atob(value), char => char.charCodeAt(0));
    class Client {
        constructor(project, token, workspace) {
            this.project = project;
            this.token = token;
            this.workspace = workspace;
        }
        async call(action, args = {}) {
            const response = await fetch('http://127.0.0.1:41739/api', {
                method: 'POST', mode: 'cors', credentials: 'omit', cache: 'no-store',
                targetAddressSpace: 'loopback',
                headers: {'Content-Type': 'application/json', 'X-RiseGate-Token': this.token},
                body: JSON.stringify({workspace: this.workspace, ...args, action, project: this.project}),
                signal: AbortSignal.timeout(action === 'select' ? 180000 : action === 'export' ? 120000 : 45000),
            });
            const result = await response.json();
            if (!response.ok) {
                const error = new Error(result.message || '開発用ツールとの通信に失敗しました。');
                if (response.status === 404) error.name = 'NotFoundError';
                if (response.status === 409) error.name = 'InvalidModificationError';
                throw error;
            }
            if (action === 'status' && this.workspace === undefined) this.workspace = result.workspace;
            return result;
        }
        directory(name) { return new Directory(this, '', name); }
    }
    class Handle {
        constructor(client, path, name, kind) {
            this.client = client; this.path = path; this.name = name; this.kind = kind;
        }
        async queryPermission() { return 'granted'; }
        async requestPermission() { return 'granted'; }
    }
    class Directory extends Handle {
        constructor(client, path, name) { super(client, path, name, 'directory'); }
        async *entries() {
            const result = await this.client.call('list', {path: this.path});
            for (const entry of result.entries) {
                const path = this.path ? this.path + '/' + entry.name : entry.name;
                yield [entry.name, entry.kind === 'directory' ? new Directory(this.client, path, entry.name) : new LocalFile(this.client, path, entry.name)];
            }
        }
        async *values() { for await (const [, handle] of this.entries()) yield handle; }
        async child(name, kind, options = {}) {
            if (!name || /[\/\\]/.test(name) || name === '.' || name === '..') throw new Error('ファイル名を確認してください。');
            const path = this.path ? this.path + '/' + name : name;
            let result;
            try { result = await this.client.call('stat', {path}); }
            catch (error) {
                if (error.name !== 'NotFoundError' || !options.create) throw error;
                result = await this.client.call('create', {path, kind});
            }
            if (result.kind !== kind) throw new DOMException('ファイルの種類が違います。', 'TypeMismatchError');
            return kind === 'directory' ? new Directory(this.client, path, name) : new LocalFile(this.client, path, name);
        }
        getDirectoryHandle(name, options) { return this.child(name, 'directory', options); }
        getFileHandle(name, options) { return this.child(name, 'file', options); }
    }
    class LocalFile extends Handle {
        constructor(client, path, name) { super(client, path, name, 'file'); this.hash = undefined; }
        async getFile() {
            const result = await this.client.call('read', {path: this.path});
            this.hash = result.hash;
            return new File([decode(result.content)], this.name, {lastModified: result.modified});
        }
        async createWritable() {
            if (this.hash === undefined) await this.getFile();
            const expected = this.hash;
            let bytes = null;
            let closed = false;
            return {
                write: async content => {
                    if (closed) throw new Error('保存処理は終了しています。');
                    if (typeof content === 'string') bytes = new TextEncoder().encode(content);
                    else if (content instanceof Blob) bytes = new Uint8Array(await content.arrayBuffer());
                    else if (content instanceof ArrayBuffer || ArrayBuffer.isView(content)) bytes = new Uint8Array(content.buffer || content, content.byteOffset || 0, content.byteLength);
                    else throw new Error('保存する内容を読み取れません。');
                },
                close: async () => {
                    if (closed || bytes === null) throw new Error('保存する内容がありません。');
                    closed = true;
                    const result = await this.client.call('write', {path: this.path, content: encode(bytes), expected});
                    this.hash = result.hash;
                },
                abort: async () => { closed = true; bytes = null; },
            };
        }
    }
    window.RiseGateLocalDev = {Client, decode};
})();
