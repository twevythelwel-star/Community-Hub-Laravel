<?php

namespace App\Providers;

use App\Events\VisitorCheckedInEvent;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\Community;
use App\Services\Api\RateLimiting\ApiRateLimiter;
use App\Services\GatePassEngine;
use App\Services\GeofenceService;
use App\Services\NotificationEngine\NotificationEngine;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeofenceService::class);

        $this->app->singleton(GatePassEngine::class, fn () => new GatePassEngine);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Password::defaults() backs every admin- and user-set password. It was
        // never configured, so eight characters of anything passed — for
        // System Admin accounts too. Development and tests keep the easy rule.
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(12)->mixedCase()->numbers()
            : Password::min(8));

        // Register custom API Rate Limiters (api, api.sensitive, api.webhooks)
        ApiRateLimiter::register();

        // Livewire re-runs `auth` on every component update by default, but not
        // `active`; without this a deactivated account keeps working an open page.
        if (class_exists(Livewire::class)) {
            Livewire::addPersistentMiddleware([EnsureUserIsActive::class]);
        }

        Event::listen(VisitorCheckedInEvent::class, function (VisitorCheckedInEvent $event) {
            try {
                app(NotificationEngine::class)->dispatchArrivalNotice($event->visitor, $event->gate);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch arrival notice: '.$e->getMessage());
            }
        });

        View::composer(['blade.*', 'layouts.public', 'pdf.*'], function ($view) {
            $community = Community::default() ?? Community::first();
            if (! array_key_exists('community', $view->getData())) {
                $view->with('community', $community);
            }
            if (! array_key_exists('community_name', $view->getData())) {
                $view->with('community_name', $community?->name ?? 'Community Hub');
            }
        });
    }
}
