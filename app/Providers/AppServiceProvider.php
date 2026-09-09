<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectApp;
use App\Models\Task;
use App\Policies\ClientPolicy;
use App\Policies\ImprovementPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('standalone-app-login', function (Request $request) {
            $app = $request->route('projectApp');
            $id = $app instanceof ProjectApp ? $app->public_id : (string) $app;

            return Limit::perMinute(6)->by($id.'|'.$request->ip());
        });
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Improvement::class, ImprovementPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Roadmap::class, RoadmapPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
    }
}
