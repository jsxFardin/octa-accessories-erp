<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\Platform\QueueHealth;
use App\Support\Scoping\PortalContext;
use App\Support\Settings\Settings;
use App\Support\Validation\DocumentValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->scoped(PortalContext::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading($this->app->isLocal());
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());
        Model::unguard(false);

        Vite::prefetch(concurrency: 3);

        /*
         * Validation messages name the field the way the form labels it, and a line-item error
         * says which line. Without this a blank quotation returns "The lines.0.product_id field
         * is required" four times per row.
         */
        Validator::resolver(
            fn ($translator, $data, $rules, $messages, $attributes) => new DocumentValidator(
                $translator, $data, $rules, $messages, $attributes,
            ),
        );

        /*
         * Every `can:` check in the application resolves here. Permission-based, never
         * role-based (06-rbac §1) — the one role the gate knows about is super_admin, and
         * that check lives inside User::hasPermission so it is stated exactly once.
         */
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasPermission($ability) ? true : null;
        });

        /*
         * A driver may open the trip list, but only ever sees their own trips (06-rbac §4).
         * Expressed as an ability rather than two routes, so the scoping lives in one place
         * instead of being duplicated per screen.
         */
        Gate::define('trip.access', fn (User $user): bool => $user->hasPermission('trip.view_any')
            || $user->hasPermission('trip.view_own'));

        /*
         * Login throttling keyed by email *and* IP. Keying on IP alone would let one
         * mistyped password lock out a factory floor sharing a single NAT gateway, which is
         * exactly the deployment this runs in.
         */
        /*
         * The worker heartbeat. Notifications are queued (`DocumentNotification` is
         * `ShouldQueue`), so with nothing draining the queue the inbox is silently always
         * empty — no error, no log line. One cache write per completed job is what lets
         * Configuration → Settings and `queue:health` say "worker unavailable" instead.
         */
        Event::listen(JobProcessed::class, fn () => app(QueueHealth::class)->recordHeartbeat());

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
