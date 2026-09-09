<?php

namespace App\Services;

use App\Models\AiChatMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpenAiImageService
{
    public function tool(): array
    {
        return [
            'type' => 'function', 'name' => 'generate_image', 'strict' => true,
            'description' => '画像を1枚生成・編集する。会話と参照ファイルのデザイン指示を具体的なpromptにまとめる。画像保存だけの依頼では使わない。',
            'parameters' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => [
                    'prompt' => ['type' => 'string'],
                    'image_name' => ['type' => 'string', 'description' => '会話の内容と画像の特徴に合う短い日本語のPNG保存名'],
                    'reference_message_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'reference_images内の参照画像ID。編集なら元画像のID。参照不要なら空配列。最大4枚。'],
                    'size' => ['type' => 'string', 'enum' => ['auto', '1024x1024', '1536x1024', '1024x1536']],
                ],
                'required' => ['prompt', 'image_name', 'reference_message_ids', 'size'],
            ],
        ];
    }

    public function generate(array $call, Collection $messages): array
    {
        $args = json_decode($call['arguments'] ?? '', true);
        if (! is_array($args) || ! is_string($args['prompt'] ?? null) || trim($args['prompt']) === ''
            || mb_strlen($args['prompt']) > 32000 || ! is_string($args['image_name'] ?? null)
            || ! is_array($args['reference_message_ids'] ?? null) || count($args['reference_message_ids']) > 4
            || ! in_array($args['size'] ?? null, ['auto', '1024x1024', '1536x1024', '1024x1536'], true)) {
            throw new RuntimeException('画像生成の指示を読み取れませんでした。もう一度お試しください。');
        }
        $request = Http::withToken((string) config('services.openai.api_key'))->acceptJson()->timeout(300);
        // Only reuse images already included in this same conversation request to OpenAI.
        $imageIndex = 0;
        foreach ($args['reference_message_ids'] as $id) {
            $source = is_int($id) ? $messages->first(fn (AiChatMessage $message) => $message->id === $id) : null;
            if (! $source?->image_path || ! in_array($source->image_mime, ['image/png', 'image/jpeg', 'image/webp'], true)
                || ! Storage::disk('local')->exists($source->image_path)) {
                throw new RuntimeException('参照する画像がこの会話に見つかりません。');
            }
            foreach ($source->attachedImages() as $image) {
                if ($imageIndex >= 4) throw new RuntimeException('画像編集の参照画像は合計4枚までです。参照を絞ってください。');
                if (! Storage::disk('local')->exists($image['path'])) throw new RuntimeException('参照画像が見つかりません。');
                $request->attach("image[{$imageIndex}]", Storage::disk('local')->get($image['path']), $image['name'], ['Content-Type'=>$image['mime']]);
                $imageIndex++;
            }
        }
        $model = (string) config('services.openai.image_model', 'gpt-image-2');
        try {
            $response = $request->post('https://api.openai.com/v1/images/'.($args['reference_message_ids'] === [] ? 'generations' : 'edits'), [
                'model' => $model, 'prompt' => $args['prompt'], 'size' => $args['size'],
                'quality' => 'auto', 'output_format' => 'png', 'n' => 1,
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('画像生成サービスへ接続できませんでした。時間を置いて再度お試しください。');
        }
        if (! $response->successful()) {
            report(new RuntimeException('OpenAI Images API error '.$response->status()));
            throw new RuntimeException('画像を生成できませんでした。画像モデルの設定または利用残高を確認してください。');
        }
        return [
            'type' => 'image_generation_call', 'status' => 'completed',
            'result' => $response->json('data.0.b64_json'),
            'image_name' => ImageSavePath::suggestedName($args['image_name']),
            'model' => $model, 'usage' => $response->json('usage'),
        ];
    }
}
