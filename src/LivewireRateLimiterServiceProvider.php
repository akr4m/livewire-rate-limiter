<?php

namespace Akr4m\LivewireRateLimiter;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Akr4m\LivewireRateLimiter\Http\Middleware\LivewireRateLimitMiddleware;
use Akr4m\LivewireRateLimiter\Listeners\ComponentRateLimitListener;

class LivewireRateLimiterServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/livewire-rate-limiter.php',
            'livewire-rate-limiter'
        );

        // Register the rate limiter manager as a singleton
        $this->app->singleton(RateLimiterManager::class, function ($app) {
            return new RateLimiterManager(
                $app['cache'],
                $app['config']['livewire-rate-limiter']
            );
        });

        // Register the key resolver
        $this->app->singleton(Resolvers\KeyResolver::class, function ($app) {
            return new Resolvers\KeyResolver($app['request']);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishConfig();
        $this->registerMiddleware();
        $this->registerLivewireHooks();
        $this->registerCommands();
        $this->loadTranslations();
    }

    /**
     * Publish configuration file.
     */
    protected function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/livewire-rate-limiter.php' => config_path('livewire-rate-limiter.php'),
            ], 'livewire-rate-limiter-config');

            $this->publishes([
                __DIR__ . '/../resources/lang' => resource_path('lang/vendor/livewire-rate-limiter'),
            ], 'livewire-rate-limiter-lang');
        }
    }

    /**
     * Register middleware for Livewire routes.
     */
    protected function registerMiddleware(): void
    {
        // Register middleware alias
        $this->app['router']->aliasMiddleware(
            'livewire.rate.limit',
            LivewireRateLimitMiddleware::class
        );

        // Auto-apply to Livewire routes if configured
        if (config('livewire-rate-limiter.auto_apply_to_routes', false)) {
            Livewire::addPersistentMiddleware([
                LivewireRateLimitMiddleware::class,
            ]);
        }
    }

    /**
     * Register Livewire lifecycle hooks.
     */
    protected function registerLivewireHooks(): void
    {
        // Listen to Livewire component lifecycle events
        Livewire::listen('component.dehydrate', function ($component) {
            app(ComponentRateLimitListener::class)->handle($component);
        });

        // Register custom Livewire directives for Blade
        if (class_exists('Livewire\LivewireBladeDirectives')) {
            Livewire::directive('rateLimit', function ($expression) {
                return "<?php \$__rateLimitKey = {$expression}; ?>";
            });
        }
    }

    /**
     * Register console commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ClearRateLimitsCommand::class,
                Commands\ShowRateLimitsCommand::class,
            ]);
        }
    }

    /**
     * Load translation files.
     */
    protected function loadTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'livewire-rate-limiter');
    }
}
