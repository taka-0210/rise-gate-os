<?php

namespace App\Services;

use App\Models\AiChatMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiChatService
{
    public function respond(Collection $messages, array $projectContext, int $userId): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI APIキーが設定されていません。');
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(90)
                ->retry(2, 500, throw: false)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('services.openai.chat_model'),
                    'instructions' => $this->instructions($projectContext),
                    'input' => $messages->map(fn (AiChatMessage $message): array => [
                        'role' => $message->role,
                        'content' => $this->messageContent($message),
                    ])->values()->all(),
                    'reasoning' => ['effort' => 'low'],
                    'text' => $this->textConfiguration($projectContext),
                    'max_output_tokens' => $this->editableFiles($projectContext) === [] ? 1200 : 12000,
                    'store' => false,
                    'safety_identifier' => hash('sha256', 'rise-gate-os-user-'.$userId),
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('AIサービスへ接続できませんでした。時間を置いて再度お試しください。');
        }

        if (! $response->successful()) {
            report(new RuntimeException('OpenAI API error '.$response->status().': '.$response->body()));
            throw new RuntimeException('AIから回答を取得できませんでした。設定または利用残高を確認してください。');
        }

        $data = $response->json();
        $content = collect($data['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->where('type', 'output_text')
            ->pluck('text')
            ->filter()
            ->implode("\n\n");

        if ($content === '') {
            throw new RuntimeException('AIの回答本文を確認できませんでした。');
        }

        $structured = $this->parseFileChange($content, $this->editableFiles($projectContext));
        if ($structured) {
            $content = $structured['answer'];
        }
        $inputTokens = (int) data_get($data, 'usage.input_tokens', 0);
        $outputTokens = (int) data_get($data, 'usage.output_tokens', 0);

        return [
            'content' => $content,
            'provider_response_id' => $data['id'] ?? null,
            'model' => $data['model'] ?? config('services.openai.chat_model'),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_microusd' => (int) round(
                $inputTokens * (float) config('services.openai.input_usd_per_million')
                + $outputTokens * (float) config('services.openai.output_usd_per_million')
            ),
            ...(! empty($structured['path']) ? [
                'file_change_path' => $structured['path'],
                'file_change_content' => $structured['content'],
                'file_change_original_hash' => $structured['original_hash'],
                'file_change_status' => 'pending',
            ] : []),
        ];
    }

    private function textConfiguration(array $projectContext): array
    {
        if ($this->editableFiles($projectContext) === []) {
            return ['verbosity' => 'low'];
        }

        return [
            'verbosity' => 'low',
            'format' => [
                'type' => 'json_schema',
                'name' => 'file_change_proposal',
                'description' => 'A Japanese answer and an optional complete replacement for one supplied project file.',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                        'file_change' => [
                            'anyOf' => [
                                [
                                    'type' => 'object',
                                    'properties' => [
                                        'path' => ['type' => 'string'],
                                        'content' => ['type' => 'string'],
                                    ],
                                    'required' => ['path', 'content'],
                                    'additionalProperties' => false,
                                ],
                                ['type' => 'null'],
                            ],
                        ],
                    ],
                    'required' => ['answer', 'file_change'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function parseFileChange(string $content, array $editableFiles): ?array
    {
        if ($editableFiles === []) {
            return null;
        }
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! is_string($decoded['answer'] ?? null)) {
            return null;
        }
        $change = $decoded['file_change'] ?? null;
        if ($change === null) {
            return ['answer' => $decoded['answer'], 'path' => null];
        }
        if (! is_array($change) || ! is_string($change['path'] ?? null) || ! is_string($change['content'] ?? null)) {
            return null;
        }
        $target = collect($editableFiles)->firstWhere('path', $change['path']);
        if (! $target) {
            return null;
        }
        if (strlen($change['content']) > 1_000_000) {
            return null;
        }

        return [
            'answer' => is_string($decoded['answer'] ?? null) ? $decoded['answer'] : '変更案を作成しました。内容を確認してください。',
            'path' => $target['path'],
            'content' => $change['content'],
            'original_hash' => $target['sha256'],
        ];
    }

    private function messageContent(AiChatMessage $message): string|array
    {
        if ($message->role !== AiChatMessage::ROLE_USER || ! $message->image_path || ! Storage::disk('local')->exists($message->image_path)) {
            return $message->content;
        }

        return [
            ['type' => 'input_text', 'text' => $message->content],
            ['type' => 'input_image', 'image_url' => 'data:'.$message->image_mime.';base64,'.base64_encode(Storage::disk('local')->get($message->image_path))],
        ];
    }

    private function instructions(array $context): string
    {
        $fileChangeInstruction = $this->editableFiles($context) === [] ? '' : <<<'PROMPT'

IMPORTANT: When project_files or open_file is present, inspect the supplied files and return only valid JSON with no Markdown fence:
{"answer":"short Japanese explanation","file_change":{"path":"exact supplied file path","content":"complete updated file content"}}
If no file change is needed, return {"answer":"normal Japanese answer","file_change":null}.
Choose exactly one file from the supplied paths. Never invent or target another path.
PROMPT;
        return <<<'PROMPT'
あなたはRISE GATE OSの読み取り専用AIパートナーです。
提供されたプロジェクト情報だけを事実として扱い、日本語で簡潔かつ具体的に回答してください。
情報が不足している場合は推測で補わず、不足している情報を明示してください。
OSのデータを変更した、保存した、承認したとは決して述べないでください。
変更が必要な場合は、実行せずに提案として説明してください。

現在のプロジェクト情報:
PROMPT."\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).$fileChangeInstruction;
    }

    private function editableFiles(array $context): array
    {
        $files = $context['project_files'] ?? [];
        if (! empty($context['open_file']) && ! collect($files)->contains('path', $context['open_file']['path'])) {
            array_unshift($files, $context['open_file']);
        }

        return $files;
    }
}
