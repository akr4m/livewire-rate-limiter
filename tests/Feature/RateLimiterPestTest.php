<?php

use Livewire\Livewire;
use Akr4m\LivewireRateLimiter\RateLimiterManager;
use Akr4m\LivewireRateLimiter\Tests\Fixtures\RateLimitedComponent;
use Akr4m\LivewireRateLimiter\Events\RateLimitExceeded;
use Akr4m\LivewireRateLimiter\Exceptions\RateLimitExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use function Pest\Laravel\{actingAs};

beforeEach(function () {
    Cache::flush();
    $this->manager = app(RateLimiterManager::class);
});

describe('Rate Limiting Core Functionality', function () {

    test('allows requests within rate limit', function () {
        $component = Livewire::test(RateLimitedComponent::class);

        // Make 3 requests (default limit)
        for ($i = 0; $i < 3; $i++) {
            $component->call('submit')
                ->assertSet('submitted', true)
                ->assertHasNoErrors();
        }
    });

    test('blocks requests exceeding rate limit', function () {
        $component = Livewire::test(RateLimitedComponent::class);

        // Max out the limit
        for ($i = 0; $i < 3; $i++) {
            $component->call('submit');
        }

        // This should fail
        $component->call('submit')
            ->assertHasErrors(['rateLimited']);
    });

    test('respects different limits for different methods', function () {
        $component = Livewire::test(TestComponent::class);

        // Test method with higher limit
        for ($i = 0; $i < 10; $i++) {
            $component->call('relaxedAction')->assertSuccessful();
        }

        // Test method with lower limit
        for ($i = 0; $i < 3; $i++) {
            $component->call('strictAction')->assertSuccessful();
        }

        // Strict should fail
        $component->call('strictAction')->assertHasErrors();

        // But relaxed should still work
        $component->call('relaxedAction')->assertSuccessful();
    });
});

describe('Rate Limiting Strategies', function () {

    test('fixed window strategy blocks after limit', function () {
        config(['livewire-rate-limiter.limiters.default.strategy' => 'fixed_window']);

        $key = 'test-fixed';

        // Use all attempts
        for ($i = 0; $i < 5; $i++) {
            expect($this->manager->attempt($key, 'default'))->toBeTrue();
        }

        // Should be blocked
        expect($this->manager->attempt($key, 'default'))->toBeFalse();

        // Retry after should be positive
        expect($this->manager->retryAfter($key, 'default'))
            ->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(60);
    });

    test('sliding window strategy maintains accurate window', function () {
        config(['livewire-rate-limiter.limiters.default.strategy' => 'sliding_window']);

        $key = 'test-sliding';

        // Make attempts
        for ($i = 0; $i < 5; $i++) {
            expect($this->manager->attempt($key, 'default'))->toBeTrue();
            usleep(100000); // 0.1 second
        }

        // Should be blocked
        expect($this->manager->attempt($key, 'default'))->toBeFalse();
    });

    test('token bucket strategy refills over time', function () {
        config(['livewire-rate-limiter.limiters.default.strategy' => 'token_bucket']);

        $key = 'test-token';

        // Use tokens
        for ($i = 0; $i < 3; $i++) {
            expect($this->manager->attempt($key, 'default'))->toBeTrue();
        }

        // Should have fewer tokens
        expect($this->manager->remaining($key, 'default'))->toBeLessThan(60);
    });
});

describe('Key Resolution', function () {

    test('resolves IP-based keys correctly', function () {
        $resolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);

        $key = $resolver->resolve('ip');

        expect($key)
            ->toStartWith('ip:')
            ->toHaveLength(68); // 'ip:' + 64 char hash
    });

    test('resolves user-based keys for authenticated users', function () {
        $user = User::factory()->create();
        actingAs($user);

        $resolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);
        $key = $resolver->resolve('user');

        expect($key)->toBe("user:{$user->id}");
    });

    test('falls back to guest key for unauthenticated users', function () {
        $resolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);
        $key = $resolver->resolve('user');

        expect($key)->toStartWith('guest:ip:');
    });

    test('combines multiple key strategies', function () {
        $user = User::factory()->create();
        actingAs($user);

        $resolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);
        $key = $resolver->resolve(['user', 'ip']);

        expect($key)->toContain("user:{$user->id}");
        expect($key)->toContain(':ip:');
    });
});

describe('Bypass Mechanisms', function () {

    test('bypasses rate limiting in configured environments', function () {
        config(['livewire-rate-limiter.bypass.environments' => ['testing']]);

        $key = 'test-bypass-env';

        // Should always succeed in testing environment
        for ($i = 0; $i < 100; $i++) {
            expect($this->manager->attempt($key))->toBeTrue();
        }
    });

    test('bypasses rate limiting for whitelisted IPs', function () {
        config(['livewire-rate-limiter.bypass.ips' => ['127.0.0.1']]);

        $key = 'test-bypass-ip';

        // Should always succeed from localhost
        for ($i = 0; $i < 100; $i++) {
            expect($this->manager->attempt($key))->toBeTrue();
        }
    });

    test('bypasses rate limiting for specific users', function () {
        $admin = User::factory()->create(['is_admin' => true]);
        actingAs($admin);

        config(['livewire-rate-limiter.bypass.user_ids' => [$admin->id]]);

        $key = 'test-bypass-user';

        for ($i = 0; $i < 100; $i++) {
            expect($this->manager->attempt($key))->toBeTrue();
        }
    });
});

