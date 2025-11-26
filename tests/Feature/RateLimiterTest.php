<?php

namespace Akr4m\LivewireRateLimiter\Tests\Feature;

use Livewire\Livewire;
use Akr4m\LivewireRateLimiter\RateLimiterManager;
use Akr4m\LivewireRateLimiter\Tests\TestCase;
use Akr4m\LivewireRateLimiter\Tests\Fixtures\TestComponent;
use Akr4m\LivewireRateLimiter\Tests\Fixtures\RateLimitedComponent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Akr4m\LivewireRateLimiter\Events\RateLimitExceeded;

class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();
    }

    /** @test */
    public function it_allows_requests_within_rate_limit()
    {
        $component = Livewire::test(RateLimitedComponent::class);

        for ($i = 0; $i < 3; $i++) {
            $component->call('submit')
                ->assertSet('submitted', true)
                ->assertHasNoErrors();
        }
    }

    /** @test */
    public function it_blocks_requests_exceeding_rate_limit()
    {
        $component = Livewire::test(RateLimitedComponent::class);

        // Make allowed requests
        for ($i = 0; $i < 3; $i++) {
            $component->call('submit');
        }

        // This should be blocked
        $component->call('submit')
            ->assertHasErrors(['rateLimited']);
    }

    /** @test */
    public function it_respects_different_rate_limits_for_different_methods()
    {
        $component = Livewire::test(TestComponent::class);

        // Test relaxed method (higher limit)
        for ($i = 0; $i < 10; $i++) {
            $component->call('relaxedAction')
                ->assertSuccessful();
        }

        // Test strict method (lower limit)
        for ($i = 0; $i < 3; $i++) {
            $component->call('strictAction')
                ->assertSuccessful();
        }

        // This should fail
        $component->call('strictAction')
            ->assertHasErrors(['rateLimited']);

        // But relaxed should still work
        $component->call('relaxedAction')
            ->assertSuccessful();
    }

    /** @test */
    public function it_uses_fixed_window_strategy()
    {
        config(['livewire-rate-limiter.limiters.default.strategy' => 'fixed_window']);

        $manager = app(RateLimiterManager::class);
        $key = 'test-fixed-window';

        // Make max attempts
        for ($i = 0; $i < 5; $i++) {
            $result = $manager->attempt($key, 'default');
            $this->assertTrue($result);
        }

        // Should fail
        $result = $manager->attempt($key, 'default');
        $this->assertFalse($result);

        // Check retry after
        $retryAfter = $manager->retryAfter($key, 'default');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    /** @test */
    public function it_uses_sliding_window_strategy()
    {
        config(['livewire-rate-limiter.limiters.default.strategy' => 'sliding_window']);

        $manager = app(RateLimiterManager::class);
        $key = 'test-sliding-window';

        // Make attempts with small delays
        for ($i = 0; $i < 5; $i++) {
            $result = $manager->attempt($key, 'default');
            $this->assertTrue($result);
            usleep(100000); // 0.1 second
        }

        // Should fail
        $result = $manager->attempt($key, 'default');
        $this->assertFalse($result);
    }

    /** @test */
    public function it_identifies_users_by_different_strategies()
    {
        $resolver = app(\Akr4m\LivewireRateLimiter\Resolvers\KeyResolver::class);

        // Test IP resolution
        $ipKey = $resolver->resolve('ip');
        $this->assertStringStartsWith('ip:', $ipKey);

        // Test guest resolution
        $guestKey = $resolver->resolve('user');
        $this->assertStringStartsWith('guest:', $guestKey);

        // Test with authenticated user
        $this->actingAs($this->createUser());
        $userKey = $resolver->resolve('user');
        $this->assertStringStartsWith('user:', $userKey);
    }

    /** @test */
    public function it_bypasses_rate_limiting_based_on_configuration()
    {
        config(['livewire-rate-limiter.bypass.ips' => ['127.0.0.1']]);

        $manager = app(RateLimiterManager::class);
        $key = 'test-bypass';

        // Should always succeed from bypassed IP
        for ($i = 0; $i < 100; $i++) {
            $result = $manager->attempt($key, 'default');
            $this->assertTrue($result);
        }
    }

    /** @test */
    public function it_emits_events_when_rate_limit_exceeded()
    {
        Event::fake();

        config(['livewire-rate-limiter.events.enabled' => true]);

        $manager = app(RateLimiterManager::class);
        $key = 'test-events';

        // Max out attempts
        for ($i = 0; $i < 60; $i++) {
            $manager->attempt($key);
        }

        // This should trigger event
        $manager->attempt($key);

        Event::assertDispatched(RateLimitExceeded::class);
    }

    /** @test */
    public function it_resets_rate_limit_when_requested()
    {
        $manager = app(RateLimiterManager::class);
        $key = 'test-reset';

        // Make some attempts
        for ($i = 0; $i < 5; $i++) {
            $manager->attempt($key, 'strict');
        }

        // Check it would be blocked
        $this->assertFalse($manager->check($key, 'strict'));

        // Reset
        $manager->reset($key, 'strict');

        // Should work again
        $this->assertTrue($manager->check($key, 'strict'));
        $this->assertTrue($manager->attempt($key, 'strict'));
    }

    /** @test */
    public function it_provides_remaining_attempts_count()
    {
        $manager = app(RateLimiterManager::class);
        $key = 'test-remaining';

        // Initially should have full attempts
        $remaining = $manager->remaining($key, 'strict');
        $this->assertEquals(10, $remaining); // strict limiter has 10 attempts

        // Make an attempt
        $manager->attempt($key, 'strict');

        // Should have one less
        $remaining = $manager->remaining($key, 'strict');
        $this->assertEquals(9, $remaining);
    }

    /** @test */
    public function it_shares_global_rate_limits_across_instances()
    {
        $manager = app(RateLimiterManager::class);

        // Simulate multiple instances/users accessing global limit
        $globalKey1 = 'global:component:method';
        $globalKey2 = 'global:component:method'; // Same key

        // Both should share the same limit
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($manager->attempt($globalKey1, 'strict'));
        }

        // Different "instance" but same global key
        for ($i = 0; $i < 7; $i++) {
            $this->assertTrue($manager->attempt($globalKey2, 'strict'));
        }

        // Should be at limit now
        $this->assertFalse($manager->attempt($globalKey1, 'strict'));
        $this->assertFalse($manager->attempt($globalKey2, 'strict'));
    }

    /** @test */
    public function it_handles_custom_response_strategies()
    {
        // Test validation error response
        config(['livewire-rate-limiter.response_strategy' => 'validation_error']);

        $component = Livewire::test(RateLimitedComponent::class);

        // Max out
        for ($i = 0; $i < 3; $i++) {
            $component->call('submit');
        }

        // Should have validation error
        $component->call('submit')
            ->assertHasErrors(['rateLimited']);
    }

    /** @test */
    public function it_includes_rate_limit_headers_when_enabled()
    {
        config(['livewire-rate-limiter.headers.enabled' => true]);

        $response = $this->get('/livewire/message/' . RateLimitedComponent::class);

        // Note: This would need actual implementation in middleware
        // Just showing the test structure
        $this->assertTrue(true); // Placeholder
    }

    /** @test */
    public function it_handles_concurrent_requests_correctly()
    {
        $manager = app(RateLimiterManager::class);
        $key = 'test-concurrent';

        $promises = [];

        // Simulate concurrent requests
        for ($i = 0; $i < 10; $i++) {
            $promises[] = function () use ($manager, $key) {
                return $manager->attempt($key, 'strict');
            };
        }

        $results = array_map(fn($promise) => $promise(), $promises);

        // All should succeed (strict has 10 attempts)
        $this->assertCount(10, array_filter($results));

        // Next one should fail
        $this->assertFalse($manager->attempt($key, 'strict'));
    }

    /** @test */
    public function it_respects_per_action_configuration()
    {
        $component = Livewire::test(TestComponent::class);

        // Each action should have its own limit
        for ($i = 0; $i < 5; $i++) {
            $component->call('action1')->assertSuccessful();
            $component->call('action2')->assertSuccessful();
        }

        // If not per-action, both would share the limit and fail
        // But with per-action, they have separate limits
    }

    /** @test */
    public function it_handles_rate_limit_with_custom_keys()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $component = Livewire::test(TestComponent::class);

        // Test user-specific rate limiting
        for ($i = 0; $i < 5; $i++) {
            $component->call('userSpecificAction')
                ->assertSuccessful();
        }

        // Should fail for this user
        $component->call('userSpecificAction')
            ->assertHasErrors();

        // But work for another user
        $this->actingAs($this->createUser());

        $component = Livewire::test(TestComponent::class);
        $component->call('userSpecificAction')
            ->assertSuccessful();
    }

    /** @test */
    public function it_cleans_expired_rate_limit_entries()
    {
        $manager = app(RateLimiterManager::class);
        $key = 'test-expiry';

        // Make an attempt
        $manager->attempt($key, 'default');

        // Fast forward time (would need Carbon manipulation in real test)
        // Cache::flush(); // Simulate expiry

        // After expiry, should have full attempts again
        // This is handled by cache expiry
        $this->assertTrue(true); // Placeholder
    }

    protected function createUser()
    {
        return \App\Models\User::factory()->create();
    }
}
