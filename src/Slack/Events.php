<?php
declare(strict_types=1);

namespace Studio\Slack;

/** Pure helpers for handling Slack Events API requests. */
final class Events
{
    /** https://docs.slack.dev/authentication/verifying-requests-from-slack */
    public static function verify(string $body, string $timestamp, string $signature, string $secret, ?int $now = null): bool
    {
        $now ??= time();
        if ($secret === '' || !ctype_digit($timestamp) || abs($now - (int) $timestamp) > 300) {
            return false;
        }
        $expected = 'v0=' . hash_hmac('sha256', "v0:$timestamp:$body", $secret);
        return hash_equals($expected, $signature);
    }

    /** Message subtypes that are still a person talking (file_share = a message with an attachment). */
    private const PERSON_SUBTYPES = ['file_share'];

    /** Only DMs and @mentions from people get a reply. */
    public static function shouldReply(array $event): bool
    {
        if (!empty($event['bot_id'])) {
            return false;
        }
        if (!empty($event['subtype']) && !in_array($event['subtype'], self::PERSON_SUBTYPES, true)) {
            return false;
        }
        $type = $event['type'] ?? '';
        if ($type === 'message') {
            return ($event['channel_type'] ?? '') === 'im';
        }
        return $type === 'app_mention';
    }

    /** Removes the bot's own @mention and turns Slack's <https://url|label> links into plain URLs. */
    public static function clean(string $text, string $botUserId): string
    {
        if ($botUserId !== '') {
            $text = (string) preg_replace('/<@' . preg_quote($botUserId, '/') . '>\s*/', '', $text);
        }
        $text = (string) preg_replace('/<(https?:\/\/[^|>\s]+)(?:\|[^>]*)?>/', '$1', $text);
        return trim($text);
    }

    /** Image attachments on a message that a model can look at. */
    public static function images(array $message): array
    {
        $ok = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return array_values(array_filter($message['files'] ?? [], fn ($f) => in_array($f['mimetype'] ?? '', $ok, true)
            && ($f['size'] ?? 0) <= 5 * 1024 * 1024 && !empty($f['url_private'])));
    }

    /**
     * Turns Slack messages (oldest first) into alternating user/assistant turns.
     * Back-to-back messages from one side are merged; the list always starts with the user.
     */
    public static function toConversation(array $slackMessages, string $botUserId): array
    {
        $out = [];
        foreach ($slackMessages as $m) {
            $subtype = $m['subtype'] ?? null;
            if ($subtype !== null && $subtype !== 'bot_message' && !in_array($subtype, self::PERSON_SUBTYPES, true)) {
                continue;
            }
            $role = (($m['user'] ?? '') === $botUserId || isset($m['bot_id'])) ? 'assistant' : 'user';
            $text = self::clean((string) ($m['text'] ?? ''), $botUserId);
            if ($n = count($m['files'] ?? [])) {
                $text = trim($text . "\n[shared " . ($n === 1 ? 'a file' : "$n files") . ']');
            }
            if ($text === '') {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['role'] === $role) {
                $out[$last]['content'] .= "\n\n" . $text;
            } else {
                $out[] = ['role' => $role, 'content' => $text];
            }
        }
        while ($out && $out[0]['role'] !== 'user') {
            array_shift($out);
        }
        return $out;
    }

    /** Sends Slack its 200 now (it gives up after 3 seconds) and keeps running to write the reply. */
    public static function finishResponse(): void
    {
        ignore_user_abort(true);
        set_time_limit(180);
        http_response_code(200);
        header('Content-Length: 0');
        header('Connection: close');
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
    }
}
