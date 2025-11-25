<?php

namespace Akr4m\LivewireRateLimiter;

use Akr4m\LivewireRateLimiter\Hooks\RateLimitComponentHook;
use Akr4m\LivewireRateLimiter\Http\Middleware\LivewireRateLimitMiddleware;
use Illuminate\Support\ServiceProvider;
use Livewire\ComponentHookRegistry;
use Livewire\Livewire;

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

        // Register interface binding
        $this->app->bind(
            Contracts\RateLimiterInterface::class,
            RateLimiterManager::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishConfig();
        $this->registerMiddleware();
        $this->registerCommands();
        $this->registerComponentHook();
    }

    /**
     * Register the Livewire component hook for rate limiting.
     */
    protected function registerComponentHook(): void
    {
        ComponentHookRegistry::register(RateLimitComponentHook::class);
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
}
