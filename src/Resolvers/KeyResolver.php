<?php

namespace Akr4m\LivewireRateLimiter\Resolvers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KeyResolver
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Resolve the rate limit key based on configuration.
     */
    public function resolve(?string $strategy = null): string
    {
        $strategy = $strategy ?? config('livewire-rate-limiter.key_by', 'ip');

        if (is_array($strategy)) {
            return $this->resolveMultiple($strategy);
        }

        return match ($strategy) {
            'ip' => $this->resolveIp(),
            'user' => $this->resolveUser(),
            'session' => $this->resolveSession(),
            'fingerprint' => $this->resolveFingerprint(),
            'route' => $this->resolveRoute(),
            'custom' => $this->resolveCustom(),
            default => $this->resolveIp(),
        };
    }

    /**
     * Resolve multiple key strategies.
     */
    protected function resolveMultiple(array $strategies): string
    {
        $keys = [];

        foreach ($strategies as $strategy) {
            $keys[] = $this->resolve($strategy);
        }

        return implode(':', $keys);
    }

    /**
     * Resolve IP-based key.
     */
    protected function resolveIp(): string
    {
        // Handle proxies and load balancers
        $ip = $this->request->ip();

        // Check for Cloudflare
        if ($this->request->header('CF-Connecting-IP')) {
            $ip = $this->request->header('CF-Connecting-IP');
        }
        // Check for X-Forwarded-For
        elseif ($this->request->header('X-Forwarded-For')) {
            $ips = explode(',', $this->request->header('X-Forwarded-For'));
            $ip = trim($ips[0]);
        }
        // Check for X-Real-IP
        elseif ($this->request->header('X-Real-IP')) {
            $ip = $this->request->header('X-Real-IP');
        }

        return 'ip:' . hash('sha256', $ip);
    }

    /**
     * Resolve user-based key.
     */
    protected function resolveUser(): string
    {
        if (!auth()->check()) {
            return 'guest:' . $this->resolveIp();
        }

        return 'user:' . auth()->id();
    }

    /**
     * Resolve session-based key.
     */
    protected function resolveSession(): string
    {
        if (!session()->has('rate_limit_session_id')) {
            session()->put('rate_limit_session_id', Str::uuid()->toString());
        }

        return 'session:' . session()->get('rate_limit_session_id');
    }

    /**
     * Resolve browser fingerprint-based key.
     */
    protected function resolveFingerprint(): string
    {
        $fingerprint = $this->request->header('X-Browser-Fingerprint');

        if (!$fingerprint) {
            // Fallback to creating a fingerprint from available data
            $fingerprint = hash('sha256', implode('|', [
                $this->request->header('User-Agent', ''),
                $this->request->header('Accept-Language', ''),
                $this->request->header('Accept-Encoding', ''),
                $this->request->ip(),
            ]));
        }

        return 'fingerprint:' . $fingerprint;
    }

    /**
     * Resolve route-based key.
     */
    protected function resolveRoute(): string
    {
        $route = $this->request->route();

        if ($route) {
            return 'route:' . $route->getName() ?? $route->uri();
        }

        return 'route:' . $this->request->path();
    }

    /**
     * Resolve custom key using configured resolver.
     */
    protected function resolveCustom(): string
    {
        $resolver = config('livewire-rate-limiter.custom_key_resolver');

        if (!$resolver || !class_exists($resolver)) {
            return $this->resolveIp(); // Fallback
        }

        $instance = app($resolver);

        if (method_exists($instance, 'resolve')) {
            return $instance->resolve($this->request);
        }

        return $this->resolveIp(); // Fallback
    }

    /**
     * Generate a composite key from multiple sources.
     */
    public function composite(array $sources): string
    {
        $parts = [];

        foreach ($sources as $source => $value) {
            if (is_numeric($source)) {
                $parts[] = $this->resolve($value);
            } else {
                $parts[] = $source . ':' . $value;
            }
        }

        return implode(':', $parts);
    }

    /**
     * Get request instance.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }
}
