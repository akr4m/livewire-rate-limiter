<?php

namespace Akr4m\LivewireRateLimiter\Contracts;

interface RateLimiterInterface
{
    /**
     * Attempt to perform an action within rate limits.
     */
    public function attempt(
        string $key,
        ?string $limiter = null,
        ?callable $callback = null,
        ?int $maxAttempts = null,
        ?int $decayMinutes = null
    ): mixed;

    /**
     * Check if rate limit would be exceeded without consuming attempt.
     */
    public function check(string $key, ?string $limiter = null): bool;

    /**
     * Get remaining attempts for a key.
     */
    public function remaining(string $key, ?string $limiter = null): int;

    /**
     * Get retry after time in seconds.
     */
    public function retryAfter(string $key, ?string $limiter = null, ?int $decayMinutes = null): int;

    /**
     * Reset rate limit for a key.
     */
    public function reset(string $key, ?string $limiter = null): bool;

    /**
     * Clear all rate limits.
     */
    public function clear(): bool;
}
