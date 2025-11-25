<?php

namespace App\Livewire;

use Livewire\Component;
use LivewireRateLimiter\Attributes\RateLimit;
use LivewireRateLimiter\Traits\WithRateLimiting;

/**
 * Example 1: Contact Form with rate limiting
 */
#[RateLimit(maxAttempts: 3, decayMinutes: 5)]
class ContactForm extends Component
{
    use WithRateLimiting;

    public string $name = '';
    public string $email = '';
    public string $message = '';

    protected $rules = [
        'name' => 'required|min:3',
        'email' => 'required|email',
        'message' => 'required|min:10',
    ];

    public function submit()
    {
        $this->validate();

        // Process the contact form
        // This method is automatically rate limited due to class-level attribute
        
        // Send email, save to database, etc.
        
        $this->reset();
        session()->flash('message', 'Contact form submitted successfully!');
    }

    public function render()
    {
        return view('livewire.contact-form');
    }
}

/**
 * Example 2: Using method-level rate limiting with different strategies
 */
class UserDashboard extends Component
{
    use WithRateLimiting;

    public $user;
    public $posts;

    // Relaxed rate limit for viewing
    #[RateLimit(maxAttempts: 100, decayMinutes: 1)]
    public function loadMorePosts()
    {
        $this->posts = auth()->user()->posts()->paginate(10);
    }

    // Strict rate limit for modifications
    #[RateLimit::strict()]
    public function deletePost($postId)
    {
        auth()->user()->posts()->findOrFail($postId)->delete();
        $this->loadMorePosts();
    }

    // Form-specific rate limit
    #[RateLimit::forForm(maxAttempts: 5, decayMinutes: 2)]
    public function updateProfile()
    {
        $this->validate([
            'user.name' => 'required',
            'user.email' => 'required|email',
        ]);

        auth()->user()->update($this->user);
    }

    // Per-user rate limiting
    #[RateLimit::perUser(maxAttempts: 10, decayMinutes: 1)]
    public function exportData()
    {
        // Export user data
        return response()->download(auth()->user()->exportData());
    }

    public function render()
    {
        return view('livewire.user-dashboard');
    }
}

/**
 * Example 3: Using configuration-based rate limiting
 */
class SearchComponent extends Component
{
    use WithRateLimiting;

    public string $query = '';
    public array $results = [];

    // Define rate limits via property
    protected array $rateLimits = [
        'enabled' => true,
        'limiter' => 'relaxed',
        'perAction' => true,
    ];

    public function search()
    {
        if (strlen($this->query) < 3) {
            return;
        }

        // This will be rate limited based on $rateLimits configuration
        $this->results = Product::search($this->query)->take(10)->get();
    }

    public function instantSearch()
    {
        // Real-time search with custom rate limiting
        $this->checkRateLimit('instantSearch');
        
        if (strlen($this->query) >= 2) {
            $this->results = Product::search($this->query)->take(5)->get();
        }
    }

    public function render()
    {
        return view('livewire.search-component', [
            'remaining' => $this->getRateLimitRemaining('search'),
        ]);
    }
}

/**
 * Example 4: API-like component with custom error handling
 */
class ApiDataFetcher extends Component
{
    use WithRateLimiting;

    public $data = [];
    public $loading = false;
    public $error = null;

    #[RateLimit::forApi(maxAttempts: 30, decayMinutes: 1)]
    public function fetchExternalData()
    {
        $this->loading = true;
        $this->error = null;

        try {
            // Simulate API call
            $response = Http::get('https://api.example.com/data');
            $this->data = $response->json();
        } catch (\Exception $e) {
            $this->error = 'Failed to fetch data';
        } finally {
            $this->loading = false;
        }
    }

    // Global rate limit shared across all instances
    #[RateLimit::global(maxAttempts: 1000, decayMinutes: 60)]
    public function fetchPublicData()
    {
        // This limit is shared across all users/instances
        $this->data = cache()->remember('public_data', 300, function () {
            return Http::get('https://api.example.com/public')->json();
        });
    }

    public function render()
    {
        return view('livewire.api-data-fetcher');
    }
}

/**
 * Example 5: Complex form with multiple rate-limited actions
 */
class MultiStepForm extends Component
{
    use WithRateLimiting;

    public int $currentStep = 1;
    public array $formData = [];

    // Different rate limits for different actions
    #[RateLimit(maxAttempts: 20, decayMinutes: 1, key: '{session}:step')]
    public function nextStep()
    {
        $this->validateStep();
        
        if ($this->currentStep < 5) {
            $this->currentStep++;
        }
    }

    #[RateLimit(maxAttempts: 20, decayMinutes: 1, key: '{session}:step')]
    public function previousStep()
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    #[RateLimit::forForm(maxAttempts: 3, decayMinutes: 10)]
    public function submit()
    {
        $this->validateAll();
        
        // Process the complete form
        DB::transaction(function () {
            // Save data
        });

        // Reset rate limit after successful submission
        $this->resetRateLimit('submit');
        
        session()->flash('success', 'Form submitted successfully!');
        $this->redirect('/success');
    }

    // Save draft without strict rate limiting
    #[RateLimit(maxAttempts: 30, decayMinutes: 1, responseType: 'silent')]
    public function saveDraft()
    {
        cache()->put('form_draft_' . auth()->id(), $this->formData, 3600);
        $this->dispatch('draft-saved');
    }

    protected function validateStep()
    {
        // Step-specific validation
    }

    protected function validateAll()
    {
        // Complete form validation
    }

    public function render()
    {
        return view('livewire.multi-step-form', [
            'canProceed' => $this->getRateLimitRemaining('nextStep') > 0,
            'remainingSubmissions' => $this->getRateLimitRemaining('submit'),
        ]);
    }
}

/**
 * Example 6: Admin component with bypass capabilities
 */
class AdminPanel extends Component
{
    use WithRateLimiting;

    public function mount()
    {
        // Admins can bypass rate limiting
        if (auth()->user()->isAdmin()) {
            $this->bypassRateLimitOnce();
        }
    }

    #[RateLimit(maxAttempts: 100, decayMinutes: 1)]
    public function performAdminAction()
    {
        // This will bypass rate limiting for admins due to mount() method
        // Regular users will still be rate limited
    }

    public function render()
    {
        return view('livewire.admin-panel');
    }
}
