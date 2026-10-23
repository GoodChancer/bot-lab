<?php
declare(strict_types=1);

namespace Studio;

/** Appends one line to data/studio.log. Never logs secrets or message contents. */
final class Log
{
    public static function write(string $line): void
    {
        $dir = (string) Config::get('data_dir');
        @file_put_contents($dir . '/studio.log', gmdate('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function tail(int $lines = 50): array
    {
        $file = Config::get('data_dir') . '/studio.log';
        if (!is_file($file)) {
            return [];
        }
        return array_slice(file($file, FILE_IGNORE_NEW_LINES) ?: [], -$lines);
    }
}
