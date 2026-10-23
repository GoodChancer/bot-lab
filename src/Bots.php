<?php
declare(strict_types=1);

namespace Studio;

/** Bot records. Secret columns (ending in _enc) are encrypted; use secret() / setSecret(). */
final class Bots
{
    /** Columns the studio may edit directly. */
    public const EDITABLE = [
        'name', 'personality', 'short_description', 'long_description', 'background_color',
        'avatar_description', 'avatar_style', 'provider', 'model',
        'can_see_images', 'can_read_links', 'can_search_web', 'icon_background',
    ];
    public const FLAGS = ['can_see_images', 'can_read_links', 'can_search_web'];
    public const SECRETS = ['client_secret', 'signing_secret', 'bot_token'];

    public static function all(): array
    {
        return Db::query('SELECT * FROM bots ORDER BY created_at, id')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        return Db::query('SELECT * FROM bots WHERE id = ?', [$id])->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        return Db::query('SELECT * FROM bots WHERE slug = ?', [$slug])->fetch() ?: null;
    }

    public static function findByState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        return Db::query('SELECT * FROM bots WHERE oauth_state = ?', [$state])->fetch() ?: null;
    }

    public static function create(array $fields): array
    {
        $fields = self::clean($fields);
        if (($fields['name'] ?? '') === '') {
            throw new \InvalidArgumentException('Give the bot a name.');
        }
        $fields['slug'] = self::uniqueSlug($fields['slug'] ?? $fields['name']);
        $cols = array_keys($fields);
        Db::query(
            'INSERT INTO bots (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($fields)
        );
        return self::find((int) Db::pdo()->lastInsertId());
    }

    public static function update(int $id, array $fields): array
    {
        $fields = self::clean($fields);
        if ($fields) {
            $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($fields)));
            Db::query("UPDATE bots SET $set, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [...array_values($fields), $id]);
        }
        return self::find($id) ?? throw new \RuntimeException('Bot not found.');
    }

    /** For columns the studio doesn't let people edit (status, app_id, ...). */
    public static function setSystem(int $id, array $fields): void
    {
        $allowed = ['status', 'app_id', 'client_id', 'bot_user_id', 'team_id', 'oauth_state', 'icon_synced', 'granted_scopes',
            'avatar_source_mime', 'avatar_crop'];
        $fields = array_intersect_key($fields, array_flip($allowed));
        if ($fields) {
            $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($fields)));
            Db::query("UPDATE bots SET $set, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [...array_values($fields), $id]);
        }
    }

    public static function delete(int $id): void
    {
        $bot = self::find($id);
        Db::query('DELETE FROM bots WHERE id = ?', [$id]);
        if ($bot) {
            @unlink(self::avatarPath($bot['slug']));
            if ($bot['avatar_source_mime'] ?? '') {
                @unlink(self::sourcePath($bot['slug'], $bot['avatar_source_mime']));
            }
        }
    }

    public static function secret(array $bot, string $name): string
    {
        return Crypto::decrypt($bot[$name . '_enc'] ?? '');
    }

    public static function setSecret(int $id, string $name, string $value): void
    {
        if (!in_array($name, self::SECRETS, true)) {
            throw new \InvalidArgumentException("Unknown secret $name");
        }
        Db::query("UPDATE bots SET {$name}_enc = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", [$value === '' ? '' : Crypto::encrypt($value), $id]);
    }

    /** What the studio's pages may see: never the secrets themselves. */
    public static function public(array $bot): array
    {
        $out = array_diff_key($bot, array_flip(['client_secret_enc', 'signing_secret_enc', 'bot_token_enc', 'oauth_state']));
        $out['id'] = (int) $bot['id'];
        $out['has_avatar'] = is_file(self::avatarPath($bot['slug']));
        $out['has_bot_token'] = ($bot['bot_token_enc'] ?? '') !== '';
        foreach (self::FLAGS as $flag) {
            $out[$flag] = (bool) ($bot[$flag] ?? 0);
        }
        // Seeing images needs Slack's files:read permission, which only takes effect after a reinstall.
        $granted = (string) ($bot['granted_scopes'] ?? '');
        $out['needs_reinstall'] = $out['can_see_images'] && $bot['status'] === 'installed'
            && $granted !== '' && !in_array('files:read', explode(',', $granted), true);
        $out['scopes_unknown'] = $bot['status'] === 'installed' && $granted === '';
        // Apps the studio didn't create have no client secret, so they're reinstalled from Slack's own page.
        $out['can_reinstall_here'] = ($bot['client_id'] ?? '') !== '' && ($bot['client_secret_enc'] ?? '') !== '';
        $out['has_avatar_source'] = ($bot['avatar_source_mime'] ?? '') !== '' && is_file(self::sourcePath($bot['slug'], $bot['avatar_source_mime']));
        $out['avatar_crop'] = json_decode((string) ($bot['avatar_crop'] ?? ''), true) ?: null;
        return $out;
    }

    public static function avatarPath(string $slug): string
    {
        return Config::get('data_dir') . '/avatars/' . $slug . '.png';
    }

    /** Image types accepted as an avatar's original (generated or uploaded). */
    public const SOURCE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'];

    public static function sourcePath(string $slug, string $mime): string
    {
        return Config::get('data_dir') . '/avatars/' . $slug . '-source.' . (self::SOURCE_TYPES[$mime] ?? 'bin');
    }

    public static function slugify(string $text): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        return substr($slug, 0, 40) ?: 'bot';
    }

    private static function uniqueSlug(string $wanted): string
    {
        $base = self::slugify($wanted);
        $slug = $base;
        for ($i = 2; self::findBySlug($slug); $i++) {
            $slug = "$base-$i";
        }
        return $slug;
    }

    private static function clean(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if (in_array($key, self::FLAGS, true)) {
                $out[$key] = $value ? 1 : 0;
            } elseif (in_array($key, self::EDITABLE, true) || $key === 'slug') {
                $out[$key] = trim((string) $value);
            }
        }
        if (isset($out['background_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $out['background_color'])) {
            throw new \InvalidArgumentException('Slack card color must look like #1b2440.');
        }
        if (isset($out['icon_background']) && $out['icon_background'] !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $out['icon_background'])) {
            throw new \InvalidArgumentException('Icon background must look like #1b2440.');
        }
        if (isset($out['provider']) && !in_array($out['provider'], ['anthropic', 'openai'], true)) {
            throw new \InvalidArgumentException('Provider must be anthropic or openai.');
        }
        if (isset($out['name']) && mb_strlen($out['name']) > 35) {
            throw new \InvalidArgumentException('Slack app names can be at most 35 characters.');
        }
        return $out;
    }
}
