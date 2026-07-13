<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Improvement;
use App\Models\Project;
use App\Policies\ClientPolicy;
use App\Policies\ImprovementPolicy;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Improvement::class, ImprovementPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
    }
}
