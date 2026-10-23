<?php
// Slack sends every DM and @mention for a character here, e.g. /slackbot/events.php?bot=fox
// We answer Slack right away (it gives up after 3 seconds), then ask Claude and post the reply.
declare(strict_types=1);

require __DIR__ . '/lib.php';

$body = (string) file_get_contents('php://input');
$key = strtolower((string) ($_GET['bot'] ?? ''));

try {
    $config = slackbotConfig();
} catch (Throwable $e) {
    slackbotLog('config error: ' . $e->getMessage());
    http_response_code(503);
    exit('Not set up yet.');
}

$bot = $config['bots'][$key] ?? null;
if (!$bot || !slackbotVerify($body, (string) $bot['signing_secret'])) {
    http_response_code(401);
    exit('Bad request.');
}

$payload = json_decode($body, true) ?: [];

// Slack checks the URL once when you save it in the app settings.
if (($payload['type'] ?? '') === 'url_verification') {
    header('Content-Type: application/json');
    echo json_encode(['challenge' => $payload['challenge'] ?? '']);
    exit;
}

// Slack resends an event if it thinks we were too slow. We already handled it, so skip.
if (isset($_SERVER['HTTP_X_SLACK_RETRY_NUM'])) {
    http_response_code(200);
    exit;
}

$event = $payload['event'] ?? [];
$botUserId = (string) ($payload['authorizations'][0]['user_id'] ?? '');

if (!slackbotShouldReply($event)) {
    http_response_code(200);
    exit;
}

slackbotFinishResponse();
slackbotReply($key, $bot, $config, $event, $botUserId);
