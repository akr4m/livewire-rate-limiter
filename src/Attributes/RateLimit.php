<?php

namespace Akr4m\LivewireRateLimiter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
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
        // If limiter is specified, other values are optional
        if ($this->limiter === null && $this->maxAttempts === null) {
            $this->limiter = 'default';
        }
    }

    /**
     * Create a RateLimit for form submissions.
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
     */
    public static function strict(): self
    {
        return new self(limiter: 'strict');
    }

    /**
     * Create a relaxed rate limit.
     */
    public static function relaxed(): self
    {
        return new self(limiter: 'relaxed');
    }

    /**
     * Create a per-user rate limit.
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
