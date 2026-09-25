<?php

namespace App\Services\ActionExecution;

use App\Contracts\ActionDraftProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiActionDraftProvider implements ActionDraftProvider
{
    public function suggest(array $payload): string
    {
        $key = config('services.openai.api_key');
        if (! $key) throw new RuntimeException('AI Providerが設定されていません。手入力で続行できます。');
        $prompt = implode("\n", [
            '日本語の簡潔な発信文案を1つ作成し、文案本文だけを返してください。',
            '発信先の説明: '.$payload['target'],
            'Action title: '.$payload['action_title'],
            'Done Condition: '.$payload['done_condition'],
            '文案指示: '.$payload['instruction'],
            '送信済み、外部予約済み、実施済みとは表現しないでください。',
        ]);
        $response = Http::withToken($key)->timeout(30)->post('https://api.openai.com/v1/responses', [
            'model' => config('services.openai.chat_model'), 'input' => $prompt,
        ]);
        if (! $response->successful()) throw new RuntimeException('AI文案生成に失敗しました。手入力で続行できます。');
        $data = $response->json();
        $text = trim((string) ($data['output_text'] ?? ''));
        if ($text === '') {
            foreach ($data['output'] ?? [] as $item) foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text') $text .= (string) ($content['text'] ?? '');
            }
            $text = trim($text);
        }
        if ($text === '') throw new RuntimeException('AIから文案を取得できませんでした。手入力で続行できます。');
        return $text;
    }
}
