<?php

namespace App\Services;

use RuntimeException;

class ImageSavePath
{
    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        if (mb_strlen($path) > 240 || count($parts) > 12 || ! preg_match('/\.png$/i', $path)) {
            throw new RuntimeException('保存先は接続フォルダ内のPNGファイルを指定してください。');
        }
        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part, '.') || trim($part) !== $part || str_ends_with($part, '.')
                || preg_match('/[\x00-\x1f\x7f<>:"|?*]/u', $part)
                || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i', $part)
                || in_array(strtolower($part), ['vendor', 'node_modules', 'deploy', 'deployment'], true)) {
                throw new RuntimeException('この保存先には書き込めません。通常のフォルダ名とPNGファイル名を指定してください。');
            }
        }
        if (strtolower($parts[0]) === 'storage' && strtolower($parts[1] ?? '') !== 'content') {
            throw new RuntimeException('保護対象の保存先には書き込めません。');
        }

        return $path;
    }
}
