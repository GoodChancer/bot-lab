<?php
declare(strict_types=1);

namespace Studio;

/** Small key/value store for things set from the studio (like the Slack config token). */
final class Settings
{
    public static function get(string $key, string $default = ''): string
    {
        $row = Db::query('SELECT value FROM settings WHERE key = ?', [$key])->fetch();
        return $row ? (string) $row['value'] : $default;
    }

    public static function set(string $key, string $value): void
    {
        Db::query('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
    }

    public static function getSecret(string $key): string
    {
        return Crypto::decrypt(self::get($key));
    }

    public static function setSecret(string $key, string $value): void
    {
        self::set($key, Crypto::encrypt($value));
    }

    public static function delete(string $key): void
    {
        Db::query('DELETE FROM settings WHERE key = ?', [$key]);
    }
}
