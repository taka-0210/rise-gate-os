<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('aiAccessKey');
        $enabled = $key?->workspace?->aiSetting?->enabled
            ?? $key?->workspace()->with('aiSetting')->first()?->aiSetting?->enabled;

        if (! $enabled) {
            return response()->json(['message' => 'このWorkspaceではAI機能が有効化されていません。'], 403);
        }

        return $next($request);
    }
}
