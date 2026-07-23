<?php

use App\Http\Middleware\EnsureCurrentWorkspace;
use App\Http\Middleware\EnsureSystemAdmin;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureWorkspaceMode;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['file_content']);
        $middleware->alias([
            'workspace' => EnsureCurrentWorkspace::class,
            'system-admin' => EnsureSystemAdmin::class,
            'active-user' => EnsureActiveUser::class,
            'workspace-mode' => EnsureWorkspaceMode::class,
            'ai-key' => \App\Http\Middleware\AuthenticateAiAccessKey::class,
            'ai-enabled' => \App\Http\Middleware\EnsureWorkspaceAiEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
