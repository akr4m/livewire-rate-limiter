<?php

namespace Akr4m\LivewireRateLimiter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Akr4m\LivewireRateLimiter\RateLimiterManager;

class ShowRateLimitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rate-limit:show
                            {--key= : Show specific key pattern}
                            {--user= : Show limits for specific user ID}
                            {--active : Show only active (consumed) limits}
                            {--exhausted : Show only exhausted limits}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display current rate limit statuses';

    protected RateLimiterManager $manager;

    /**
     * Execute the console command.
     */
    public function handle(RateLimiterManager $manager): int
    {
        $this->manager = $manager;

        $store = Cache::store(config('livewire-rate-limiter.cache_store'));

        if (!method_exists($store, 'connection')) {
            $this->error('This command requires Redis cache driver for full functionality.');
            return Command::FAILURE;
        }

        $pattern = 'livewire_rate_limit:*';

        if ($key = $this->option('key')) {
            $pattern = "livewire_rate_limit:*{$key}*";
        } elseif ($userId = $this->option('user')) {
            $pattern = "livewire_rate_limit:*user:{$userId}*";
        }

        $keys = $store->connection()->command('KEYS', [$pattern]);

        if (empty($keys)) {
            $this->info('No rate limits found.');
            return Command::SUCCESS;
        }

        $limits = $this->collectLimits($keys, $store);

        // Filter based on options
        if ($this->option('active')) {
            $limits = array_filter($limits, fn($l) => $l['attempts'] > 0);
        } elseif ($this->option('exhausted')) {
            $limits = array_filter($limits, fn($l) => $l['remaining'] === 0);
        }

        $this->displayLimits($limits);

        return Command::SUCCESS;
    }

    /**
     * Collect limit information from cache keys.
     */
    protected function collectLimits(array $keys, $store): array
    {
        $limits = [];

        foreach ($keys as $key) {
            // Skip expiry tracking keys
            if (str_contains($key, ':expires_at')) {
                continue;
            }

            $value = $store->get($key);
            $parts = explode(':', str_replace('livewire_rate_limit:', '', $key));

            $limiter = $parts[0] ?? 'unknown';
            $identifier = implode(':', array_slice($parts, 1));

            // Get limiter config
            $config = config("livewire-rate-limiter.limiters.{$limiter}", []);
            $maxAttempts = $config['attempts'] ?? 60;

            // Calculate remaining
            $attempts = is_array($value) ? count($value) : (int)$value;
            $remaining = max(0, $maxAttempts - $attempts);

            // Get TTL
            $ttl = $store->connection()->command('TTL', [$key]);

            $limits[] = [
                'key' => $key,
                'limiter' => $limiter,
                'identifier' => $identifier,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'remaining' => $remaining,
                'ttl' => $ttl > 0 ? $ttl : 0,
                'expires_in' => $ttl > 0 ? $this->formatTime($ttl) : 'expired',
                'exhausted' => $remaining === 0,
            ];
        }

        return $limits;
    }

    /**
     * Display limits in a table.
     */
    protected function displayLimits(array $limits): void
    {
        if (empty($limits)) {
            $this->info('No rate limits match the criteria.');
            return;
        }

        $headers = [
            'Limiter',
            'Identifier',
            'Attempts',
            'Remaining',
            'Expires In',
            'Status'
        ];

        $rows = array_map(function ($limit) {
            return [
                $limit['limiter'],
                $this->truncate($limit['identifier'], 40),
                "{$limit['attempts']}/{$limit['max_attempts']}",
                $limit['remaining'],
                $limit['expires_in'],
                $limit['exhausted'] ? '<fg=red>EXHAUSTED</>' : '<fg=green>ACTIVE</>',
            ];
        }, $limits);

        $this->table($headers, $rows);

        $this->newLine();
        $this->info('Total active limits: ' . count($limits));
        $exhausted = count(array_filter($limits, fn($l) => $l['exhausted']));
        if ($exhausted > 0) {
            $this->warn("Exhausted limits: {$exhausted}");
        }
    }

    /**
     * Format time in seconds to human-readable.
     */
    protected function formatTime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $seconds = $seconds % 60;
            return "{$minutes}m {$seconds}s";
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return "{$hours}h {$minutes}m";
        }
    }

    /**
     * Truncate string with ellipsis.
     */
    protected function truncate(string $string, int $length): string
    {
        if (strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length - 3) . '...';
    }
}
