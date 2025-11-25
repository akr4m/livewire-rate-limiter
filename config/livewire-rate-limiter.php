<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Rate Limiter
    |--------------------------------------------------------------------------
    |
    | This option controls the default rate limiter that will be used by
    | Livewire components when no specific limiter is specified.
    |
    */

    'default' => env('LIVEWIRE_RATE_LIMITER', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Define which cache store should be used for rate limiting.
    | By default, it uses Laravel's default cache store.
    |
    */

    'cache_store' => env('LIVEWIRE_RATE_LIMIT_STORE', config('cache.default')),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiters
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the rate limiters for your application.
    | Each limiter can have its own unique configuration.
    |
    */

    'limiters' => [
        'default' => [
            'attempts' => 60,
            'decay_minutes' => 1,
            'strategy' => 'fixed_window', // fixed_window, sliding_window, leaky_bucket
        ],

        'strict' => [
            'attempts' => 10,
            'decay_minutes' => 1,
            'strategy' => 'sliding_window',
        ],

        'relaxed' => [
            'attempts' => 100,
            'decay_minutes' => 1,
            'strategy' => 'fixed_window',
        ],

        'auth' => [
            'attempts' => 30,
            'decay_minutes' => 1,
            'strategy' => 'sliding_window',
            'key_by' => ['user', 'ip'], // Multiple keys for identification
        ],

        'guest' => [
            'attempts' => 20,
            'decay_minutes' => 1,
            'strategy' => 'fixed_window',
            'key_by' => ['ip', 'session'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Identification Strategy
    |--------------------------------------------------------------------------
    |
    | Define how to identify unique clients for rate limiting.
    | Available options: 'ip', 'user', 'session', 'fingerprint', 'custom'
    |
    */

    'key_by' => env('LIVEWIRE_RATE_LIMIT_KEY', 'ip'),

    /*
    |--------------------------------------------------------------------------
    | Custom Key Resolver
    |--------------------------------------------------------------------------
    |
    | If using 'custom' key identification, specify the resolver class.
    |
    */

    'custom_key_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Response Strategy
    |--------------------------------------------------------------------------
    |
    | Define how the package should respond when rate limit is exceeded.
    | Options: 'exception', 'validation_error', 'event', 'silent'
    |
    */

    'response_strategy' => 'validation_error',

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    |
    | Customize error messages shown when rate limits are exceeded.
    |
    */

    'messages' => [
        'rate_limit_exceeded' => 'Too many requests. Please try again in :seconds seconds.',
        'rate_limit_exceeded_minutes' => 'Too many requests. Please try again in :minutes minutes.',
        'rate_limit_exceeded_hours' => 'Too many requests. Please try again in :hours hours.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | Enable/disable events and define custom event classes.
    |
    */

    'events' => [
        'enabled' => true,
        'rate_limit_exceeded' => \LivewireRateLimiter\Events\RateLimitExceeded::class,
        'rate_limit_attempted' => \LivewireRateLimiter\Events\RateLimitAttempted::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers
    |--------------------------------------------------------------------------
    |
    | Include rate limit information in response headers.
    |
    */

    'headers' => [
        'enabled' => true,
        'remaining' => 'X-RateLimit-Remaining',
        'limit' => 'X-RateLimit-Limit',
        'retry_after' => 'X-RateLimit-RetryAfter',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Apply to Routes
    |--------------------------------------------------------------------------
    |
    | Automatically apply rate limiting to all Livewire routes.
    |
    */

    'auto_apply_to_routes' => false,

    /*
    |--------------------------------------------------------------------------
    | Bypass
    |--------------------------------------------------------------------------
    |
    | Define conditions or callbacks to bypass rate limiting.
    |
    */

    'bypass' => [
        'environments' => ['local', 'testing'],
        'ips' => [],
        'user_ids' => [],
        'callback' => null, // Custom bypass logic callback
    ],

    /*
    |--------------------------------------------------------------------------
    | Component Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for components using the rate limiting trait.
    |
    */

    'component_defaults' => [
        'enabled' => true,
        'limiter' => 'default',
        'per_action' => true, // Apply limits per action or globally per component
        'share_limit' => false, // Share limit across all component instances
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Log rate limit hits and other events.
    |
    */

    'logging' => [
        'enabled' => env('LIVEWIRE_RATE_LIMIT_LOGGING', false),
        'channel' => env('LIVEWIRE_RATE_LIMIT_LOG_CHANNEL', 'stack'),
        'level' => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Settings specific to testing environments.
    |
    */

    'testing' => [
        'enabled' => env('LIVEWIRE_RATE_LIMIT_TESTING', true),
        'fake' => false, // Fake rate limiting in tests
    ],

];
