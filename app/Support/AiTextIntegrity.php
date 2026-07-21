<?php

namespace App\Support;

class AiTextIntegrity
{
    public const ERROR_MESSAGE = '日本語の文字化けを検出しました。UTF-8のまま送信し、タイトル・概要・変更内容が正しく読めることを確認してください。';

    public static function containsMojibake(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsMojibake($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value)) {
            return false;
        }

        return str_contains($value, "\u{FFFD}") || preg_match('/\?{3,}/u', $value) === 1;
    }
}
