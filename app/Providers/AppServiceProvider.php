<?php

namespace App\Providers;

use App\Contracts\ActionDraftProvider;
use App\Models\Client;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectApp;
use App\Models\Roadmap;
use App\Models\Task;
use App\Observers\PlanVersionObserver;
use App\Policies\ClientPolicy;
use App\Policies\ImprovementPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RoadmapPolicy;
use App\Policies\TaskPolicy;
use App\Services\ActionExecution\OpenAiActionDraftProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('account-mail', function (Request $request): array {
            $subject = $request->user()
                ? 'user:'.$request->user()->id
                : 'email:'.Str::lower(trim((string) $request->input('email')));
            $identity = hash('sha256', $subject.'|'.$request->ip());

            return [
                Limit::perMinute(config('account.mail.max_per_minute'))->by('account-mail-minute:'.$identity),
                Limit::perHour(config('account.mail.max_per_hour'))->by('account-mail-hour:'.$identity),
            ];
        });
        RateLimiter::for('account-token', fn (Request $request) => Limit::perMinute(
            config('account.token.max_attempts_per_minute')
        )->by('account-token:'.hash('sha256', $request->ip())));
        RateLimiter::for('invitation', function (Request $request): array {
            $identity = implode('|', [
                $request->user()?->id ?: 'guest',
                $request->session()->get('current_company_id', 'none'),
                Str::lower(trim((string) $request->input('email'))),
                $request->ip(),
            ]);

            return [Limit::perMinute((int) config('invitation.max_requests_per_minute'))->by(hash('sha256', $identity))];
        });
        RateLimiter::for('owner-onboarding', function (Request $request): array {
            $identity = implode('|', [
                $request->user()?->id ?: 'guest',
                Str::lower(trim((string) $request->input('email'))),
                $request->ip(),
            ]);

            return [Limit::perMinute((int) config('owner_onboarding.max_requests_per_minute'))->by(hash('sha256', $identity))];
        });
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Improvement::class, ImprovementPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Roadmap::class, RoadmapPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Project::observe(PlanVersionObserver::class);
        Roadmap::observe(PlanVersionObserver::class);
        Improvement::observe(PlanVersionObserver::class);
        Task::observe(PlanVersionObserver::class);
    }
}
