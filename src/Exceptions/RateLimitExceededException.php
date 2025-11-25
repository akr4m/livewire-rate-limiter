<?php

namespace Akr4m\LivewireRateLimiter\Exceptions;

use Exception;

class RateLimitExceededException extends Exception
{
    protected int $retryAfter;

    public function __construct(string $message = '', int $retryAfter = 0, int $code = 429, ?Exception $previous = null)
    {
        $this->retryAfter = $retryAfter;

        if (empty($message)) {
            $message = "Rate limit exceeded. Please try again in {$retryAfter} seconds.";
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the number of seconds until the rate limit resets.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }

    /**
     * Get the response headers that should be sent.
     */
    public function getHeaders(): array
    {
        return [
            'X-RateLimit-RetryAfter' => $this->retryAfter,
            'Retry-After' => $this->retryAfter,
        ];
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'retry_after' => $this->retryAfter,
            ], 429, $this->getHeaders());
        }

        return redirect()->back()
            ->withErrors(['rate_limit' => $this->getMessage()])
            ->withHeaders($this->getHeaders());
    }
}
