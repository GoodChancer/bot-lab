<?php
// Slack sends every DM and @mention for a bot here: events.php?bot=<slug>
declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Studio\Bots;
use Studio\Log;
use Studio\Reply;
use Studio\Slack\Events;

$body = (string) file_get_contents('php://input');
$payload = json_decode($body, true) ?: [];

try {
    $bot = Bots::findBySlug((string) ($_GET['bot'] ?? ''));
} catch (Throwable $e) {
    Log::write('events: ' . $e->getMessage());
    http_response_code(503);
    exit('Not set up yet.');
}
if (!$bot) {
    http_response_code(404);
    exit('Unknown bot.');
}

$verified = Events::verify(
    $body,
    (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''),
    (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''),
    Bots::secret($bot, 'signing_secret'),
);

// Slack checks the URL while the studio is still creating the app, before the signing secret
// is saved. Echoing the challenge back reveals nothing, so allow it for bots being set up.
if (($payload['type'] ?? '') === 'url_verification' && ($verified || $bot['status'] === 'draft')) {
    header('Content-Type: application/json');
    echo json_encode(['challenge' => (string) ($payload['challenge'] ?? '')]);
    exit;
}

if (!$verified) {
    http_response_code(401);
    exit('Bad signature.');
}

// Slack resends events it thinks we missed; we already handled them.
if (isset($_SERVER['HTTP_X_SLACK_RETRY_NUM'])) {
    exit;
}

$event = $payload['event'] ?? [];
if ($bot['status'] !== 'installed' || !Events::shouldReply($event)) {
    exit;
}

Events::finishResponse();
Reply::handle($bot, $event, (string) ($payload['authorizations'][0]['user_id'] ?? $bot['bot_user_id']));
