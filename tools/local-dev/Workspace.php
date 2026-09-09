<?php
declare(strict_types=1);

namespace RiseGate\LocalDev;

use RuntimeException;

/** The helper only operates on a folder selected on this PC. No OS server paths. */
final class Workspace
{
    public function __construct(public readonly string $root)
    {
        if (!is_dir($root) || realpath($root) !== $root) {
            throw new RuntimeException('保存フォルダが見つかりません。選び直してください。');
        }
    }

    public function path(string $relative, bool $internal = false): string
    {
        if (strlen($relative) > 500 || preg_match('/[\\\\:\x00-\x1f<>"|?*]/', $relative)) {
            throw new RuntimeException('使用できないパスです。');
        }
        $current = $this->root;
        foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
                || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])($|\.)/i', $part)) {
                throw new RuntimeException('フォルダ外のパスは使用できません。');
            }
            if (!$internal && (preg_match('/^(\.env($|\.)|\.git$|vendor$|node_modules$|data$|private$)/i', $part)
                || preg_match('/\.(sqlite3?|db|pem|key)$/i', $part))) {
                throw new RuntimeException('データ・秘密情報は直接編集できません。');
            }
            $current .= DIRECTORY_SEPARATOR.$part;
            if (is_link($current)) {
                throw new RuntimeException('リンク先にはアクセスできません。');
            }
            if (file_exists($current)) {
                $resolved = realpath($current);
                if ($resolved === false || !str_starts_with(strtolower($resolved.DIRECTORY_SEPARATOR), strtolower($this->root.DIRECTORY_SEPARATOR))) {
                    throw new RuntimeException('フォルダ外にはアクセスできません。');
                }
            }
        }
        return $current;
    }

    public function listing(string $relative): array
    {
        $path = $this->path($relative);
        if (!is_dir($path)) {
            throw new RuntimeException('フォルダがありません。', 404);
        }
        $entries = [];
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            try { $entry = $this->path(ltrim($relative.'/'.$name, '/')); }
            catch (RuntimeException) { continue; }
            $entries[] = ['name' => $name, 'kind' => is_dir($entry) ? 'directory' : 'file'];
            if (count($entries) >= 2000) break;
        }
        return $entries;
    }

    public function read(string $relative): array
    {
        $path = $this->path($relative);
        if (!is_file($path)) throw new RuntimeException('ファイルがありません。', 404);
        if (filesize($path) > 16_000_000) throw new RuntimeException('16MBを超えるファイルは扱えません。');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('読み取りに失敗しました。');
        return ['content' => base64_encode($bytes), 'hash' => hash('sha256', $bytes), 'modified' => filemtime($path) * 1000];
    }

    public function write(string $relative, string $encoded, ?string $expected): array
    {
        $path = $this->path($relative);
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) > 16_000_000) throw new RuntimeException('ファイルの内容またはサイズが不正です。');
        if (!is_dir(dirname($path))) throw new RuntimeException('保存先フォルダがありません。');
        // x+b never truncates an unexpected existing file.
        $new = !file_exists($path);
        $stream = @fopen($path, $new ? 'x+b' : 'r+b');
        if (!$stream || !flock($stream, LOCK_EX)) throw new RuntimeException('ファイルをロックできません。');
        try {
            $old = stream_get_contents($stream);
            if (($new && $expected !== null) || (!$new && ($expected === null || !hash_equals($expected, hash('sha256', $old))))) {
                throw new RuntimeException('ファイルが変更されています。最新内容を読み直してください。', 409);
            }
            rewind($stream);
            if (fwrite($stream, $bytes) !== strlen($bytes) || !ftruncate($stream, strlen($bytes))) {
                rewind($stream);
                fwrite($stream, $old);
                ftruncate($stream, strlen($old));
                throw new RuntimeException('保存できませんでした。');
            }
            fflush($stream);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
            clearstatcache(true, $path);
        }
        return ['hash' => hash('sha256', $bytes)];
    }

    public function create(string $relative, string $kind): array
    {
        $path = $this->path($relative);
        if ($kind === 'directory') {
            if (!is_dir($path) && !mkdir($path, 0700)) throw new RuntimeException('フォルダを作成できません。');
        } else {
            if (!is_file($path)) {
                $f = @fopen($path, 'x');
                if (!$f) throw new RuntimeException('ファイルを作成できません。');
                fclose($f);
            }
        }
        return ['kind' => is_dir($path) ? 'directory' : 'file'];
    }

    public function export(string $destination): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new RuntimeException('公開用ZIPを作成できません。');
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
            $count = 0;
            $size = 0;
            foreach ($iterator as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->root) + 1));
                if (preg_match('~(^|/)(\.rise-gate|\.git|\.env[^/]*|data|private|node_modules|vendor)(/|$)|\.(sqlite3?|db|log|pem|key)$~i', $relative)) continue;
                $path = $this->path($relative);
                if (!$file->isFile()) continue;
                $size += $file->getSize();
                if (++$count > 10000 || $size > 20_000_000) throw new RuntimeException('公開用ファイルは20MB・1万件までです。');
                $zip->addFile($path, $relative);
            }
            $zip->addFromString('RISE-GATE-PUBLISH.txt', "公開先はPHP 8.2以降とPDO SQLiteに対応したサーバーを使用してください。\npublic/を公開ディレクトリに設定します。DB・秘密情報・開発履歴はZIPに含みません。\n初回はSETUP.mdの手順で独立した管理者を作成してください。\n更新時はdata/と既存のDBを保持してください。開発用PHPサーバーは本番では使用しません。\n時刻はAsia/Tokyoです。\n");
        } finally { $zip->close(); }
    }
}
