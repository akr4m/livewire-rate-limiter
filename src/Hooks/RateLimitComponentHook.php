<?php

namespace Akr4m\LivewireRateLimiter\Hooks;

use Akr4m\LivewireRateLimiter\Attributes\RateLimit;
use Akr4m\LivewireRateLimiter\Exceptions\RateLimitExceededException;
use Akr4m\LivewireRateLimiter\RateLimiterManager;
use Akr4m\LivewireRateLimiter\Resolvers\KeyResolver;
use Akr4m\LivewireRateLimiter\Traits\WithRateLimiting;
use Illuminate\Validation\ValidationException;
use Livewire\ComponentHook;
use ReflectionClass;
use ReflectionMethod;

class RateLimitComponentHook extends ComponentHook
{
    protected array $rateLimitedMethods = [];

    protected array $rateLimitConfig = [];

    public function skip(): bool
    {
        // Only apply to components using the WithRateLimiting trait
        return !in_array(WithRateLimiting::class, class_uses_recursive($this->component));
    }

    public function boot(): void
    {
        $this->discoverRateLimitedMethods();
        $this->configureComponentRateLimiting();
    }

    protected function discoverRateLimitedMethods(): void
    {
        $reflection = new ReflectionClass($this->component);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RateLimit::class);

            if (!empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                $this->rateLimitedMethods[$method->getName()] = [
                    'limiter' => $attribute->limiter,
                    'maxAttempts' => $attribute->maxAttempts,
                    'decayMinutes' => $attribute->decayMinutes,
                    'key' => $attribute->key,
                    'message' => $attribute->message,
                    'responseType' => $attribute->responseType,
                    'perAction' => $attribute->perAction,
                    'shareLimit' => $attribute->shareLimit,
                ];
            }
        }
    }

    protected function configureComponentRateLimiting(): void
    {
        $reflection = new ReflectionClass($this->component);
        $attributes = $reflection->getAttributes(RateLimit::class);

        if (!empty($attributes)) {
            $attribute = $attributes[0]->newInstance();
            $this->rateLimitConfig = [
                'enabled' => true,
                'limiter' => $attribute->limiter,
                'maxAttempts' => $attribute->maxAttempts,
                'decayMinutes' => $attribute->decayMinutes,
                'key' => $attribute->key,
                'message' => $attribute->message,
                'responseType' => $attribute->responseType,
                'perAction' => $attribute->perAction,
                'shareLimit' => $attribute->shareLimit,
            ];
        } elseif (property_exists($this->component, 'rateLimits')) {
            $this->rateLimitConfig = array_merge(['enabled' => true], $this->component->rateLimits);
        }
    }

    public function call($method, $params, $returnEarly, $metadata, $componentContext): ?callable
    {
        if ($this->shouldCheckRateLimit($method)) {
            $this->checkRateLimit($method, $returnEarly);
        }

        return null;
    }

    protected function shouldCheckRateLimit(string $method): bool
    {
        if (!config('livewire-rate-limiter.component_defaults.enabled', true)) {
            return false;
        }

        if (isset($this->rateLimitedMethods[$method])) {
            return true;
        }

        return !empty($this->rateLimitConfig) && ($this->rateLimitConfig['enabled'] ?? false);
    }

    protected function checkRateLimit(string $method, callable $returnEarly): void
    {
        $manager = app(RateLimiterManager::class);
        $key = $this->getRateLimitKey($method);
        $config = $this->getMergedRateLimitConfig($method);

        $attempt = $manager->attempt(
            $key,
            $config['limiter'] ?? null,
            null,
            $config['maxAttempts'] ?? null,
            $config['decayMinutes'] ?? null
        );

        if (!$attempt) {
            $this->handleRateLimitExceeded(
                $method,
                $manager->retryAfter($key, $config['limiter'] ?? null, $config['decayMinutes'] ?? null),
                $returnEarly
            );
        }
    }

    protected function getRateLimitKey(string $method): string
    {
        $config = $this->getMergedRateLimitConfig($method);

        if ($config['key'] ?? null) {
            return $this->resolveRateLimitKey($config['key'], $method);
        }

        $keyResolver = app(KeyResolver::class);
        $baseKey = $keyResolver->resolve();

        if ($config['perAction'] ?? true) {
            return sprintf('%s:%s:%s', $baseKey, get_class($this->component), $method);
        }

        return sprintf('%s:%s', $baseKey, get_class($this->component));
    }

    protected function resolveRateLimitKey(string $key, string $method): string
    {
        $replacements = [
            '{user}' => auth()->id() ?? 'guest',
            '{ip}' => request()->ip(),
            '{session}' => session()->getId(),
            '{component}' => get_class($this->component),
            '{method}' => $method,
            '{action}' => $method,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $key);
    }

    protected function getRateLimitConfig(string $method): array
    {
        if (isset($this->rateLimitedMethods[$method])) {
            return $this->rateLimitedMethods[$method];
        }

        return $this->rateLimitConfig;
    }

    protected function getMergedRateLimitConfig(string $method): array
    {
        $config = $this->getRateLimitConfig($method);

        if (!empty($config['limiter'])) {
            $limiterConfig = config("livewire-rate-limiter.limiters.{$config['limiter']}", []);

            $mappedLimiterConfig = [
                'maxAttempts' => $limiterConfig['attempts'] ?? null,
                'decayMinutes' => $limiterConfig['decay_minutes'] ?? null,
                'responseType' => $limiterConfig['response_type'] ?? null,
            ];

            foreach ($mappedLimiterConfig as $key => $value) {
                if ($value !== null && !isset($config[$key])) {
                    $config[$key] = $value;
                }
            }
        }

        return $config;
    }

    protected function handleRateLimitExceeded(string $method, int $retryAfter, callable $returnEarly): void
    {
        $config = $this->getMergedRateLimitConfig($method);
        $responseType = $config['responseType'] ?? config('livewire-rate-limiter.response_strategy', 'validation_error');
        $message = $this->getRateLimitMessage($retryAfter, $config['message'] ?? null);

        // Always return early to prevent the method from executing
        $returnEarly();

        switch ($responseType) {
            case 'exception':
                throw new RateLimitExceededException($message, $retryAfter);

            case 'validation_error':
                $this->component->addError('rateLimited', $message);
                break;

            case 'event':
                $this->component->dispatch('rateLimitExceeded', [
                    'method' => $method,
                    'retryAfter' => $retryAfter,
                    'message' => $message,
                ]);
                break;

            case 'notification':
                $this->component->dispatch('notify', [
                    'type' => 'error',
                    'message' => $message,
                ]);
                break;

            case 'silent':
                // Do nothing, just prevent the action
                break;

            default:
                $this->component->addError('rateLimited', $message);
        }
    }

    protected function getRateLimitMessage(int $seconds, ?string $customMessage = null): string
    {
        if ($customMessage) {
            return str_replace([':seconds', ':minutes', ':hours'], [
                $seconds,
                ceil($seconds / 60),
                ceil($seconds / 3600),
            ], $customMessage);
        }

        $messages = config('livewire-rate-limiter.messages', []);

        if ($seconds < 60) {
            $message = $messages['rate_limit_exceeded'] ?? 'Too many requests. Please try again in :seconds seconds.';
            return str_replace(':seconds', (string) $seconds, $message);
        } elseif ($seconds < 3600) {
            $minutes = (int) ceil($seconds / 60);
            $message = $messages['rate_limit_exceeded_minutes'] ?? 'Too many requests. Please try again in :minutes minutes.';
            return str_replace(':minutes', (string) $minutes, $message);
        } else {
            $hours = (int) ceil($seconds / 3600);
            $message = $messages['rate_limit_exceeded_hours'] ?? 'Too many requests. Please try again in :hours hours.';
            return str_replace(':hours', (string) $hours, $message);
        }
    }
}
