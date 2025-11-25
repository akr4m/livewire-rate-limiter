<?php

namespace Akr4m\LivewireRateLimiter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Akr4m\LivewireRateLimiter\RateLimiterManager;
use Akr4m\LivewireRateLimiter\Exceptions\RateLimitExceededException;
use Symfony\Component\HttpFoundation\Response;

class LivewireRateLimitMiddleware
{
    protected RateLimiterManager $rateLimiter;

    public function __construct(RateLimiterManager $rateLimiter)
    {
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $limiter = null): Response
    {
        // Skip if not a Livewire request
        if (!$this->isLivewireRequest($request)) {
            return $next($request);
        }

        // Extract component and method from request
        $component = $this->getComponentName($request);
        $method = $this->getMethodName($request);

        // Build rate limit key
        $keyResolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);
        $baseKey = $keyResolver->resolve();
        $key = sprintf('%s:%s:%s', $baseKey, $component, $method ?: 'render');

        // Check rate limit
        $limiter = $limiter ?? config('livewire-rate-limiter.default');

        if (!$this->rateLimiter->attempt($key, $limiter)) {
            $retryAfter = $this->rateLimiter->retryAfter($key, $limiter);
            $remaining = $this->rateLimiter->remaining($key, $limiter);

            // Add headers
            return $this->rateLimitResponse($request, $retryAfter, $remaining, $limiter);
        }

        $response = $next($request);

        // Add rate limit headers to successful response
        if (config('livewire-rate-limiter.headers.enabled')) {
            $remaining = $this->rateLimiter->remaining($key, $limiter);
            $limit = config("livewire-rate-limiter.limiters.{$limiter}.attempts", 60);

            $response->headers->set(
                config('livewire-rate-limiter.headers.remaining', 'X-RateLimit-Remaining'),
                $remaining
            );
            $response->headers->set(
                config('livewire-rate-limiter.headers.limit', 'X-RateLimit-Limit'),
                $limit
            );
        }

        return $response;
    }

    /**
     * Check if this is a Livewire request.
     */
    protected function isLivewireRequest(Request $request): bool
    {
        return $request->header('X-Livewire') === 'true' ||
            str_contains($request->path(), 'livewire/');
    }

    /**
     * Extract component name from request.
     */
    protected function getComponentName(Request $request): string
    {
        // For Livewire v3/v4
        $payload = $request->input('components.0.snapshot', []);

        if (isset($payload['memo']['name'])) {
            return $payload['memo']['name'];
        }

        // Fallback to path parsing
        if (preg_match('/livewire\/message\/(.+)/', $request->path(), $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * Extract method name from request.
     */
    protected function getMethodName(Request $request): ?string
    {
        // For Livewire v3/v4
        $updates = $request->input('components.0.updates', []);

        foreach ($updates as $update) {
            if ($update['type'] === 'callMethod') {
                return $update['payload']['method'] ?? null;
            }
        }

        return null;
    }

    /**
     * Generate rate limit exceeded response.
     */
    protected function rateLimitResponse(
        Request $request,
        int $retryAfter,
        int $remaining,
        string $limiter
    ): Response {
        $message = $this->getRateLimitMessage($retryAfter);

        if ($request->expectsJson() || $this->isLivewireRequest($request)) {
            $response = response()->json([
                'message' => $message,
                'retry_after' => $retryAfter,
                'remaining' => $remaining,
            ], 429);
        } else {
            $response = response($message, 429);
        }

        // Add headers
        if (config('livewire-rate-limiter.headers.enabled')) {
            $limit = config("livewire-rate-limiter.limiters.{$limiter}.attempts", 60);

            $response->headers->set(
                config('livewire-rate-limiter.headers.retry_after', 'X-RateLimit-RetryAfter'),
                $retryAfter
            );
            $response->headers->set(
                config('livewire-rate-limiter.headers.remaining', 'X-RateLimit-Remaining'),
                0
            );
            $response->headers->set(
                config('livewire-rate-limiter.headers.limit', 'X-RateLimit-Limit'),
                $limit
            );
            $response->headers->set('Retry-After', $retryAfter);
        }

        return $response;
    }

    /**
     * Get user-friendly rate limit message.
     */
    protected function getRateLimitMessage(int $seconds): string
    {
        if ($seconds < 60) {
            return trans('livewire-rate-limiter::messages.rate_limit_exceeded', ['seconds' => $seconds]);
        } elseif ($seconds < 3600) {
            $minutes = ceil($seconds / 60);
            return trans('livewire-rate-limiter::messages.rate_limit_exceeded_minutes', ['minutes' => $minutes]);
        } else {
            $hours = ceil($seconds / 3600);
            return trans('livewire-rate-limiter::messages.rate_limit_exceeded_hours', ['hours' => $hours]);
        }
    }
}
