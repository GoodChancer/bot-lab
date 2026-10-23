<?php
declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;

require __DIR__ . '/vendor/autoload.php';

const SLACKBOT_HISTORY_LIMIT = 20; // recent DM messages sent to Claude as memory

/** Settings and secrets live outside the website folder, in ~/slackbot-config.php. */
function slackbotConfig(): array
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__, 2) . '/slackbot-config.php';
        if (!is_file($path)) {
            throw new RuntimeException("missing $path");
        }
        $config = require $path;
    }
    return $config;
}

function slackbotLog(string $line): void
{
    $file = dirname(__DIR__, 2) . '/slackbot.log';
    @file_put_contents($file, gmdate('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Checks the request really came from Slack (https://api.slack.com/authentication/verifying-requests-from-slack). */
function slackbotVerify(string $body, string $secret): bool
{
    $ts = $_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? '';
    $sig = $_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? '';
    if ($secret === '' || !ctype_digit($ts) || abs(time() - (int) $ts) > 300) {
        return false;
    }
    $expected = 'v0=' . hash_hmac('sha256', "v0:$ts:$body", $secret);
    return hash_equals($expected, $sig);
}

/** Only DMs and @mentions from people. */
function slackbotShouldReply(array $event): bool
{
    if (!empty($event['bot_id']) || !empty($event['subtype'])) {
        return false;
    }
    $type = $event['type'] ?? '';
    if ($type === 'message') {
        return ($event['channel_type'] ?? '') === 'im';
    }
    return $type === 'app_mention';
}

/** Sends Slack its "OK" now and keeps running in the background to write the reply. */
function slackbotFinishResponse(): void
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

function slackbotReply(string $key, array $bot, array $config, array $event, string $botUserId): void
{
    $characters = json_decode((string) file_get_contents(__DIR__ . '/characters.json'), true);
    $character = $characters['characters'][$key];
    $system = $character['prompt'] . "\n\n" . $characters['slack_style'];
    $token = (string) $bot['bot_token'];
    $channel = (string) $event['channel'];

    try {
        if ($event['type'] === 'message') {
            $history = slackbotApi($token, 'conversations.history', ['channel' => $channel, 'limit' => SLACKBOT_HISTORY_LIMIT], 'GET');
            $messages = slackbotToClaude(array_reverse($history['messages'] ?? []), $botUserId);
            $threadTs = null;
        } else {
            $text = slackbotClean((string) ($event['text'] ?? ''), $botUserId);
            $messages = [['role' => 'user', 'content' => $text !== '' ? $text : 'Someone mentioned you with no message. Say hi briefly.']];
            $threadTs = $event['thread_ts'] ?? $event['ts'];
        }
        if (!$messages) {
            return;
        }
        $reply = slackbotAskClaude($config, $system, $messages);
        $post = ['channel' => $channel, 'text' => $reply];
        if ($threadTs) {
            $post['thread_ts'] = $threadTs;
        }
        slackbotApi($token, 'chat.postMessage', $post);
        slackbotLog("{$character['name']} replied ({$event['type']})");
    } catch (Throwable $e) {
        slackbotLog("{$character['name']} failed: " . $e->getMessage());
    }
}

function slackbotAskClaude(array $config, string $system, array $messages): string
{
    static $client = null;
    $client ??= new Client(apiKey: (string) $config['anthropic_api_key']);
    try {
        $message = $client->messages->create(
            model: $config['model'] ?? 'claude-haiku-5-5',
            maxTokens: 4000,
            system: $system,
            messages: $messages,
            outputConfig: ['effort' => 'low'],
        );
    } catch (RateLimitException) {
        return "I'm getting too many requests right now. Try again in a minute.";
    } catch (APIStatusException $e) {
        slackbotLog('Claude API error: ' . $e->getMessage());
        return 'Something went wrong talking to my brain. Check the bot log.';
    } catch (APIConnectionException $e) {
        slackbotLog('Could not reach Claude: ' . $e->getMessage());
        return "I couldn't reach the Claude API. Try again in a bit.";
    }

    $stop = is_object($message->stopReason) ? $message->stopReason->value : (string) $message->stopReason;
    if ($stop === 'refusal') {
        return "I can't help with that one.";
    }
    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    $text = trim($text);
    return $text !== '' ? $text : '(I came up empty. Try asking another way.)';
}

/** Removes the bot's own @mention from a message. */
function slackbotClean(string $text, string $botUserId): string
{
    return trim(preg_replace('/<@' . preg_quote($botUserId, '/') . '>\s*/', '', $text));
}

/** Turns Slack messages (oldest first) into Claude's alternating user/assistant format. */
function slackbotToClaude(array $slackMessages, string $botUserId): array
{
    $out = [];
    foreach ($slackMessages as $m) {
        $subtype = $m['subtype'] ?? null;
        if ($subtype !== null && $subtype !== 'bot_message') {
            continue;
        }
        $isBot = ($m['user'] ?? '') === $botUserId || isset($m['bot_id']);
        $role = $isBot ? 'assistant' : 'user';
        $text = slackbotClean((string) ($m['text'] ?? ''), $botUserId);
        if ($text === '') {
            continue;
        }
        $last = count($out) - 1;
        if ($last >= 0 && $out[$last]['role'] === $role) {
            $out[$last]['content'] .= "\n\n" . $text; // merge back-to-back messages from one side
        } else {
            $out[] = ['role' => $role, 'content' => $text];
        }
    }
    while ($out && $out[0]['role'] !== 'user') { // must start with the user
        array_shift($out);
    }
    return $out;
}

/** Calls a Slack Web API method and returns the decoded response. */
function slackbotApi(string $token, string $method, array $params, string $verb = 'POST'): array
{
    $url = "https://slack.com/api/$method";
    $ch = curl_init();
    $headers = ["Authorization: Bearer $token"];
    if ($verb === 'GET') {
        $url .= '?' . http_build_query($params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || empty($data['ok'])) {
        throw new RuntimeException("Slack $method failed: " . ($data['error'] ?? $err ?: 'no response'));
    }
    return $data;
}
