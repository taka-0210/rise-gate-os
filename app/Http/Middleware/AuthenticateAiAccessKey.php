<?php

namespace App\Http\Middleware;

use App\Models\AiAccessKey;
use App\Models\Workspace;
use App\Services\Organization\OrganizationAccess;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAiAccessKey
{
    public function __construct(private readonly OrganizationAccess $organizationAccess) {}

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

        $key->loadMissing(['user', 'workspace.organization']);
        $user = $key->user;
        $workspace = $key->workspace;
        if (! $user
            || ! $workspace
            || $workspace->status !== Workspace::STATUS_ACTIVE
            || ! $workspace->organization
            || ! $this->organizationAccess->hasActiveMembership($user, $workspace->organization)
            || ! $user->workspaces()->where('workspaces.id', $workspace->id)->exists()) {
            return response()->json(['message' => '現在の所属ではこのAI接続を利用できません。'], 403);
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
