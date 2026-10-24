<?php
declare(strict_types=1);

namespace Studio;

/** Settings from config.php (written by bin/setup.php), merged over sensible defaults. */
final class Config
{
    /** Bump when the studio's pages change, so open browser tabs reload instead of running old code. */
    public const VERSION = '22';

    private static ?array $data = null;

    public static function defaults(): array
    {
        return [
            'base_url' => '',          // public URL of the public/ folder, no trailing slash
            'password_hash' => '',     // owner password (password_hash)
            'secret_key' => '',        // base64, 32 bytes: encrypts tokens stored in the database
            'anthropic_api_key' => '',
            'openai_api_key' => '',
            'data_dir' => STUDIO_ROOT . '/data',
            'history_limit' => 20,     // recent DM messages sent as conversation memory
            'demo_mode' => false,      // true: the sign-in page offers a read-only demo for visitors
            'models' => [
                // What each bot talks with by default (changeable per bot).
                'chat' => ['anthropic' => 'claude-haiku-5-5', 'openai' => 'gpt-5-mini'],
                // Writing personas and drawing avatars: quality matters more than cost here.
                'generate' => ['anthropic' => 'claude-opus-5-5', 'openai' => 'gpt-5'],
                'image' => ['openai' => 'gpt-image-2'],
            ],
        ];
    }

    public static function all(): array
    {
        if (self::$data === null) {
            $path = getenv('STUDIO_CONFIG') ?: STUDIO_ROOT . '/config.php';
            if (!is_file($path)) {
                throw new \RuntimeException('Missing config.php. Run: php bin/setup.php');
            }
            self::$data = array_replace_recursive(self::defaults(), require $path);
        }
        return self::$data;
    }

    /** Dot paths work: Config::get('models.chat.anthropic'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Tests replace the whole config. */
    public static function override(array $data): void
    {
        self::$data = array_replace_recursive(self::defaults(), $data);
    }

    public static function baseUrl(): string
    {
        return rtrim((string) self::get('base_url'), '/');
    }
}
