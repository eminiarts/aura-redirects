<?php

namespace Aura\Redirects\Tests;

use Aura\Base\AuraServiceProvider;
use Aura\Base\Providers\AuthServiceProvider;
use Aura\Redirects\AuraRedirectsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Lab404\Impersonate\ImpersonateServiceProvider;
use Laravel\Fortify\FortifyServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use InteractsWithViews;

    protected bool $teamsEnabled = true;

    protected function defineEnvironment($app): void
    {
        $this->useIsolatedFilesystemPaths($app);
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
        $app['config']->set('app.url', 'https://www.example.test');
        $app['config']->set('aura.teams', $this->teamsEnabled);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->defineEnvironment($app);

        (require __DIR__.'/../vendor/eminiarts/aura-cms/database/migrations/create_aura_tables.php.stub')->up();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            FortifyServiceProvider::class,
            AuthServiceProvider::class,
            AuraServiceProvider::class,
            AuraRedirectsServiceProvider::class,
            ImpersonateServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Aura\\Base\\Database\\Factories\\'.class_basename($modelName).'Factory',
        );

        $this->defineRoutes($this->app->make('router'));
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(function () use ($router): void {
            $router->get('/old-path', fn () => response('old-path'));
            $router->get('/new-path', fn () => response('new-path'));
            $router->get('/temporary-target', fn () => response('temporary-target'));
            $router->get('/search-target', fn () => response('search-target'));
            $router->get('/campaign-destination', fn () => response('campaign-destination'));
            $router->match(['GET', 'HEAD'], '/head-target', fn () => response('head-target'));
            $router->post('/submit', fn () => response('submitted'));
            $router->get('/admin/healthcheck', fn () => response('admin-ok'));
            $router->get('/public-destination', fn () => response('public-destination'));
        });

        $router->middleware('api')->group(function () use ($router): void {
            $router->get('/api/ping', fn () => response()->json(['ok' => true]));
        });
    }

    private function useIsolatedFilesystemPaths($app): void
    {
        $basePath = sys_get_temp_dir().'/aura-redirects-testbench-'.getmypid().($this->teamsEnabled ? '-teams' : '-single');

        foreach ([
            'app/Aura/Resources',
            'bootstrap/cache',
            'config',
            'database/migrations',
            'public',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $path) {
            if (! is_dir($basePath.'/'.$path)) {
                @mkdir($basePath.'/'.$path, 0755, true);
            }
        }

        $app->useAppPath($basePath.'/app');
        (function (): void {
            $this->namespace = 'App\\';
        })->call($app);
        $app->useBootstrapPath($basePath.'/bootstrap');
        $app->useConfigPath($basePath.'/config');
        $app->useDatabasePath($basePath.'/database');
        $app->usePublicPath($basePath.'/public');
        $app->useStoragePath($basePath.'/storage');
    }
}
