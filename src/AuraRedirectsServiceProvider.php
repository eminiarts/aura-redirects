<?php

namespace Aura\Redirects;

use Aura\Base\Facades\Aura;
use Aura\Base\Resources\Team;
use Aura\Redirects\Commands\ValidateRedirectsCommand;
use Aura\Redirects\Contracts\ResolvesRedirectContext;
use Aura\Redirects\Middleware\HandleAuraRedirects;
use Aura\Redirects\Models\Redirect;
use Aura\Redirects\Policies\RedirectPolicy;
use Aura\Redirects\Services\ConfiguredRedirectContextResolver;
use Aura\Redirects\Services\ProtectedPathMatcher;
use Aura\Redirects\Services\RedirectCacheStore;
use Aura\Redirects\Services\RedirectDestinationNormalizer;
use Aura\Redirects\Services\RedirectDiagnostics;
use Aura\Redirects\Services\RedirectHitRecorder;
use Aura\Redirects\Services\RedirectMatcher;
use Aura\Redirects\Services\RedirectPathNormalizer;
use Aura\Redirects\Services\RedirectPermissionRegistrar;
use Aura\Redirects\Services\RedirectRepository;
use Aura\Redirects\Services\RedirectValidator;
use Aura\Redirects\Support\QueryStringMerger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AuraRedirectsServiceProvider extends PackageServiceProvider
{
    public function bootingPackage(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('aura-redirects')
            ->hasConfigFile()
            ->hasCommands(ValidateRedirectsCommand::class)
            ->hasMigrations(
                'create_aura_redirects_table',
                'create_aura_redirect_hit_stats_table',
            )
            ->runsMigrations();
    }

    public function packageRegistered(): void
    {
        $resolver = config('aura-redirects.resolver.class', ConfiguredRedirectContextResolver::class);

        $this->app->bind(ResolvesRedirectContext::class, $resolver);
        $this->app->singleton(QueryStringMerger::class);
        $this->app->singleton(RedirectPathNormalizer::class);
        $this->app->singleton(ProtectedPathMatcher::class);
        $this->app->singleton(RedirectDestinationNormalizer::class);
        $this->app->singleton(RedirectValidator::class);
        $this->app->singleton(RedirectRepository::class);
        $this->app->singleton(RedirectCacheStore::class);
        $this->app->singleton(RedirectMatcher::class);
        $this->app->singleton(RedirectDiagnostics::class);
        $this->app->singleton(RedirectHitRecorder::class);
        $this->app->singleton(RedirectPermissionRegistrar::class);
    }

    public function packageBooted(): void
    {
        Aura::registerResources([Redirect::class]);
        $this->registerAuraResourceRoutes();

        Gate::policy(Redirect::class, RedirectPolicy::class);

        $this->registerMiddleware();
        $this->registerPermissions();
        $this->registerSchedule();
    }

    private function registerMiddleware(): void
    {
        if ($this->app->bound(HttpKernel::class)) {
            $kernel = $this->app->make(HttpKernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(HandleAuraRedirects::class);
            }
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->prependMiddlewareToGroup('web', HandleAuraRedirects::class);
    }

    private function registerPermissions(): void
    {
        $this->app->booted(fn (): int => app(RedirectPermissionRegistrar::class)->synchronize());

        if (class_exists(Team::class)) {
            Event::listen('eloquent.created: '.Team::class, function (Team $team): void {
                app(RedirectPermissionRegistrar::class)->synchronize((int) $team->getKey());
            });
        }
    }

    private function registerSchedule(): void
    {
        $cron = config('aura-redirects.schedule.validate_cron');

        if (! is_string($cron) || trim($cron) === '') {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($cron): void {
            $command = config('aura-redirects.schedule.warm_cache_during_validation', false)
                ? 'aura-redirects:validate --warm-cache'
                : 'aura-redirects:validate';

            $schedule->command($command)->cron($cron)->withoutOverlapping();
        });
    }

    private function registerAuraResourceRoutes(): void
    {
        $resource = app(Redirect::class);
        $slug = $resource->getSlug();
        $routeName = "aura.{$slug}.index";

        if (Route::has($routeName)) {
            return;
        }

        Route::domain(config('aura.domain'))
            ->middleware(config('aura-settings.middleware.aura-admin'))
            ->name('aura.')
            ->prefix(config('aura.path'))
            ->group(function () use ($resource, $slug): void {
                Route::get("/{$slug}", $resource::indexComponent())->name("{$slug}.index");
                Route::get("/{$slug}/create", $resource::createComponent())->name("{$slug}.create");
                Route::get("/{$slug}/{id}/edit", $resource::editComponent())->name("{$slug}.edit");
                Route::get("/{$slug}/{id}", $resource::viewComponent())->name("{$slug}.view");
            });
    }
}
