<?php

namespace Akr4m\LivewireRateLimiter\Testing;

use Akr4m\LivewireRateLimiter\RateLimiterManager;
use Mockery;

trait WithRateLimitTesting
{
    protected bool $rateLimitingFaked = false;
    protected array $rateLimitAttempts = [];

    /**
     * Fake rate limiting for testing.
     */
    protected function fakeRateLimiting(): void
    {
        $this->rateLimitingFaked = true;
        $this->rateLimitAttempts = [];

        $mock = Mockery::mock(RateLimiterManager::class)->makePartial();

        $mock->shouldReceive('attempt')
            ->andReturnUsing(function ($key, $limiter = null) {
                $fullKey = $limiter ? "{$limiter}:{$key}" : $key;

                if (!isset($this->rateLimitAttempts[$fullKey])) {
                    $this->rateLimitAttempts[$fullKey] = 0;
                }

                $this->rateLimitAttempts[$fullKey]++;

                $config = config(
                    "livewire-rate-limiter.limiters.{$limiter}",
                    config('livewire-rate-limiter.limiters.default')
                );

                $maxAttempts = $config['attempts'] ?? 3;

                return $this->rateLimitAttempts[$fullKey] <= $maxAttempts;
            });

        $mock->shouldReceive('check')
            ->andReturnUsing(function ($key, $limiter = null) {
                $fullKey = $limiter ? "{$limiter}:{$key}" : $key;
                $attempts = $this->rateLimitAttempts[$fullKey] ?? 0;

                $config = config(
                    "livewire-rate-limiter.limiters.{$limiter}",
                    config('livewire-rate-limiter.limiters.default')
                );

                $maxAttempts = $config['attempts'] ?? 3;

                return $attempts < $maxAttempts;
            });

        $mock->shouldReceive('remaining')
            ->andReturnUsing(function ($key, $limiter = null) {
                $fullKey = $limiter ? "{$limiter}:{$key}" : $key;
                $attempts = $this->rateLimitAttempts[$fullKey] ?? 0;

                $config = config(
                    "livewire-rate-limiter.limiters.{$limiter}",
                    config('livewire-rate-limiter.limiters.default')
                );

                $maxAttempts = $config['attempts'] ?? 3;

                return max(0, $maxAttempts - $attempts);
            });

        $mock->shouldReceive('retryAfter')
            ->andReturn(60);

        $mock->shouldReceive('reset')
            ->andReturnUsing(function ($key, $limiter = null) {
                $fullKey = $limiter ? "{$limiter}:{$key}" : $key;
                unset($this->rateLimitAttempts[$fullKey]);
                return true;
            });

        app()->instance(RateLimiterManager::class, $mock);
    }

    /**
     * Assert that rate limiting was attempted for a key.
     */
    protected function assertRateLimitAttempted(string $key, ?string $limiter = null): void
    {
        $fullKey = $limiter ? "{$limiter}:{$key}" : $key;

        $this->assertTrue(
            isset($this->rateLimitAttempts[$fullKey]) && $this->rateLimitAttempts[$fullKey] > 0,
            "Rate limit was not attempted for key: {$fullKey}"
        );
    }

    /**
     * Assert that rate limiting was not attempted for a key.
     */
    protected function assertRateLimitNotAttempted(string $key, ?string $limiter = null): void
    {
        $fullKey = $limiter ? "{$limiter}:{$key}" : $key;

        $this->assertFalse(
            isset($this->rateLimitAttempts[$fullKey]) && $this->rateLimitAttempts[$fullKey] > 0,
            "Rate limit was attempted for key: {$fullKey}"
        );
    }

    /**
     * Assert the number of rate limit attempts for a key.
     */
    protected function assertRateLimitAttemptCount(string $key, int $count, ?string $limiter = null): void
    {
        $fullKey = $limiter ? "{$limiter}:{$key}" : $key;
        $actual = $this->rateLimitAttempts[$fullKey] ?? 0;

        $this->assertEquals(
            $count,
            $actual,
            "Expected {$count} rate limit attempts for key {$fullKey}, but got {$actual}"
        );
    }

    /**
     * Set a specific attempt count for testing.
     */
    protected function setRateLimitAttempts(string $key, int $attempts, ?string $limiter = null): void
    {
        $fullKey = $limiter ? "{$limiter}:{$key}" : $key;
        $this->rateLimitAttempts[$fullKey] = $attempts;
    }

    /**
     * Reset fake rate limiting.
     */
    protected function resetFakeRateLimiting(): void
    {
        $this->rateLimitingFaked = false;
        $this->rateLimitAttempts = [];

        // Restore real instance
        app()->forgetInstance(RateLimiterManager::class);
    }

    /**
     * Clean up after test.
     */
    protected function tearDown(): void
    {
        if ($this->rateLimitingFaked) {
            $this->resetFakeRateLimiting();
        }

        parent::tearDown();
    }
}
