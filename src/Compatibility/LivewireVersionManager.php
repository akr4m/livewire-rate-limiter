<?php

namespace Akr4m\LivewireRateLimiter\Compatibility;

use Livewire\Livewire;
use Livewire\Component;
use ReflectionClass;

class LivewireVersionManager
{
    protected static ?string $version = null;
    protected static ?bool $isV4 = null;

    /**
     * Detect Livewire version.
     */
    public static function detectVersion(): string
    {
        if (static::$version !== null) {
            return static::$version;
        }

        // Check for Livewire v4 specific classes/methods
        if (class_exists('Livewire\Mechanisms\ComponentRegistry')) {
            static::$version = '4.x';
            static::$isV4 = true;
        } elseif (method_exists(Livewire::class, 'isLivewireRequestTestingOverride')) {
            static::$version = '3.5+';
            static::$isV4 = false;
        } else {
            static::$version = '3.x';
            static::$isV4 = false;
        }

        return static::$version;
    }

    /**
     * Check if running Livewire v4.
     */
    public static function isV4(): bool
    {
        if (static::$isV4 === null) {
            static::detectVersion();
        }

        return static::$isV4;
    }

    /**
     * Register middleware based on version.
     */
    public static function registerMiddleware(array $middleware): void
    {
        if (static::isV4()) {
            // Livewire v4 middleware registration
            if (method_exists(Livewire::class, 'addPersistentMiddleware')) {
                Livewire::addPersistentMiddleware($middleware);
            } else {
                // Fallback for v4 beta changes
                foreach ($middleware as $mw) {
                    app('livewire')->addMiddleware($mw);
                }
            }
        } else {
            // Livewire v3 middleware registration
            Livewire::addPersistentMiddleware($middleware);
        }
    }

    /**
     * Register lifecycle hooks based on version.
     */
    public static function registerHooks(array $hooks): void
    {
        if (static::isV4()) {
            // v4 uses a different event system
            foreach ($hooks as $event => $callback) {
                static::registerV4Hook($event, $callback);
            }
        } else {
            // v3 hooks
            foreach ($hooks as $event => $callback) {
                Livewire::listen($event, $callback);
            }
        }
    }

    /**
     * Register v4 specific hook.
     */
    protected static function registerV4Hook(string $event, callable $callback): void
    {
        // v4 beta may change this
        switch ($event) {
            case 'component.dehydrate':
                // v4 equivalent
                Livewire::listen('dehydrate', $callback);
                break;

            case 'component.hydrate':
                // v4 equivalent
                Livewire::listen('hydrate', $callback);
                break;

            case 'component.updating':
                // v4 uses property hooks
                Livewire::listen('property.updating', $callback);
                break;

            default:
                // Try direct registration
                Livewire::listen($event, $callback);
        }
    }

    /**
     * Get component fingerprint/ID based on version.
     */
    public static function getComponentId(Component $component): string
    {
        if (static::isV4()) {
            // v4 uses a different ID system
            return $component->getId();
        } else {
            // v3 fingerprint
            if (method_exists($component, 'getFingerprint')) {
                return $component->getFingerprint();
            }

            return $component->id;
        }
    }

    /**
     * Extract request data based on version.
     */
    public static function extractRequestData(array $payload): array
    {
        if (static::isV4()) {
            // v4 request structure
            return [
                'component' => $payload['snapshot']['memo']['name'] ?? null,
                'method' => static::extractV4Method($payload),
                'data' => $payload['snapshot']['data'] ?? [],
                'updates' => $payload['updates'] ?? [],
            ];
        } else {
            // v3 request structure
            return [
                'component' => $payload['components'][0]['snapshot']['memo']['name'] ?? null,
                'method' => static::extractV3Method($payload),
                'data' => $payload['components'][0]['snapshot']['data'] ?? [],
                'updates' => $payload['components'][0]['updates'] ?? [],
            ];
        }
    }

    /**
     * Extract method from v4 request.
     */
    protected static function extractV4Method(array $payload): ?string
    {
        foreach ($payload['updates'] ?? [] as $update) {
            if ($update['type'] === 'callMethod') {
                return $update['payload']['method'] ?? null;
            }
        }

        return null;
    }

    /**
     * Extract method from v3 request.
     */
    protected static function extractV3Method(array $payload): ?string
    {
        $updates = $payload['components'][0]['updates'] ?? [];

        foreach ($updates as $update) {
            if ($update['type'] === 'callMethod') {
                return $update['payload']['method'] ?? null;
            }
        }

        return null;
    }

    /**
     * Handle response based on version.
     */
    public static function handleResponse($component, array $data): void
    {
        if (static::isV4()) {
            // v4 response handling
            if (isset($data['error'])) {
                $component->addError($data['field'] ?? 'default', $data['error']);
            }

            if (isset($data['event'])) {
                $component->dispatch($data['event'], $data['params'] ?? []);
            }
        } else {
            // v3 response handling
            if (isset($data['error'])) {
                $component->addError($data['field'] ?? 'default', $data['error']);
            }

            if (isset($data['event'])) {
                $component->emit($data['event'], ...$data['params'] ?? []);
            }
        }
    }

    /**
     * Check if feature is supported in current version.
     */
    public static function isFeatureSupported(string $feature): bool
    {
        $features = [
            'reactive' => static::isV4(),
            'locked_properties' => static::isV4(),
            'computed_properties' => static::isV4(),
            'wire_model_defer' => !static::isV4(), // Removed in v4
            'emit' => !static::isV4(), // Replaced by dispatch in v4
            'dispatch' => static::isV4(),
            'persistent_middleware' => true,
            'dehydrate_hook' => true,
        ];

        return $features[$feature] ?? false;
    }

    /**
     * Get version-specific configuration.
     */
    public static function getConfig(string $key)
    {
        $configs = [
            'v4' => [
                'event_method' => 'dispatch',
                'id_method' => 'getId',
                'request_path' => 'snapshot.memo.name',
            ],
            'v3' => [
                'event_method' => 'emit',
                'id_method' => 'id',
                'request_path' => 'components.0.snapshot.memo.name',
            ],
        ];

        $version = static::isV4() ? 'v4' : 'v3';

        return $configs[$version][$key] ?? null;
    }
}
