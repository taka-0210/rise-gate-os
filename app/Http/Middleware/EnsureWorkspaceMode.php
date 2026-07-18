<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->session()->get('access_mode', 'workspace') === 'workspace', 403);

        return $next($request);
    }
}
