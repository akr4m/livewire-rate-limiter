<?php

namespace Akr4m\LivewireRateLimiter\Attributes;

use Attribute;

/**
 * Rate limit attribute for Livewire components.
 *
 * Usage as an attribute:
 *   #[RateLimit(maxAttempts: 5, decayMinutes: 1)]
 *   #[RateLimit(limiter: 'strict')]
 *   #[RateLimit(limiter: 'form')]
 *   #[RateLimit(key: '{user}:{component}:{method}')]
 *
 * Available named limiters (configured in config/livewire-rate-limiter.php):
 *   - 'default': 60 attempts per minute
 *   - 'strict': 10 attempts per minute (sliding window)
 *   - 'relaxed': 100 attempts per minute
 *   - 'form': 5 attempts per minute (for form submissions)
 *   - 'action': 10 attempts per minute (for button clicks)
 *   - 'api': 60 attempts per minute (throws exception)
 *   - 'auth': 30 attempts per minute (user-based)
 *   - 'guest': 20 attempts per minute (IP/session-based)
 *
 * Static factory methods are for programmatic (runtime) usage only:
 *   $config = RateLimit::forForm();
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class RateLimit
{
    public function __construct(
        public ?int $maxAttempts = null,
        public ?int $decayMinutes = null,
        public ?string $limiter = null,
        public ?string $key = null,
        public ?string $message = null,
        public string $responseType = 'validation_error',
        public bool $perAction = true,
        public bool $shareLimit = false,
    ) {
        // If neither limiter nor maxAttempts is specified, use default limiter
        if ($this->limiter === null && $this->maxAttempts === null) {
            $this->limiter = 'default';
        }
    }

    /**
     * Convert to array for configuration.
     */
    public function toArray(): array
    {
        return [
            'maxAttempts' => $this->maxAttempts,
            'decayMinutes' => $this->decayMinutes,
            'limiter' => $this->limiter,
            'key' => $this->key,
            'message' => $this->message,
            'responseType' => $this->responseType,
            'perAction' => $this->perAction,
            'shareLimit' => $this->shareLimit,
        ];
    }

    /**
     * Create a RateLimit for form submissions.
     *
     * Note: For attribute usage, use #[RateLimit(limiter: 'form')] instead.
     */
    public static function forForm(int $maxAttempts = 5, int $decayMinutes = 1): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            decayMinutes: $decayMinutes,
            responseType: 'validation_error'
        );
    }

    /**
     * Create a RateLimit for button clicks/actions.
     *
     * Note: For attribute usage, use #[RateLimit(limiter: 'action')] instead.
     */
    public static function forAction(int $maxAttempts = 10, int $decayMinutes = 1): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            decayMinutes: $decayMinutes,
            responseType: 'notification'
        );
    }

    /**
     * Create a RateLimit for API-like endpoints.
     *
     * Note: For attribute usage, use #[RateLimit(limiter: 'api')] instead.
     */
    public static function forApi(int $maxAttempts = 60, int $decayMinutes = 1): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            decayMinutes: $decayMinutes,
            responseType: 'exception'
        );
    }

    /**
     * Create a strict rate limit.
     *
     * Note: For attribute usage, use #[RateLimit(limiter: 'strict')] instead.
     */
    public static function strict(): self
    {
        return new self(limiter: 'strict');
    }

    /**
     * Create a relaxed rate limit.
     *
     * Note: For attribute usage, use #[RateLimit(limiter: 'relaxed')] instead.
     */
    public static function relaxed(): self
    {
        return new self(limiter: 'relaxed');
    }

    /**
     * Create a per-user rate limit.
     *
     * Note: For attribute usage, use #[RateLimit(key: '{user}:{component}:{method}')] instead.
     */
    public static function perUser(int $maxAttempts = 30, int $decayMinutes = 1): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            decayMinutes: $decayMinutes,
            key: '{user}:{component}:{method}'
        );
    }

    /**
     * Create a per-IP rate limit.
     *
     * Note: For attribute usage, use #[RateLimit(key: '{ip}:{component}:{method}')] instead.
     */
    public static function perIp(int $maxAttempts = 20, int $decayMinutes = 1): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            decayMinutes: $decayMinutes,
            key: '{ip}:{component}:{method}'
        );
    }

    /**
     * Create a global rate limit shared across all instances.
     *
     * Note: For attribute usage, use #[RateLimit(key: 'global:{component}:{method}', shareLimit: true)] instead.
     */
    public static function global(int $maxAttempts = 100, int $decayMinutes = 1): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            decayMinutes: $decayMinutes,
            key: 'global:{component}:{method}',
            shareLimit: true
        );
    }
}
