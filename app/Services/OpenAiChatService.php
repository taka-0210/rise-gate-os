<?php

namespace App\Services;

use App\Models\AiChatMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiChatService
{
    public function respond(Collection $messages, array $projectContext, int $userId, bool $generateImage = false): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('OpenAI APIキーが設定されていません。');
        }

        $projectContext['reference_images'] = $messages->filter(fn (AiChatMessage $message) => $message->image_path)
            ->map(fn (AiChatMessage $message): array => ['message_id' => $message->id, 'description' => $message->content, 'name' => $message->image_name])->values()->all();
        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(300)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => config('services.openai.chat_model'),
                    'instructions' => $this->instructions($projectContext),
                    'tools' => [app(OpenAiImageService::class)->tool(), $this->imageSaveTool()],
                    ...($generateImage ? ['tool_choice' => ['type' => 'function', 'name' => 'generate_image']] : []),
                    'input' => $messages->flatMap(fn (AiChatMessage $message): array => $this->inputMessages($message))->values()->all(),
                    'reasoning' => ['effort' => 'low'],
                    'text' => $this->textConfiguration($projectContext),
                    'max_output_tokens' => $this->editableFiles($projectContext) === [] && empty($projectContext['local_file_access']) ? 1200 : 12000,
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
        if (($data['status'] ?? null) === 'incomplete') {
            throw new RuntimeException('生成が途中で止まったため保存していません。小さい単位に分けて再度依頼してください。');
        }
        $content = collect($data['output'] ?? [])
            ->where('type', 'message')
            ->flatMap(fn (array $item) => $item['content'] ?? [])
            ->where('type', 'output_text')
            ->pluck('text')
            ->filter()
            ->implode("\n\n");

        $generatedImage = collect($data['output'] ?? [])->first(fn (array $item): bool => ($item['type'] ?? null) === 'image_generation_call' && ($item['status'] ?? null) === 'completed'
        );
        $imageCalls = collect($data['output'] ?? [])->filter(fn (array $item): bool => ($item['type'] ?? null) === 'function_call' && ($item['name'] ?? null) === 'generate_image'
        );
        if ($imageCalls->count() > 1) {
            throw new RuntimeException('画像は1回に1枚ずつ生成してください。');
        }
        if ($imageCalls->isNotEmpty()) {
            $generatedImage = app(OpenAiImageService::class)->generate($imageCalls->first(), $messages);
        }
        $reply = json_decode($content, true);
        $suggestedImageName = $generatedImage['image_name'] ?? (is_string($reply['image_name'] ?? null) ? $reply['image_name'] : null);
        $imageSave = $this->parseImageSave($data['output'] ?? [], $messages->last()->ai_chat_thread_id, (bool) $generatedImage);
        if ($imageSave) {
            $content = '画像の保存先を準備しました。ブラウザでフォルダへの保存を進めます。';
        }
        if ($content === '' && $generatedImage) {
            $content = '画像を生成しました。';
        }
        if ($content === '') {
            throw new RuntimeException('AIの回答本文を確認できませんでした。');
        }

        $structured = $this->parseFileChange($content, $this->editableFiles($projectContext), ! empty($projectContext['local_file_access']));
        if ($structured) {
            $content = $structured['answer'];
        }
        $inputTokens = (int) data_get($data, 'usage.input_tokens', 0);
        $outputTokens = (int) data_get($data, 'usage.output_tokens', 0);

        return [
            ...($generatedImage ? $this->saveGeneratedImage($generatedImage, $messages->last()->ai_chat_thread_id, ImageSavePath::suggestedName($suggestedImageName, $content === '画像を生成しました。' ? $messages->last()->content : $content)) : []),
            'content' => $content,
            'image_save' => $imageSave,
            'provider_response_id' => $data['id'] ?? null,
            'model' => $data['model'] ?? config('services.openai.chat_model'),
            'image_input_tokens' => $generatedImage ? AiChatUsage::tokens($generatedImage['usage'] ?? null, 'input_tokens') : null,
            'image_output_tokens' => $generatedImage ? AiChatUsage::tokens($generatedImage['usage'] ?? null, 'output_tokens') : null,
            'provider_usage' => ['chat' => $data['usage'] ?? null, 'image' => $generatedImage['usage'] ?? null, 'image_model' => $generatedImage['model'] ?? null],
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

    private function imageSaveTool(): array
    {
        return [
            'type' => 'function',
            'name' => 'save_generated_image',
            'description' => 'ユーザーが生成画像の保存を依頼した場合だけ、ローカル接続フォルダ内にフォルダを作りPNGを保存する操作を準備する。既存画像の保存では再生成しない。',
            'strict' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'source_message_id' => ['type' => 'integer', 'description' => 'generated_imagesのmessage_id。今回新しく生成した画像のみ0を指定。'],
                    'path' => ['type' => 'string', 'description' => '接続フォルダからの相対パス。例: デザイン案/第1案.png。ユーザー指定を優先し、指定がなければ会話の内容・画像の特徴に合う日本語の名前を提案する。例: 画像/お団子中身見えるパターン.png。'],
                ],
                'required' => ['source_message_id', 'path'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function parseImageSave(array $output, int $threadId, bool $hasNewImage): ?array
    {
        $call = collect($output)->first(fn (array $item): bool => ($item['type'] ?? null) === 'function_call' && ($item['name'] ?? null) === 'save_generated_image'
        );
        if (! $call) {
            return null;
        }
        $args = json_decode($call['arguments'] ?? '', true);
        if (! is_array($args) || ! is_int($args['source_message_id'] ?? null) || ! is_string($args['path'] ?? null)) {
            throw new RuntimeException('画像の保存指示を読み取れませんでした。保存先を指定して再度依頼してください。');
        }
        $sourceId = $args['source_message_id'];
        if ($sourceId === 0 && ! $hasNewImage) {
            throw new RuntimeException('保存する生成画像がありません。先に画像を生成してください。');
        }
        if ($sourceId !== 0) {
            $source = AiChatMessage::where('ai_chat_thread_id', $threadId)->where('role', AiChatMessage::ROLE_ASSISTANT)->find($sourceId);
            if (! $source?->image_path || $source->image_mime !== 'image/png' || ! Storage::disk('local')->exists($source->image_path)) {
                throw new RuntimeException('保存する生成画像が見つかりません。この会話の画像を指定してください。');
            }
        }

        return ['source_message_id' => $sourceId, 'path' => ImageSavePath::normalize($args['path']), 'status' => 'pending'];
    }

    private function saveGeneratedImage(array $image, int $threadId, string $suggestedName): array
    {
        $encoded = $image['result'] ?? null;
        $bytes = is_string($encoded) && strlen($encoded) <= 40_000_000 ? base64_decode($encoded, true) : false;
        $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        if (! $info || ($info['mime'] ?? null) !== 'image/png') {
            throw new RuntimeException('生成された画像を読み取れませんでした。もう一度お試しください。');
        }
        $name = 'generated-'.Str::uuid().'.png';
        $path = "ai-chat/{$threadId}/generated/{$name}";
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('生成された画像を保存できませんでした。');
        }

        return ['image_path' => $path, 'image_name' => $suggestedName, 'image_mime' => 'image/png', 'image_size' => strlen($bytes)];
    }

    private function inputMessages(AiChatMessage $message): array
    {
        $input = [['role' => $message->role, 'content' => $this->messageContent($message)]];
        if ($message->role === AiChatMessage::ROLE_ASSISTANT && $message->image_save) {
            $input[0]['content'] .= "\n画像の保存操作: ".json_encode($message->image_save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($message->role === AiChatMessage::ROLE_ASSISTANT && $message->image_path && Storage::disk('local')->exists($message->image_path)) {
            $input[] = ['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => '直前のAI回答で生成された画像（続きの会話の参照用）'],
                ['type' => 'input_image', 'image_url' => 'data:'.$message->image_mime.';base64,'.base64_encode(Storage::disk('local')->get($message->image_path))],
            ]];
        }

        if ($message->role === AiChatMessage::ROLE_ASSISTANT && $message->file_change_path) {
            $input[0]['content'] .= "\nファイル保存操作: ".json_encode([
                'path' => $message->file_change_path,
                'status' => $message->file_change_status,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $input;
    }

    private function textConfiguration(array $projectContext): array
    {
        return [
            'verbosity' => 'low',
            'format' => [
                'type' => 'json_schema',
                'name' => 'file_change_proposal',
                'description' => 'A Japanese answer and optional complete content for one existing or new local project file.',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => ['type' => 'string'],
                        'image_name' => ['type' => ['string', 'null'], 'description' => '生成画像の内容と会話の意図がわかる短い日本語の保存名。例: お団子中身見えるパターン.png。画像を生成していない場合はnull。'],
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
                    'required' => ['answer', 'file_change', 'image_name'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private function parseFileChange(string $content, array $editableFiles, bool $localFileAccess = false): ?array
    {
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
        if (! $target && $localFileAccess) {
            $target = ['path' => LocalAiFilePath::normalize($change['path']), 'sha256' => null];
        }
        if (! $target) {
            throw new RuntimeException('ファイルを作成するにはProject設定でローカルフォルダを接続してください。');
        }
        LocalAiFilePath::normalize($target['path']);
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
        $fileChangeInstruction = <<<'PROMPT'

IMPORTANT: When project_files or open_file is present, inspect the supplied files and return only valid JSON with no Markdown fence:
{"answer":"short Japanese explanation","file_change":{"path":"exact supplied file path","content":"complete updated file content"},"image_name":null}
If no file change is needed, return {"answer":"normal Japanese answer","file_change":null,"image_name":null}.
For existing files choose exactly one supplied path. If local_file_access is true, you may create one new text file with a relative path inside the connected folder. Return the entire working file in file_change.content, not just instructions or a code block in answer. For a simple TODO app, prefer one self-contained index.html with embedded CSS/JavaScript and localStorage persistence. Never overwrite an existing file whose content was not supplied. Never target absolute paths, parent paths, secrets, dependencies, deployment files or backups. Use Asia/Tokyo for date/time displays.
If auto_save_files is true, the browser will save this response automatically using the user's folder permission. Say that you are preparing the file for saving, not that saving is already complete. If false, the user applies the change from the card. An applied file save operation in conversation history confirms a completed save. Past claims that files cannot be created or saved are obsolete; use the current capabilities.
PROMPT;

        return <<<'PROMPT'
あなたはRISE GATE OSのAIパートナーです。プロジェクト情報の参照、変更案の作成、画像の生成ができます。
画像を作る依頼にはgenerate_imageツールを使い、1回の回答につき画像を1枚生成してください。
開いているファイルと会話のデザイン指示を画像生成に反映してください。画像生成用プロンプトを返すだけで済ませないでください。
画像生成は利用可能です。過去の会話や資料に「画像生成できない」とあっても現在の機能制限として扱わないでください。
生成した画像はチャットに表示されます。
回答本文はJSONのanswerに、生成画像の保存名はimage_nameに返してください。画像を生成していないときはimage_nameをnullにします。ファイルの変更がなければfile_changeはnullです。
画像生成時は、それまでの会話・商品・中身の見せ方・構図・バリエーションの違いを踏まえて、短く自然な日本語の保存名を必ず提案してください。
例:「お団子中身見えるパターン.png」「黒箱に金文字の高級感パターン.png」。ユーザーが名前を指定した場合はそれを優先します。「生成画像.png」のような内容がわからない名前は避け、フォルダや記号を含まないファイル名だけを返してください。
ユーザーが「フォルダを作って画像を保存」「この画像を第1案として残して」などと依頼したらsave_generated_imageを使ってください。ローカル保存は利用可能です。
保存対象はgenerated_imagesのmessage_idで指定します。「この画像」は特に指定がなければ最新の生成画像です。保存だけの依頼でgenerate_imageを使わないでください。
フォルダ名・ファイル名は依頼を優先し、指定がなければgenerated_imagesのnameや会話の内容から画像の特徴がわかる日本語名を提案します。開いているファイルの親フォルダが明らかならその中に保存先フォルダを作成します。
save_generated_imageは保存準備です。ブラウザで完了するまで保存済みとは述べないでください。過去のimage_save.statusがsavedならブラウザから保存完了が報告されています。
提供されたプロジェクト情報だけを事実として扱い、日本語で簡潔かつ具体的に回答してください。
情報が不足している場合は推測で補わず、不足している情報を明示してください。
OSの業務データを変更した、保存した、承認したとは述べないでください。ローカルファイルは保存操作のstatusがappliedの場合だけ保存完了を報告できます。
OSの業務データの変更は提案として説明してください。接続されたローカルフォルダ内の通常ファイルはfile_changeで作成・更新できます。作成や保存を依頼されたら説明だけで終わらず、ファイル全体を返してください。local_file_accessがfalseで新規作成が必要な場合はProject設定でフォルダ接続を案内してください。
ただし画像の保存依頼はsave_generated_imageでブラウザの保存操作を準備できます。image_save.statusがsavedなら保存完了の報告に基づいて回答してください。

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
