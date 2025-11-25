<?php

namespace Akr4m\LivewireRateLimiter\Strategies;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

class TokenBucketStrategy
{
    protected Cache $cache;
    protected float $refillRate; // Tokens per second

    public function __construct(Cache $cache, float $refillRate = 1.0)
    {
        $this->cache = $cache;
        $this->refillRate = $refillRate;
    }

    /**
     * Attempt to consume a token from the bucket.
     */
    public function attempt(string $key, int $maxTokens, int $decayMinutes): array
    {
        $now = microtime(true);
        $bucket = $this->getBucket($key, $maxTokens, $now);

        if ($bucket['tokens'] >= 1) {
            // Consume a token
            $bucket['tokens'] -= 1;
            $bucket['last_refill'] = $now;

            $this->cache->put(
                $key,
                $bucket,
                Carbon::now()->addMinutes($decayMinutes)
            );

            return [
                'allowed' => true,
                'tokens_remaining' => floor($bucket['tokens']),
                'retry_after' => 0,
            ];
        }

        // Calculate when next token will be available
        $timeForNextToken = (1 - $bucket['tokens']) / $this->refillRate;

        return [
            'allowed' => false,
            'tokens_remaining' => 0,
            'retry_after' => ceil($timeForNextToken),
        ];
    }

    /**
     * Get or initialize bucket state.
     */
    protected function getBucket(string $key, int $maxTokens, float $now): array
    {
        $bucket = $this->cache->get($key);

        if (!$bucket) {
            return [
                'tokens' => $maxTokens,
                'last_refill' => $now,
                'max_tokens' => $maxTokens,
            ];
        }

        // Refill tokens based on elapsed time
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = $elapsed * $this->refillRate;

        $bucket['tokens'] = min(
            $maxTokens,
            $bucket['tokens'] + $tokensToAdd
        );
        $bucket['last_refill'] = $now;

        return $bucket;
    }

    /**
     * Check if request would be allowed without consuming token.
     */
    public function check(string $key, int $maxTokens, int $decayMinutes): bool
    {
        $now = microtime(true);
        $bucket = $this->getBucket($key, $maxTokens, $now);

        return $bucket['tokens'] >= 1;
    }

    /**
     * Get remaining tokens.
     */
    public function remaining(string $key, int $maxTokens, int $decayMinutes): int
    {
        $now = microtime(true);
        $bucket = $this->getBucket($key, $maxTokens, $now);

        return floor($bucket['tokens']);
    }

    /**
     * Reset the bucket.
     */
    public function reset(string $key): bool
    {
        return $this->cache->forget($key);
    }

    /**
     * Get retry after time in seconds.
     */
    public function retryAfter(string $key, int $decayMinutes): int
    {
        $now = microtime(true);
        $bucket = $this->cache->get($key);

        if (!$bucket || $bucket['tokens'] >= 1) {
            return 0;
        }

        $timeForNextToken = (1 - $bucket['tokens']) / $this->refillRate;
        return ceil($timeForNextToken);
    }
}
