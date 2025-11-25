# Laravel Livewire Rate Limiter

A flexible, configurable rate limiting package for Laravel Livewire components with support for multiple strategies and storage backends.

## Features

- 🚀 **Multiple Rate Limiting Strategies**: Fixed Window, Sliding Window, and Leaky Bucket
- 🎯 **Flexible Configuration**: Per-component, per-action, or global rate limits
- 💾 **Multiple Storage Backends**: Redis, Database, Array (via Laravel's cache abstraction)
- 🔧 **Developer-Friendly API**: Simple traits, attributes, and helper methods
- 🛡️ **Security-First**: IP-based, user-based, session-based, or custom key identification
- 🎨 **Customizable Responses**: Validation errors, exceptions, events, or silent handling
- 🧪 **Comprehensive Testing**: PHPUnit and Pest test suites included

## Requirements

- PHP 8.2+
- Laravel 12.x
- Livewire 3.5+ or 4.x (beta)

## Installation

Install the package via Composer:

```bash
composer require akr4m/livewire-rate-limiter
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=livewire-rate-limiter-config
```

Optionally, publish the language files:

```bash
php artisan vendor:publish --tag=livewire-rate-limiter-lang
```

## Quick Start

### 1. Add the trait to your Livewire component

```php
use LivewireRateLimiter\Traits\WithRateLimiting;

class ContactForm extends Component
{
    use WithRateLimiting;

    // Your component code
}
```

### 2. Apply rate limiting using attributes

```php
use LivewireRateLimiter\Attributes\RateLimit;

#[RateLimit(maxAttempts: 5, decayMinutes: 1)]
class ContactForm extends Component
{
    use WithRateLimiting;

    public function submit()
    {
        // This method is automatically rate limited
    }
}
```

### 3. Or apply to specific methods

```php
class UserDashboard extends Component
{
    use WithRateLimiting;

    #[RateLimit::forForm(maxAttempts: 3, decayMinutes: 5)]
    public function updateProfile()
    {
        // Rate limited to 3 attempts per 5 minutes
    }

    #[RateLimit::perUser(maxAttempts: 10, decayMinutes: 1)]
    public function exportData()
    {
        // Rate limited per user
    }
}
```

## Configuration

The configuration file (`config/livewire-rate-limiter.php`) provides extensive customization options:

### Define Custom Limiters

```php
'limiters' => [
    'strict' => [
        'attempts' => 5,
        'decay_minutes' => 10,
        'strategy' => 'sliding_window',
    ],
    'api' => [
        'attempts' => 60,
        'decay_minutes' => 1,
        'strategy' => 'fixed_window',
        'key_by' => ['user', 'ip'],
    ],
],
```

### Key Identification Strategies

```php
'key_by' => 'ip', // Options: 'ip', 'user', 'session', 'fingerprint', 'custom'
```

### Response Strategies

```php
'response_strategy' => 'validation_error', // Options: 'exception', 'validation_error', 'event', 'silent'
```

## Usage Examples

### Basic Form Protection

```php
#[RateLimit(maxAttempts: 3, decayMinutes: 5)]
class LoginForm extends Component
{
    use WithRateLimiting;

    public function login()
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Attempt login
    }
}
```

### Different Limits for Different Actions

```php
class ContentManager extends Component
{
    use WithRateLimiting;

    #[RateLimit::relaxed()] // 100 requests per minute
    public function loadMore()
    {
        // Load more content
    }

    #[RateLimit::strict()] // 10 requests per minute
    public function delete($id)
    {
        // Delete content
    }

    #[RateLimit::forForm()] // 5 requests per minute
    public function save()
    {
        // Save changes
    }
}
```

### Custom Key Strategies

```php
class DataExporter extends Component
{
    use WithRateLimiting;

    // Rate limit by user ID
    #[RateLimit(maxAttempts: 5, decayMinutes: 60, key: '{user}:export')]
    public function exportUserData()
    {
        // Export data
    }

    // Rate limit by IP address
    #[RateLimit(maxAttempts: 10, decayMinutes: 1, key: '{ip}:search')]
    public function search()
    {
        // Perform search
    }

    // Global rate limit (shared across all users)
    #[RateLimit::global(maxAttempts: 1000, decayMinutes: 60)]
    public function fetchPublicData()
    {
        // Fetch public data
    }
}
```

### Property-Based Configuration

```php
class SearchComponent extends Component
{
    use WithRateLimiting;

    protected array $rateLimits = [
        'enabled' => true,
        'limiter' => 'relaxed',
        'perAction' => true,
    ];

    public function search()
    {
        // Automatically rate limited based on property configuration
    }
}
```

### Manual Rate Limit Checking

```php
class AdvancedComponent extends Component
{
    use WithRateLimiting;

    public function performAction()
    {
        // Check remaining attempts
        $remaining = $this->getRateLimitRemaining('performAction');

        if ($remaining === 0) {
            $this->addError('limit', 'Please wait before trying again.');
            return;
        }

        // Manually check rate limit
        $this->checkRateLimit('performAction');

        // Perform action
    }

    public function resetUserLimit()
    {
        // Manually reset rate limit for a method
        $this->resetRateLimit('performAction');
    }
}
```

### Bypass Rate Limiting

```php
class AdminComponent extends Component
{
    use WithRateLimiting;

    public function mount()
    {
        if (auth()->user()->isAdmin()) {
            $this->bypassRateLimitOnce();
        }
    }

    #[RateLimit(maxAttempts: 10, decayMinutes: 1)]
    public function adminAction()
    {
        // Admins bypass this rate limit
    }
}
```

## Advanced Features

### Custom Rate Limiting Strategies

Create your own rate limiting strategy:

```php
use LivewireRateLimiter\RateLimiterManager;

class CustomStrategy
{
    public function attempt(string $key, int $maxAttempts, int $decayMinutes): array
    {
        // Your custom implementation
        return [
            'allowed' => true,
            'attempts' => 1,
            'remaining' => $maxAttempts - 1,
            'retry_after' => 0,
        ];
    }
}

// Register in a service provider
app(RateLimiterManager::class)->extend('custom', function ($cache, $config) {
    return new CustomStrategy($cache, $config);
});
```

### Custom Key Resolver

Create a custom key resolver for complex identification:

```php
class CustomKeyResolver
{
    public function resolve(Request $request): string
    {
        // Your custom logic
        return 'custom:' . $request->fingerprint();
    }
}

// Register in config
'custom_key_resolver' => CustomKeyResolver::class,
'key_by' => 'custom',
```

### Event Handling

Listen for rate limit events:

```php
use LivewireRateLimiter\Events\RateLimitExceeded;
use LivewireRateLimiter\Events\RateLimitAttempted;

// In your EventServiceProvider
protected $listen = [
    RateLimitExceeded::class => [
        SendRateLimitNotification::class,
        LogRateLimitViolation::class,
    ],
];
```

### Middleware for Routes

Apply rate limiting to Livewire routes:

```php
Route::get('/dashboard', Dashboard::class)
    ->middleware('livewire.rate.limit:strict');
```

Or enable globally in config:

```php
'auto_apply_to_routes' => true,
```

## Testing

The package includes comprehensive test coverage. Run tests with:

```bash
# PHPUnit
vendor/bin/phpunit

# Pest
vendor/bin/pest
```

### Testing Your Components

```php
use LivewireRateLimiter\Testing\WithRateLimitTesting;

class ContactFormTest extends TestCase
{
    use WithRateLimitTesting;

    public function test_rate_limit_is_enforced()
    {
        $this->fakeRateLimiting();

        Livewire::test(ContactForm::class)
            ->call('submit')
            ->assertSuccessful()
            ->call('submit')
            ->assertSuccessful()
            ->call('submit')
            ->assertSuccessful()
            ->call('submit') // 4th attempt
            ->assertHasErrors(['rateLimited']);
    }
}
```

## Console Commands

Clear all rate limits:

```bash
php artisan rate-limit:clear
```

Show current rate limits:

```bash
php artisan rate-limit:show
```

## Common Use Cases

### 1. Contact Forms

Prevent spam by limiting form submissions to 3 per 5 minutes.

### 2. API-like Endpoints

Implement API rate limiting for data fetching components.

### 3. Search Components

Prevent abuse of search functionality while allowing reasonable usage.

### 4. Export Functions

Limit resource-intensive operations like data exports.

### 5. User Actions

Different limits for different user actions (view vs. modify).

### 6. Multi-step Forms

Rate limit navigation between steps to prevent automation.

## Best Practices

1. **Choose appropriate strategies**: Use sliding window for more accurate rate limiting, fixed window for simplicity.

2. **Set reasonable limits**: Balance security with user experience.

3. **Use appropriate keys**: User-based for authenticated actions, IP-based for public forms.

4. **Provide clear feedback**: Show remaining attempts or retry time to users.

5. **Log violations**: Monitor rate limit hits to identify potential attacks.

6. **Test thoroughly**: Ensure rate limits work across different scenarios.

## Security Considerations

- The package hashes IP addresses for privacy
- Session IDs are generated securely
- Custom bypass callbacks should be carefully implemented
- Rate limits are enforced server-side, not client-side
- Consider using stricter limits for sensitive operations

## Troubleshooting

### Rate limits not working

- Check if the trait is properly included
- Verify configuration is published and correct
- Ensure cache driver is properly configured

### False positives

- Check if IP detection is working correctly behind proxies
- Verify session handling for guest users
- Consider using different key strategies

### Performance issues

- Use Redis for better performance at scale
- Consider caching strategy (sliding vs. fixed window)
- Monitor cache key proliferation

## Edge Cases & Caveats

### Cache Driver Limitations

- Array driver doesn't persist between requests
- File driver may have performance issues at scale
- Redis/Memcached recommended for production

### Load Balancer Considerations

- Ensure proper IP forwarding headers are configured
- Session affinity may be required for session-based limiting
- Consider using Redis for distributed rate limiting
