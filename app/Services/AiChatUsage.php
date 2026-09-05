<?php

namespace App\Services;

use App\Models\AiChatThread;

class AiChatUsage
{
    public static function summary(?AiChatThread $thread): array
    {
        $totals = $thread?->messages()->reorder()->selectRaw(
            'COALESCE(SUM(input_tokens), 0) as chat_input, COALESCE(SUM(output_tokens), 0) as chat_output,
             COALESCE(SUM(image_input_tokens), 0) as image_input, COALESCE(SUM(image_output_tokens), 0) as image_output'
        )->first();
        $missing = $thread?->messages()->where('role', 'assistant')->whereNotNull('image_path')
            ->where(fn ($query) => $query->whereNull('image_input_tokens')->orWhereNull('image_output_tokens'))->count() ?? 0;
        $chatInput = (int) ($totals?->chat_input ?? 0);
        $chatOutput = (int) ($totals?->chat_output ?? 0);
        $imageInput = (int) ($totals?->image_input ?? 0);
        $imageOutput = (int) ($totals?->image_output ?? 0);
        $total = $chatInput + $chatOutput + $imageInput + $imageOutput;

        return [
            'chat_input_tokens' => $chatInput, 'chat_output_tokens' => $chatOutput,
            'image_input_tokens' => $imageInput, 'image_output_tokens' => $imageOutput,
            'total_tokens' => $total, 'points' => $total / 1000,
            'points_label' => rtrim(rtrim(number_format($total / 1000, 3), '0'), '.'),
            'image_usage_missing_count' => $missing,
        ];
    }

    public static function tokens(mixed $usage, string $key): ?int
    {
        $value = is_array($usage) ? ($usage[$key] ?? null) : null;

        return is_int($value) && $value >= 0 ? $value : null;
    }
}
