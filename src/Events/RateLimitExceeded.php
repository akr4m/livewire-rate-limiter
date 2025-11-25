<?php

namespace Akr4m\LivewireRateLimiter\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RateLimitExceeded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $key;
    public string $limiter;
    public array $attemptData;
    public ?string $userId;
    public string $ip;
    public string $component;
    public string $method;

    /**
     * Create a new event instance.
     */
    public function __construct(string $key, string $limiter, array $attemptData = [])
    {
        $this->key = $key;
        $this->limiter = $limiter;
        $this->attemptData = $attemptData;

        // Extract additional context
        $this->userId = auth()->id();
        $this->ip = request()->ip();

        // Try to parse component and method from key
        $parts = explode(':', $key);
        $this->component = $parts[count($parts) - 2] ?? 'unknown';
        $this->method = $parts[count($parts) - 1] ?? 'unknown';
    }

    /**
     * Get the retry after time in seconds.
     */
    public function getRetryAfter(): int
    {
        return $this->attemptData['retry_after'] ?? 0;
    }

    /**
     * Get remaining attempts (should be 0).
     */
    public function getRemaining(): int
    {
        return $this->attemptData['remaining'] ?? 0;
    }

    /**
     * Get the number of attempts made.
     */
    public function getAttempts(): int
    {
        return $this->attemptData['attempts'] ?? 0;
    }
}
