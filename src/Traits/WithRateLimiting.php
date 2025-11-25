<?php

namespace Akr4m\LivewireRateLimiter\Traits;

use Akr4m\LivewireRateLimiter\Attributes\RateLimit;
use Akr4m\LivewireRateLimiter\Exceptions\RateLimitExceededException;
use Akr4m\LivewireRateLimiter\RateLimiterManager;
use Illuminate\Support\Facades\RateLimiter as LaravelRateLimiter;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;

trait WithRateLimiting
{
    /**
     * Rate limit configuration for this component.
     */
    protected array $rateLimitConfig = [];

    /**
     * Track rate limited methods.
     */
    protected array $rateLimitedMethods = [];

    /**
     * Initialize rate limiting for the component.
     */
    public function bootWithRateLimiting(): void
    {
        $this->initializeRateLimiting();
    }

    /**
     * Initialize rate limiting configuration.
     */
    protected function initializeRateLimiting(): void
    {
        $this->discoverRateLimitedMethods();
        $this->configureComponentRateLimiting();
    }

    /**
     * Discover methods with RateLimit attributes.
     */
    protected function discoverRateLimitedMethods(): void
    {
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RateLimit::class);

            if (!empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                $this->rateLimitedMethods[$method->getName()] = [
                    'limiter' => $attribute->limiter,
                    'maxAttempts' => $attribute->maxAttempts,
                    'decayMinutes' => $attribute->decayMinutes,
                    'key' => $attribute->key,
                    'message' => $attribute->message,
                    'responseType' => $attribute->responseType,
                    'perAction' => $attribute->perAction,
                    'shareLimit' => $attribute->shareLimit,
                ];
            }
        }
    }

    /**
     * Configure component-level rate limiting.
     */
    protected function configureComponentRateLimiting(): void
    {
        // Check for component-level rate limit attribute
        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(RateLimit::class);

        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            $this->rateLimitConfig = [
                'enabled' => true,
                'limiter' => $attribute->limiter,
                'maxAttempts' => $attribute->maxAttempts,
                'decayMinutes' => $attribute->decayMinutes,
                'key' => $attribute->key,
                'message' => $attribute->message,
                'responseType' => $attribute->responseType,
                'perAction' => $attribute->perAction,
                'shareLimit' => $attribute->shareLimit,
            ];
        } elseif (property_exists($this, 'rateLimits')) {
            // Fallback to property configuration
            $this->rateLimitConfig = array_merge(['enabled' => true], $this->rateLimits);
        }
    }

    /**
     * Check rate limit before executing a method.
     */
    public function callMethod($method, $params = []): mixed
    {
        if ($this->shouldCheckRateLimit($method)) {
            $this->checkRateLimit($method);
        }

        return parent::callMethod($method, $params);
    }

    /**
     * Check if method should be rate limited.
     */
    protected function shouldCheckRateLimit(string $method): bool
    {
        // Check bypass first
        if ($this->shouldBypassRateLimit()) {
            return false;
        }

        // Skip if rate limiting is disabled globally
        if (!config('livewire-rate-limiter.component_defaults.enabled', true)) {
            return false;
        }

        // Check if method is explicitly rate limited
        if (isset($this->rateLimitedMethods[$method])) {
            return true;
        }

        // Check if component has global rate limiting
        return !empty($this->rateLimitConfig) && ($this->rateLimitConfig['enabled'] ?? false);
    }

    /**
     * Check and enforce rate limit.
     */
    protected function checkRateLimit(string $method): void
    {
        $manager = app(RateLimiterManager::class);
        $key = $this->getRateLimitKey($method);
        $config = $this->getMergedRateLimitConfig($method);

        if ($config['useLaravelRateLimiter'] ?? false) {
            $this->checkLaravelRateLimit($key, $config);
            return;
        }

        $attempt = $manager->attempt($key, $config['limiter'] ?? null);

        if (!$attempt) {
            $this->handleRateLimitExceeded($method, $manager->retryAfter($key, $config['limiter'] ?? null));
        }
    }

    /**
     * Use Laravel's built-in rate limiter.
     */
    protected function checkLaravelRateLimit(string $key, array $config): void
    {
        $executed = LaravelRateLimiter::attempt(
            $key,
            $config['maxAttempts'] ?? 60,
            function () {
                // Action allowed
            },
            $config['decayMinutes'] ?? 1
        );

        if (!$executed) {
            $seconds = LaravelRateLimiter::availableIn($key);
            $this->handleRateLimitExceeded($key, $seconds);
        }
    }

    /**
     * Get rate limit key for the action.
     */
    protected function getRateLimitKey(string $method): string
    {
        $config = $this->getMergedRateLimitConfig($method);

        if ($config['key'] ?? null) {
            return $this->resolveRateLimitKey($config['key'], $method);
        }

        $keyResolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);
        $baseKey = $keyResolver->resolve();

        if ($config['perAction'] ?? true) {
            return sprintf('%s:%s:%s', $baseKey, static::class, $method);
        }

        return sprintf('%s:%s', $baseKey, static::class);
    }

    /**
     * Resolve rate limit key with placeholders.
     */
    protected function resolveRateLimitKey(string $key, string $method): string
    {
        $replacements = [
            '{user}' => auth()->id() ?? 'guest',
            '{ip}' => request()->ip(),
            '{session}' => session()->getId(),
            '{component}' => static::class,
            '{method}' => $method,
            '{action}' => $method,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $key);
    }

    /**
     * Get rate limit configuration for a method.
     */
    protected function getRateLimitConfig(string $method): array
    {
        // Method-specific configuration takes precedence
        if (isset($this->rateLimitedMethods[$method])) {
            return $this->rateLimitedMethods[$method];
        }

        // Fall back to component configuration
        return $this->rateLimitConfig;
    }

    /**
     * Get merged rate limit configuration (attribute + limiter config).
     */
    protected function getMergedRateLimitConfig(string $method): array
    {
        $config = $this->getRateLimitConfig($method);

        // If using a named limiter, merge its config
        if (!empty($config['limiter'])) {
            $limiterConfig = config("livewire-rate-limiter.limiters.{$config['limiter']}", []);

            // Map limiter config keys to our expected format
            $mappedLimiterConfig = [
                'maxAttempts' => $limiterConfig['attempts'] ?? null,
                'decayMinutes' => $limiterConfig['decay_minutes'] ?? null,
                'responseType' => $limiterConfig['response_type'] ?? null,
            ];

            // Merge: attribute values take precedence over limiter defaults
            foreach ($mappedLimiterConfig as $key => $value) {
                if ($value !== null && !isset($config[$key])) {
                    $config[$key] = $value;
                }
            }
        }

        return $config;
    }

    /**
     * Handle rate limit exceeded.
     */
    protected function handleRateLimitExceeded(string $method, int $retryAfter): void
    {
        $config = $this->getMergedRateLimitConfig($method);
        $responseType = $config['responseType'] ?? config('livewire-rate-limiter.response_strategy', 'validation_error');
        $message = $this->getRateLimitMessage($retryAfter, $config['message'] ?? null);

        switch ($responseType) {
            case 'exception':
                throw new RateLimitExceededException($message, $retryAfter);

            case 'validation_error':
                $this->addError('rateLimited', $message);
                throw ValidationException::withMessages(['rateLimited' => $message]);

            case 'event':
                $this->dispatch('rateLimitExceeded', [
                    'method' => $method,
                    'retryAfter' => $retryAfter,
                    'message' => $message,
                ]);
                break;

            case 'notification':
                $this->dispatch('notify', [
                    'type' => 'error',
                    'message' => $message,
                ]);
                break;

            case 'silent':
                // Do nothing, just prevent the action
                break;

            default:
                $this->addError('rateLimited', $message);
        }
    }

    /**
     * Get user-friendly rate limit message.
     */
    protected function getRateLimitMessage(int $seconds, ?string $customMessage = null): string
    {
        if ($customMessage) {
            return str_replace([':seconds', ':minutes', ':hours'], [
                $seconds,
                ceil($seconds / 60),
                ceil($seconds / 3600),
            ], $customMessage);
        }

        $messages = config('livewire-rate-limiter.messages', []);

        if ($seconds < 60) {
            $message = $messages['rate_limit_exceeded'] ?? 'Too many requests. Please try again in :seconds seconds.';
            return str_replace(':seconds', (string) $seconds, $message);
        } elseif ($seconds < 3600) {
            $minutes = (int) ceil($seconds / 60);
            $message = $messages['rate_limit_exceeded_minutes'] ?? 'Too many requests. Please try again in :minutes minutes.';
            return str_replace(':minutes', (string) $minutes, $message);
        } else {
            $hours = (int) ceil($seconds / 3600);
            $message = $messages['rate_limit_exceeded_hours'] ?? 'Too many requests. Please try again in :hours hours.';
            return str_replace(':hours', (string) $hours, $message);
        }
    }

    /**
     * Get remaining attempts for a method.
     */
    public function getRateLimitRemaining(string $method): int
    {
        $manager = app(RateLimiterManager::class);
        $key = $this->getRateLimitKey($method);
        $config = $this->getMergedRateLimitConfig($method);

        return $manager->remaining($key, $config['limiter'] ?? null);
    }

    /**
     * Reset rate limit for a method.
     */
    public function resetRateLimit(string $method): void
    {
        $manager = app(RateLimiterManager::class);
        $key = $this->getRateLimitKey($method);
        $config = $this->getMergedRateLimitConfig($method);

        $manager->reset($key, $config['limiter'] ?? null);
    }

    /**
     * Temporarily bypass rate limiting for the next request.
     */
    public function bypassRateLimitOnce(): void
    {
        session()->flash('bypass_rate_limit_' . static::class, true);
    }

    /**
     * Check if rate limiting should be bypassed.
     */
    protected function shouldBypassRateLimit(): bool
    {
        return session()->pull('bypass_rate_limit_' . static::class, false);
    }
}
