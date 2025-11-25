<?php

namespace Akr4m\LivewireRateLimiter\Strategies;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

class SlidingWindowStrategy
{
    protected Cache $cache;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Attempt to make a request using sliding window algorithm.
     */
    public function attempt(string $key, int $maxAttempts, int $decayMinutes): array
    {
        $now = Carbon::now()->timestamp;
        $window = $decayMinutes * 60; // Convert to seconds
        $windowStart = $now - $window;

        // Get all attempts in the current window
        $attempts = $this->cache->get($key, []);

        // Filter out expired attempts
        $attempts = array_filter($attempts, function ($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        if (count($attempts) >= $maxAttempts) {
            // Find the oldest attempt to calculate retry time
            $oldestAttempt = min($attempts);
            $retryAfter = ($oldestAttempt + $window) - $now;

            return [
                'allowed' => false,
                'attempts' => count($attempts),
                'remaining' => 0,
                'retry_after' => max(1, $retryAfter),
            ];
        }

        // Add new attempt
        $attempts[] = $now;

        // Store updated attempts
        $this->cache->put(
            $key,
            array_values($attempts),
            Carbon::now()->addMinutes($decayMinutes)
        );

        return [
            'allowed' => true,
            'attempts' => count($attempts),
            'remaining' => $maxAttempts - count($attempts),
            'retry_after' => 0,
        ];
    }

    /**
     * Check if request would be allowed without incrementing.
     */
    public function check(string $key, int $maxAttempts, int $decayMinutes): bool
    {
        $now = Carbon::now()->timestamp;
        $window = $decayMinutes * 60;
        $windowStart = $now - $window;

        $attempts = $this->cache->get($key, []);

        // Filter out expired attempts
        $attempts = array_filter($attempts, function ($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        return count($attempts) < $maxAttempts;
    }

    /**
     * Get remaining attempts.
     */
    public function remaining(string $key, int $maxAttempts, int $decayMinutes): int
    {
        $now = Carbon::now()->timestamp;
        $window = $decayMinutes * 60;
        $windowStart = $now - $window;

        $attempts = $this->cache->get($key, []);

        // Filter out expired attempts
        $attempts = array_filter($attempts, function ($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        return max(0, $maxAttempts - count($attempts));
    }

    /**
     * Get retry after time in seconds.
     */
    public function retryAfter(string $key, int $decayMinutes): int
    {
        $now = Carbon::now()->timestamp;
        $window = $decayMinutes * 60;
        $windowStart = $now - $window;

        $attempts = $this->cache->get($key, []);

        // Filter to get valid attempts
        $attempts = array_filter($attempts, function ($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });

        if (empty($attempts)) {
            return 0;
        }

        // Find the oldest attempt
        $oldestAttempt = min($attempts);
        $retryAfter = ($oldestAttempt + $window) - $now;

        return max(0, $retryAfter);
    }

    /**
     * Reset the rate limit.
     */
    public function reset(string $key): bool
    {
        return $this->cache->forget($key);
    }
}