describe('Response Strategies', function () {

    test('throws exception when configured', function () {
        config(['livewire-rate-limiter.response_strategy' => 'exception']);

        $component = Livewire::test(RateLimitedComponent::class);

        // Max out
        for ($i = 0; $i < 3; $i++) {
            $component->call('submitWithException');
        }

        // Should throw
        expect(fn() => $component->call('submitWithException'))
            ->toThrow(RateLimitExceededException::class);
    });

    test('adds validation error when configured', function () {
        config(['livewire-rate-limiter.response_strategy' => 'validation_error']);

        $component = Livewire::test(RateLimitedComponent::class);

        // Max out
        for ($i = 0; $i < 3; $i++) {
            $component->call('submit');
        }

        // Should have validation error
        $component->call('submit')
            ->assertHasErrors(['rateLimited']);
    });

    test('dispatches event when configured', function () {
        Event::fake();

        config(['livewire-rate-limiter.response_strategy' => 'event']);

        $component = Livewire::test(RateLimitedComponent::class);

        // Max out
        for ($i = 0; $i < 3; $i++) {
            $component->call('submitWithEvent');
        }

        // Should dispatch event
        $component->call('submitWithEvent');

        Event::assertDispatched(RateLimitExceeded::class);
    });
});

describe('Cache Management', function () {

    test('resets rate limit when requested', function () {
        $key = 'test-reset';

        // Use some attempts
        for ($i = 0; $i < 5; $i++) {
            $this->manager->attempt($key, 'strict');
        }

        expect($this->manager->check($key, 'strict'))->toBeFalse();

        // Reset
        $this->manager->reset($key, 'strict');

        expect($this->manager->check($key, 'strict'))->toBeTrue();
    });

    test('provides accurate remaining count', function () {
        $key = 'test-remaining';

        expect($this->manager->remaining($key, 'strict'))->toBe(10);

        $this->manager->attempt($key, 'strict');

        expect($this->manager->remaining($key, 'strict'))->toBe(9);
    });

    test('calculates retry after correctly', function () {
        $key = 'test-retry';

        // Max out
        for ($i = 0; $i < 10; $i++) {
            $this->manager->attempt($key, 'strict');
        }

        $retryAfter = $this->manager->retryAfter($key, 'strict');

        expect($retryAfter)
            ->toBeGreaterThan(0)
            ->toBeLessThanOrEqual(60);
    });
});

describe('Component Integration', function () {

    test('discovers rate limited methods via attributes', function () {
        $component = new class extends Component {
            use \Akr4m\LivewireRateLimiter\Traits\WithRateLimiting;

            #[\Akr4m\LivewireRateLimiter\Attributes\RateLimit(maxAttempts: 5)]
            public function testMethod() {}

            public function render()
            {
                return '';
            }
        };

        $reflection = new ReflectionObject($component);
        $property = $reflection->getProperty('rateLimitedMethods');
        $property->setAccessible(true);

        expect($property->getValue($component))->toHaveKey('testMethod');
    });

    test('applies per-action rate limiting', function () {
        $component = Livewire::test(new class extends Component {
            use \Akr4m\LivewireRateLimiter\Traits\WithRateLimiting;

            public $action1Called = 0;
            public $action2Called = 0;

            #[\Akr4m\LivewireRateLimiter\Attributes\RateLimit(maxAttempts: 2)]
            public function action1()
            {
                $this->action1Called++;
            }

            #[\Akr4m\LivewireRateLimiter\Attributes\RateLimit(maxAttempts: 3)]
            public function action2()
            {
                $this->action2Called++;
            }

            public function render()
            {
                return '<div></div>';
            }
        });

        // action1 limit: 2
        $component->call('action1')->call('action1');
        $component->call('action1')->assertHasErrors();

        // action2 should still work (limit: 3)
        $component->call('action2')->call('action2')->call('action2');
        $component->call('action2')->assertHasErrors();
    });
});

describe('Performance and Concurrency', function () {

    test('handles concurrent requests correctly', function () {
        $key = 'test-concurrent';
        $results = [];

        // Simulate concurrent requests
        for ($i = 0; $i < 10; $i++) {
            $results[] = $this->manager->attempt($key, 'strict');
        }

        // All 10 should succeed (strict limit is 10)
        expect(array_filter($results))->toHaveCount(10);

        // Next should fail
        expect($this->manager->attempt($key, 'strict'))->toBeFalse();
    });

    test('performs efficiently with many keys', function () {
        $startTime = microtime(true);

        // Create many different keys
        for ($i = 0; $i < 1000; $i++) {
            $this->manager->attempt("key-{$i}", 'default');
        }

        $elapsed = microtime(true) - $startTime;

        // Should complete in reasonable time (< 1 second)
        expect($elapsed)->toBeLessThan(1.0);
    });
});
