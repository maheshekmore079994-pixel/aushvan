<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        // Catch lazy loading, missing attributes and silently dropped fields during development and tests.
        Model::shouldBeStrict(! $this->app->isProduction());

        // The Owner role passes every permission check.
        Gate::before(fn (User $user) => $user->isOwner() ? true : null);

        // Reading suppliers is open to whoever enters purchases or keeps the supplier list.
        Gate::define('suppliers.view', fn (User $user) => $user->can(Permission::PURCHASES_MANAGE) || $user->can(Permission::SUPPLIERS_MANAGE));

        // The doctor list is read by whoever bills, keeps medicines or may see prescriptions.
        Gate::define('doctors.view', fn (User $user) => $user->can(Permission::SALES_CREATE) || $user->can(Permission::MEDICINES_MANAGE) || $user->can(Permission::PRESCRIPTIONS_VIEW));

        // The cash day is visible to whoever bills or closes it.
        Gate::define('day.view', fn (User $user) => $user->can(Permission::SALES_CREATE) || $user->can(Permission::DAY_CLOSE) || $user->can(Permission::DAY_REOPEN));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
