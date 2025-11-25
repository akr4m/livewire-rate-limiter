<?php

namespace Akr4m\LivewireRateLimiter\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Akr4m\LivewireRateLimiter\RateLimiterManager;

class ClearRateLimitsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rate-limit:clear
                            {--key= : Clear specific key pattern}
                            {--user= : Clear limits for specific user ID}
                            {--ip= : Clear limits for specific IP}
                            {--component= : Clear limits for specific component}
                            {--all : Clear all rate limits (dangerous!)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear rate limits from cache';

    /**
     * Execute the console command.
     */
    public function handle(RateLimiterManager $manager): int
    {
        if ($this->option('all')) {
            if (!$this->confirm('This will clear ALL rate limits. Are you sure?')) {
                return Command::FAILURE;
            }

            $this->clearAll();
            $this->info('All rate limits have been cleared.');
            return Command::SUCCESS;
        }

        if ($key = $this->option('key')) {
            $this->clearByPattern($key);
            $this->info("Rate limits matching '{$key}' have been cleared.");
            return Command::SUCCESS;
        }

        if ($userId = $this->option('user')) {
            $this->clearByUser($userId);
            $this->info("Rate limits for user {$userId} have been cleared.");
            return Command::SUCCESS;
        }

        if ($ip = $this->option('ip')) {
            $this->clearByIp($ip);
            $this->info("Rate limits for IP {$ip} have been cleared.");
            return Command::SUCCESS;
        }

        if ($component = $this->option('component')) {
            $this->clearByComponent($component);
            $this->info("Rate limits for component {$component} have been cleared.");
            return Command::SUCCESS;
        }

        $this->error('Please specify what to clear (use --help for options).');
        return Command::FAILURE;
    }

    /**
     * Clear all rate limits.
     */
    protected function clearAll(): void
    {
        $store = Cache::store(config('livewire-rate-limiter.cache_store'));

        // If using Redis, we can use pattern matching
        if (method_exists($store, 'connection')) {
            $store->connection()->command('DEL', array_merge(
                ['livewire_rate_limit:*'],
                $store->connection()->command('KEYS', ['livewire_rate_limit:*'])
            ));
        } else {
            // For other stores, we need to track keys differently
            // This is a limitation of non-Redis stores
            $this->warn('Complete clearing is only fully supported with Redis cache driver.');
            Cache::flush(); // Nuclear option - clears entire cache
        }
    }

    /**
     * Clear by pattern.
     */
    protected function clearByPattern(string $pattern): void
    {
        $store = Cache::store(config('livewire-rate-limiter.cache_store'));

        if (method_exists($store, 'connection')) {
            $keys = $store->connection()->command('KEYS', ["livewire_rate_limit:*{$pattern}*"]);
            if (!empty($keys)) {
                $store->connection()->command('DEL', $keys);
            }
        } else {
            $this->warn('Pattern matching is only supported with Redis cache driver.');
        }
    }

    /**
     * Clear by user ID.
     */
    protected function clearByUser(string $userId): void
    {
        $this->clearByPattern("user:{$userId}");
    }

    /**
     * Clear by IP address.
     */
    protected function clearByIp(string $ip): void
    {
        $hashedIp = hash('sha256', $ip);
        $this->clearByPattern("ip:{$hashedIp}");
    }

    /**
     * Clear by component.
     */
    protected function clearByComponent(string $component): void
    {
        $this->clearByPattern($component);
    }
}
