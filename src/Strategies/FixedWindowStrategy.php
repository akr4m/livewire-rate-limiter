<?php

namespace Akr4m\LivewireRateLimiter\Strategies;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

class FixedWindowStrategy
{
    protected Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Attempt to make a request.
     */
    public function attempt(string $key, int $maxAttempts, int $decayMinutes): array
    {
        $attempts = $this->cache->get($key, 0);

        if ($attempts >= $maxAttempts) {
            return [
                'allowed' => false,
                'attempts' => $attempts,
                'remaining' => 0,
                'retry_after' => $this->retryAfter($key, $decayMinutes),
            ];
        }

        $this->cache->put(
            $key,
            $attempts + 1,
            Carbon::now()->addMinutes($decayMinutes)
        );

        return [
            'allowed' => true,
            'attempts' => $attempts + 1,
            'remaining' => $maxAttempts - ($attempts + 1),
            'retry_after' => 0,
        ];
    }

    /**
     * Check if request would be allowed without incrementing.
     */
    public function check(string $key, int $maxAttempts, int $decayMinutes): bool
    {
        $attempts = $this->cache->get($key, 0);
        return $attempts < $maxAttempts;
    }

    /**
     * Get remaining attempts.
     */
    public function remaining(string $key, int $maxAttempts, int $decayMinutes): int
    {
        $attempts = $this->cache->get($key, 0);
        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Get retry after time in seconds.
     */
    public function retryAfter(string $key, int $decayMinutes): int
    {
        $expiresAt = $this->cache->get($key . ':expires_at');

        if (!$expiresAt) {
            // Store expiration time for accurate retry calculation
            $expiresAt = Carbon::now()->addMinutes($decayMinutes)->timestamp;
            $this->cache->put(
                $key . ':expires_at',
                $expiresAt,
                Carbon::now()->addMinutes($decayMinutes)
            );
        }

        return max(0, $expiresAt - Carbon::now()->timestamp);
    }

    /**
     * Reset the rate limit.
     */
    public function reset(string $key): bool
    {
        $this->cache->forget($key);
        $this->cache->forget($key . ':expires_at');
        return true;
    }
}
