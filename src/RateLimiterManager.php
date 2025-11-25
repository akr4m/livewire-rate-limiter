<?php

namespace Akr4m\LivewireRateLimiter;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Carbon;
use Akr4m\LivewireRateLimiter\Contracts\RateLimiterInterface;
use Akr4m\LivewireRateLimiter\Strategies\FixedWindowStrategy;
use Akr4m\LivewireRateLimiter\Strategies\SlidingWindowStrategy;
use Akr4m\LivewireRateLimiter\Strategies\TokenBucketStrategy;

class RateLimiterManager implements RateLimiterInterface
{
    protected CacheManager $cache;
    protected array $config;
    protected array $strategies = [];

    public function __construct(CacheManager $cache, array $config)
    {
        $this->cache = $cache;
        $this->config = $config;
        $this->registerDefaultStrategies();
    }

    /**
     * Attempt to perform an action within rate limits.
     */
    public function attempt(
        string $key,
        ?string $limiter = null,
        ?callable $callback = null,
        ?int $maxAttempts = null,
        ?int $decayMinutes = null
    ): mixed {
        $limiter = $limiter ?? $this->config['default'];
        $limiterConfig = $this->getLimiterConfig($limiter);

        if ($this->shouldBypass()) {
            return $callback ? $callback() : true;
        }

        $strategy = $this->getStrategy($limiterConfig['strategy'] ?? 'fixed_window');
        $fullKey = $this->buildKey($key, $limiter);

        // Use attribute values if provided, otherwise fall back to limiter config
        $effectiveMaxAttempts = $maxAttempts ?? $limiterConfig['attempts'] ?? 60;
        $effectiveDecayMinutes = $decayMinutes ?? $limiterConfig['decay_minutes'] ?? 1;

        $attempt = $strategy->attempt(
            $fullKey,
            $effectiveMaxAttempts,
            $effectiveDecayMinutes
        );

        if (!$attempt['allowed']) {
            $this->handleRateLimitExceeded($key, $limiter, $attempt);
            return false;
        }

        if ($callback) {
            return $callback();
        }

        return true;
    }

    /**
     * Check if rate limit would be exceeded without consuming attempt.
     */
    public function check(string $key, ?string $limiter = null): bool
    {
        $limiter = $limiter ?? $this->config['default'];
        $limiterConfig = $this->getLimiterConfig($limiter);

        if ($this->shouldBypass()) {
            return true;
        }

        $strategy = $this->getStrategy($limiterConfig['strategy'] ?? 'fixed_window');
        $fullKey = $this->buildKey($key, $limiter);

        return $strategy->check(
            $fullKey,
            $limiterConfig['attempts'] ?? 60,
            $limiterConfig['decay_minutes'] ?? 1
        );
    }

    /**
     * Get remaining attempts for a key.
     */
    public function remaining(string $key, ?string $limiter = null): int
    {
        $limiter = $limiter ?? $this->config['default'];
        $limiterConfig = $this->getLimiterConfig($limiter);

        $strategy = $this->getStrategy($limiterConfig['strategy'] ?? 'fixed_window');
        $fullKey = $this->buildKey($key, $limiter);

        return $strategy->remaining(
            $fullKey,
            $limiterConfig['attempts'] ?? 60,
            $limiterConfig['decay_minutes'] ?? 1
        );
    }

    /**
     * Get retry after time in seconds.
     */
    public function retryAfter(string $key, ?string $limiter = null, ?int $decayMinutes = null): int
    {
        $limiter = $limiter ?? $this->config['default'];
        $limiterConfig = $this->getLimiterConfig($limiter);

        $strategy = $this->getStrategy($limiterConfig['strategy'] ?? 'fixed_window');
        $fullKey = $this->buildKey($key, $limiter);

        $effectiveDecayMinutes = $decayMinutes ?? $limiterConfig['decay_minutes'] ?? 1;

        return $strategy->retryAfter(
            $fullKey,
            $effectiveDecayMinutes
        );
    }

    /**
     * Reset rate limit for a key.
     */
    public function reset(string $key, ?string $limiter = null): bool
    {
        $fullKey = $this->buildKey($key, $limiter ?? $this->config['default']);

        return $this->cache->store($this->config['cache_store'])->forget($fullKey);
    }

    /**
     * Clear all rate limits.
     */
    public function clear(): bool
    {
        // This would require tagging support in cache
        // For now, return true as individual keys can be cleared
        return true;
    }

    /**
     * Register a custom strategy.
     */
    public function extend(string $name, callable $callback): void
    {
        $this->strategies[$name] = $callback;
    }

    /**
     * Get limiter configuration.
     */
    protected function getLimiterConfig(string $limiter): array
    {
        return $this->config['limiters'][$limiter] ?? $this->config['limiters']['default'];
    }

    /**
     * Build cache key for rate limiting.
     */
    protected function buildKey(string $key, string $limiter): string
    {
        return sprintf('livewire_rate_limit:%s:%s', $limiter, $key);
    }

    /**
     * Get strategy instance.
     */
    protected function getStrategy(string $name): object
    {
        if (isset($this->strategies[$name])) {
            return is_callable($this->strategies[$name])
                ? call_user_func($this->strategies[$name], $this->cache, $this->config)
                : $this->strategies[$name];
        }

        throw new \InvalidArgumentException("Rate limiting strategy [{$name}] not found.");
    }

    /**
     * Register default strategies.
     */
    protected function registerDefaultStrategies(): void
    {
        $cacheStore = $this->cache->store($this->config['cache_store']);

        $this->strategies['fixed_window'] = new FixedWindowStrategy($cacheStore);
        $this->strategies['sliding_window'] = new SlidingWindowStrategy($cacheStore);
        $this->strategies['token_bucket'] = new TokenBucketStrategy($cacheStore);
    }

    /**
     * Check if rate limiting should be bypassed.
     */
    protected function shouldBypass(): bool
    {
        // Read bypass config dynamically to support runtime changes (e.g., in tests)
        $bypass = config('livewire-rate-limiter.bypass', $this->config['bypass'] ?? []);

        // Check environment
        if (isset($bypass['environments']) && in_array(app()->environment(), $bypass['environments'])) {
            return true;
        }

        // Check IP
        if (isset($bypass['ips']) && in_array(request()->ip(), $bypass['ips'])) {
            return true;
        }

        // Check user ID
        if (isset($bypass['user_ids']) && auth()->check() && in_array(auth()->id(), $bypass['user_ids'])) {
            return true;
        }

        // Check custom callback
        if (isset($bypass['callback']) && is_callable($bypass['callback'])) {
            return call_user_func($bypass['callback']);
        }

        return false;
    }

    /**
     * Handle rate limit exceeded.
     */
    protected function handleRateLimitExceeded(string $key, string $limiter, array $attempt): void
    {
        if ($this->config['events']['enabled'] ?? true) {
            $eventClass = $this->config['events']['rate_limit_exceeded'];
            event(new $eventClass($key, $limiter, $attempt));
        }

        if ($this->config['logging']['enabled'] ?? false) {
            logger()->channel($this->config['logging']['channel'] ?? 'stack')
                ->log(
                    $this->config['logging']['level'] ?? 'warning',
                    'Livewire rate limit exceeded',
                    [
                        'key' => $key,
                        'limiter' => $limiter,
                        'retry_after' => $attempt['retry_after'] ?? null,
                    ]
                );
        }
    }

    /**
     * Get cache store instance.
     */
    public function getCacheStore()
    {
        return $this->cache->store($this->config['cache_store']);
    }

    /**
     * Get current configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
