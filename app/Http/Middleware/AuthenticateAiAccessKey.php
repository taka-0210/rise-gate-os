<?php

namespace App\Http\Middleware;

use App\Models\AiAccessKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAiAccessKey
{
    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('APIキーが必要です。');
        }

        $key = AiAccessKey::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $key || ! $key->isUsable()) {
            return $this->unauthorized('APIキーが無効または期限切れです。');
        }

        if ($scope !== '' && ! $key->allows($scope)) {
            return response()->json(['message' => 'このAPIキーには必要な権限がありません。'], 403);
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('aiAccessKey', $key);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 401);
    }
}
