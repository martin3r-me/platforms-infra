<?php

namespace Platform\Infra;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InfraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/infra.php', 'infra');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Platform\Infra\Console\Commands\PruneInfraDataCommand::class,
            ]);

            $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, function (\Illuminate\Console\Scheduling\Schedule $schedule) {
                $schedule->command('infra:prune')
                    ->dailyAt('04:10')
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }

    public function boot(): void
    {
        Relation::morphMap([
            'infra_server' => \Platform\Infra\Models\InfraServer::class,
            'infra_finding' => \Platform\Infra\Models\InfraFinding::class,
        ]);

        if (
            config()->has('infra.routing') &&
            config()->has('infra.navigation') &&
            PlatformCore::modulesTableExists()
        ) {
            PlatformCore::registerModule([
                'key'        => 'infra',
                'title'      => 'Infra',
                'routing'    => config('infra.routing'),
                'guard'      => config('infra.guard'),
                'navigation' => config('infra.navigation'),
                'sidebar'    => config('infra.sidebar'),
            ]);
        }

        if (PlatformCore::getModule('infra')) {
            ModuleRouter::group('infra', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
        }

        // Agent API: always loaded (the observer reports even if nobody opened the UI module).
        \Illuminate\Support\Facades\Route::domain(parse_url(config('app.url'), PHP_URL_HOST))
            ->middleware(['api'])
            ->prefix('api')
            ->group(__DIR__.'/../routes/api.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/infra.php' => config_path('infra.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'infra');

        $this->registerLivewireComponents();
        $this->registerTools();
    }

    protected function registerLivewireComponents(): void
    {
        $basePath = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\Infra\\Livewire';
        $prefix = 'infra';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            Livewire::component($prefix . '.' . $aliasPath, $class);
        }
    }

    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            $registry->register(new \Platform\Infra\Tools\ListServersTool());
            $registry->register(new \Platform\Infra\Tools\GetServerTool());
            $registry->register(new \Platform\Infra\Tools\CreateServerTool());
            $registry->register(new \Platform\Infra\Tools\UpdateServerTool());
            $registry->register(new \Platform\Infra\Tools\ListFindingsTool());
            $registry->register(new \Platform\Infra\Tools\UpdateFindingTool());
        } catch (\Throwable $e) {
            \Log::warning('Infra: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
    }
}
