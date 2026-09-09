<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiChatPayload
{
    public static function decode(Request $request): void
    {
        // Original limits are Unicode characters; allow at most four UTF-8 bytes per character.
        $limits = ['content' => 21336, 'file_content' => 5333336, 'project_files' => 5333336];
        $rules = [];
        foreach ($limits as $field => $limit) {
            $rules[$field.'_base64'] = ['sometimes', 'required', 'string', 'max:'.$limit, 'prohibits:'.$field];
        }
        $encoded = $request->validate($rules);
        $decoded = [];
        foreach ($limits as $field => $limit) {
            $key = $field.'_base64';
            if (! array_key_exists($key, $encoded)) {
                continue;
            }
            $value = base64_decode($encoded[$key], true);
            if ($value === false || base64_encode($value) !== $encoded[$key] || ! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([$field => '送信内容を読み取れませんでした。画面を再読み込みしてください。']);
            }
            // File bytes must remain exact for conflict detection and editing.
            $decoded[$field] = $field === 'content' ? trim($value) : $value;
        }
        $request->merge($decoded);
    }
}
