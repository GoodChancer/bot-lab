<?php
declare(strict_types=1);

namespace Studio\Ai;

use Studio\Config;

/** Picks the provider and default models from config.php. */
final class Ai
{
    public const PROVIDERS = ['anthropic' => 'Claude (Anthropic)', 'openai' => 'OpenAI'];

    private static array $instances = [];

    public static function provider(string $name): Provider
    {
        if (!self::available($name)) {
            throw new AiError("No API key for $name", 'This bot\'s AI provider has no API key set up.');
        }
        return self::$instances[$name] ??= match ($name) {
            'anthropic' => new ClaudeProvider((string) Config::get('anthropic_api_key')),
            'openai' => new OpenAiProvider((string) Config::get('openai_api_key')),
            default => throw new AiError("Unknown provider $name"),
        };
    }

    /** Tests swap in a fake. */
    public static function fake(string $name, Provider $provider): void
    {
        self::$instances[$name] = $provider;
    }

    public static function available(string $name): bool
    {
        if (isset(self::$instances[$name])) {
            return true;
        }
        return match ($name) {
            'anthropic' => (string) Config::get('anthropic_api_key') !== '',
            'openai' => (string) Config::get('openai_api_key') !== '',
            default => false,
        };
    }

    /** Providers with a key, in preference order. */
    public static function availableProviders(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), [self::class, 'available']));
    }

    public static function chatModel(string $provider): string
    {
        return (string) Config::get("models.chat.$provider");
    }

    public static function generateModel(string $provider): string
    {
        return (string) Config::get("models.generate.$provider");
    }
}
