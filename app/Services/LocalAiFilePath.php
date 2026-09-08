<?php

namespace App\Services;

use RuntimeException;

class LocalAiFilePath
{
    public static function normalize(string $path): string
    {
        $parts = explode('/', $path);
        if (strlen($path) > 500 || preg_match('/[\\\\:\x00-\x1f<>"|?*]/', $path)
            || collect($parts)->contains(fn (string $part): bool => $part === '' || $part === '.' || $part === '..'
                || preg_match('/[. ]$/', $part)
                || preg_match('/^(?:\.env(?:$|\.)|\.git$|\.rise-gate$|vendor$|node_modules$|deploy$|deployment$|CON(?:\.|$)|PRN(?:\.|$)|AUX(?:\.|$)|NUL(?:\.|$)|COM[1-9](?:\.|$)|LPT[1-9](?:\.|$))/i', $part))
            || (preg_match('~^storage(/|$)~i', $path) && ! preg_match('~^storage/content/~i', $path))) {
            throw new RuntimeException('この保存先は使用できません。接続フォルダ内の通常ファイルを指定してください。');
        }

        return $path;
    }
}
