(() => {
    const normalizePath = value => {
        if (typeof value !== 'string') throw new Error('保存先を指定してください。');
        const path = value.replaceAll('\\', '/');
        const parts = path.split('/');
        if ([...path].length > 240 || parts.length > 12 || !/\.png$/i.test(path)
            || parts.some(part => !part || part.startsWith('.') || part.trim() !== part || part.endsWith('.')
                || /[\u0000-\u001f\u007f<>:"|?*]/u.test(part)
                || /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i.test(part)
                || /^(vendor|node_modules|deploy|deployment)$/i.test(part))
            || (parts[0].toLowerCase() === 'storage' && parts[1]?.toLowerCase() !== 'content')) {
            throw new Error('接続フォルダ内の通常のフォルダ名とPNGファイル名を指定してください。');
        }
        return path;
    };
    const save = async (root, value, imageUrl, askPermission = false) => {
        const path = normalizePath(value);
        if (!root) throw new Error('Project設定でローカルフォルダを選び、FILESを開いてから「フォルダへ保存」を押してください。');
        let permission = await root.queryPermission({mode:'readwrite'});
        if (permission !== 'granted' && askPermission) permission = await root.requestPermission({mode:'readwrite'});
        if (permission !== 'granted') throw new Error('「フォルダへ保存」を押して、ブラウザの書き込み許可を選んでください。');
        const response = await fetch(imageUrl, {credentials:'same-origin'});
        if (!response.ok) throw new Error('保存する画像を取得できませんでした。画像のダウンロードを確認して再度お試しください。');
        const bytes = new Uint8Array(await response.arrayBuffer());
        if (![137,80,78,71,13,10,26,10].every((byte, index) => bytes[index] === byte)) {
            throw new Error('画像の内容を確認できなかったため、保存しませんでした。');
        }
        const write = async () => {
            const parts = path.split('/');
            const name = parts.pop();
            let directory = root;
            for (const part of parts) directory = await directory.getDirectoryHandle(part, {create:true});
            try {
                await directory.getFileHandle(name);
                throw new Error('同名ファイルがあるため保存していません。保存先のファイル名を変えてください。');
            } catch (error) {
                if (error.name !== 'NotFoundError') throw error;
            }
            const handle = await directory.getFileHandle(name, {create:true});
            const stream = await handle.createWritable();
            try {
                await stream.write(bytes);
                await stream.close();
            } catch (error) {
                try { await stream.abort(); } catch {}
                throw new Error('画像の書き込みが完了しませんでした。フォルダの状態を確認して再度お試しください。', {cause:error});
            }
            return path;
        };
        return globalThis.navigator?.locks
            ? navigator.locks.request('rise-gate-image-save:' + path, write)
            : write();
    };
    globalThis.RiseGateImageSave = {normalizePath, save};
})();
