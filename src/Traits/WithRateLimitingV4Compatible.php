<?php

namespace Akr4m\LivewireRateLimiter\Traits;

use Akr4m\LivewireRateLimiter\Compatibility\LivewireVersionManager;

trait WithRateLimitingV4Compatible
{
    use WithRateLimiting;

    /**
     * Initialize rate limiting with version compatibility.
     */
    public function bootWithRateLimitingV4Compatible(): void
    {
        $this->bootWithRateLimiting();

        // Apply v4-specific configurations if needed
        if (LivewireVersionManager::isV4()) {
            $this->configureV4Compatibility();
        }
    }

    /**
     * Configure v4-specific behavior.
     */
    protected function configureV4Compatibility(): void
    {
        // v4 uses reactive properties - ensure our config is reactive
        if (method_exists($this, 'reactive')) {
            $this->reactive(['rateLimitConfig']);
        }

        // v4 may require different property protection
        if (method_exists($this, 'locked')) {
            $this->locked(['rateLimitedMethods']);
        }
    }

    /**
     * Override to handle v4 dispatch vs v3 emit.
     */
    protected function handleRateLimitExceeded(string $method, int $retryAfter): void
    {
        $config = $this->getRateLimitConfig($method);
        $responseType = $config['responseType'] ?? config('livewire-rate-limiter.response_strategy', 'validation_error');
        $message = $this->getRateLimitMessage($retryAfter, $config['message'] ?? null);

        switch ($responseType) {
            case 'event':
                $this->dispatchRateLimitEvent($method, $retryAfter, $message);
                break;

            case 'notification':
                $this->dispatchNotification($message);
                break;

            default:
                parent::handleRateLimitExceeded($method, $retryAfter);
        }
    }

    /**
     * Dispatch rate limit event based on version.
     */
    protected function dispatchRateLimitEvent(string $method, int $retryAfter, string $message): void
    {
        $eventData = [
            'method' => $method,
            'retryAfter' => $retryAfter,
            'message' => $message,
        ];

        if (LivewireVersionManager::isV4()) {
            // v4 uses dispatch
            $this->dispatch('rateLimitExceeded', $eventData);
        } else {
            // v3 uses emit
            $this->emit('rateLimitExceeded', $eventData);
        }
    }

    /**
     * Dispatch notification based on version.
     */
    protected function dispatchNotification(string $message): void
    {
        $notificationData = [
            'type' => 'error',
            'message' => $message,
        ];

        if (LivewireVersionManager::isV4()) {
            $this->dispatch('notify', $notificationData);
        } else {
            $this->emit('notify', $notificationData);
        }
    }

    /**
     * Get component ID for rate limiting key.
     */
    protected function getComponentIdentifier(): string
    {
        return LivewireVersionManager::getComponentId($this);
    }

    /**
     * Check if using deferred updates (v3 only).
     */
    protected function isDeferredUpdate(string $property): bool
    {
        if (LivewireVersionManager::isV4()) {
            // v4 doesn't have wire:model.defer
            return false;
        }

        // Check if property uses .defer in v3
        return property_exists($this, '__deferredProps') &&
            in_array($property, $this->__deferredProps);
    }
}
