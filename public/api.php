<?php
// JSON endpoints for the studio pages. Everything needs the owner session and a CSRF token.
declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Studio\Ai\Ai;
use Studio\Ai\AiError;
use Studio\Ai\Generator;
use Studio\Auth;
use Studio\Bots;
use Studio\Config;
use Studio\Log;
use Studio\Reply;
use Studio\Slack\Apps;
use Studio\Slack\SlackError;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$action = (string) ($_GET['action'] ?? '');

// The avatar image is a plain GET (used in <img> tags).
if ($action === 'avatar') {
    if (!Auth::check()) {
        respond(['error' => 'Sign in first.'], 401);
    }
    $bot = Bots::find((int) ($_GET['id'] ?? 0));
    $path = $bot ? Bots::avatarPath($bot['slug']) : '';
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit;
}

// The original image an avatar was cropped from, so it can be recropped later.
if ($action === 'avatar_source') {
    if (!Auth::check()) {
        respond(['error' => 'Sign in first.'], 401);
    }
    $bot = Bots::find((int) ($_GET['id'] ?? 0));
    $mime = $bot['avatar_source_mime'] ?? '';
    $path = $bot && $mime ? Bots::sourcePath($bot['slug'], $mime) : '';
    if (!$path || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox"); // SVGs can't run anything
    header('Cache-Control: private, max-age=60');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'POST only.'], 405);
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];

if ($action === 'login') {
    $error = Auth::login((string) ($in['password'] ?? ''));
    $error ? respond(['error' => $error], 401) : respond(['ok' => true, 'csrf' => Auth::csrfToken()]);
}
if (!Auth::check()) {
    respond(['error' => 'Sign in first.'], 401);
}
if (!Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    respond(['error' => 'Your session expired. Reload the page.'], 403);
}

set_time_limit(300);

function botOrFail(array $in): array
{
    return Bots::find((int) ($in['id'] ?? 0)) ?? respond(['error' => 'Bot not found.'], 404);
}

try {
    switch ($action) {
        case 'logout':
            Auth::logout();
            respond(['ok' => true]);

        case 'state':
            respond([
                'bots' => array_map([Bots::class, 'public'], Bots::all()),
                'providers' => array_map(fn ($p) => ['id' => $p, 'label' => Ai::PROVIDERS[$p], 'chat_model' => Ai::chatModel($p)], Ai::availableProviders()),
                'styles' => array_map(fn ($k, $s) => ['id' => $k, 'label' => $s['label'], 'needs_openai' => $s['claude'] === null], array_keys(Generator::STYLES), Generator::STYLES),
                'slack_ready' => Apps::hasConfigToken(),
                'base_url' => Config::baseUrl(),
                'version' => Config::VERSION,
            ]);

        case 'generate_profile':
            $role = trim((string) ($in['role'] ?? ''));
            if ($role === '') {
                respond(['error' => 'Describe the role first.'], 400);
            }
            respond(['profile' => Generator::profile((string) ($in['provider'] ?? 'anthropic'), $role)]);

        case 'refine_profile':
            // "Make the instructions shorter and funnier": changes what's asked, keeps the rest.
            $request = trim((string) ($in['request'] ?? ''));
            if ($request === '') {
                respond(['error' => 'Say what to change first.'], 400);
            }
            respond(['profile' => Generator::refine((string) ($in['provider'] ?? 'anthropic'), (array) ($in['bot'] ?? []), $request)]);

        case 'generate_avatar':
            respond(['image' => Generator::avatar(
                (string) ($in['style'] ?? 'pixel'),
                trim((string) ($in['description'] ?? '')) ?: 'a friendly robot',
                (string) ($in['background'] ?? '#1b2440'),
                (string) ($in['prefer'] ?? 'anthropic'),
            )]);

        case 'save_bot':
            $fields = (array) ($in['bot'] ?? []);
            $bot = !empty($in['id']) ? Bots::update((int) $in['id'], $fields) : Bots::create($fields);
            if (!empty($in['avatar'])) {
                saveAvatar($bot, (string) $in['avatar']);
                Bots::setSystem((int) $bot['id'], ['icon_synced' => 0]);
            }
            if (!empty($in['avatar_source'])) {
                saveAvatarSource($bot, (string) $in['avatar_source']);
            }
            if (isset($in['avatar_crop']) && is_array($in['avatar_crop'])) {
                $c = $in['avatar_crop'];
                Bots::setSystem((int) $bot['id'], ['avatar_crop' => json_encode([
                    'x' => (float) ($c['x'] ?? 0), 'y' => (float) ($c['y'] ?? 0), 's' => max(1.0, (float) ($c['s'] ?? 1)), 'even' => !empty($c['even']),
                ])]);
            }
            $bot = Bots::find((int) $bot['id']);
            $notice = null;
            if ($bot['app_id'] !== '' && !empty($in['sync']) && Apps::hasConfigToken()) {
                $notice = Apps::update($bot);
            }
            if ($bot['status'] === 'installed') {
                try {
                    Apps::checkScopes($bot); // so the studio knows if a reinstall is needed (e.g. for images)
                } catch (SlackError) {
                }
            }
            respond(['bot' => Bots::public(Bots::find((int) $bot['id'])), 'notice' => $notice]);

        case 'create_app':
            $bot = botOrFail($in);
            $notice = Apps::create($bot);
            $bot = Bots::find((int) $bot['id']);
            respond(['bot' => Bots::public($bot), 'install_url' => Apps::startInstall($bot), 'notice' => $notice]);

        case 'install_url':
            respond(['install_url' => Apps::startInstall(botOrFail($in))]);

        case 'set_app_id':
            // Imported apps: Slack doesn't say their App ID, so the owner pastes it from api.slack.com/apps.
            $appId = strtoupper(trim((string) ($in['app_id'] ?? '')));
            if (!preg_match('/^A[A-Z0-9]{6,}$/', $appId)) {
                respond(['error' => 'App IDs look like A0123ABCDEF.'], 400);
            }
            // The app's current Slack icon stays as it is; the next save with a new avatar replaces it.
            Bots::setSystem((int) botOrFail($in)['id'], ['app_id' => $appId]);
            respond(['bot' => Bots::public(Bots::find((int) $in['id']))]);

        case 'check_scopes':
            respond(['bot' => Bots::public(Apps::checkScopes(botOrFail($in)))]);

        case 'retry_icon':
            respond(['notice' => Apps::setIcon(botOrFail($in)), 'bot' => Bots::public(Bots::find((int) $in['id']))]);

        case 'delete_bot':
            Apps::delete(botOrFail($in));
            respond(['ok' => true]);

        case 'import_bot':
            $bot = Apps::import((array) ($in['bot'] ?? []), trim((string) ($in['bot_token'] ?? '')), trim((string) ($in['signing_secret'] ?? '')));
            if (!empty($in['avatar'])) {
                saveAvatar($bot, (string) $in['avatar']);
            }
            respond(['bot' => Bots::public(Bots::find((int) $bot['id']))]);

        case 'preview_chat':
            // Try the bot's personality right in the studio before (or after) it's in Slack.
            $bot = (array) ($in['bot'] ?? []);
            $provider = (string) ($bot['provider'] ?? 'anthropic');
            $bot = [
                'name' => (string) ($bot['name'] ?? 'Bot'),
                'personality' => (string) ($bot['personality'] ?? ''),
                'provider' => $provider,
                'can_see_images' => !empty($bot['can_see_images']),
                'can_read_links' => !empty($bot['can_read_links']),
                'can_search_web' => !empty($bot['can_search_web']),
            ];
            $messages = [];
            foreach ((array) ($in['messages'] ?? []) as $m) {
                $text = trim((string) ($m['content'] ?? ''));
                if (in_array($m['role'] ?? '', ['user', 'assistant'], true) && $text !== '') {
                    $messages[] = ['role' => $m['role'], 'content' => Studio\Slack\Events::clean($text, '')];
                }
            }
            $reply = Ai::provider($provider)->chat(
                Reply::systemPrompt($bot),
                $messages,
                (string) ($in['bot']['model'] ?? '') ?: Ai::chatModel($provider),
                Reply::webOptions($bot),
            );
            respond(['reply' => $reply]);

        case 'save_slack_token':
            Apps::saveConfigTokens((string) ($in['refresh_token'] ?? ''));
            respond(['ok' => true]);

        case 'log':
            respond(['lines' => Studio\Log::tail(100)]);

        default:
            respond(['error' => 'Unknown action.'], 404);
    }
} catch (AiError $e) {
    Log::write("$action: " . $e->getMessage());
    respond(['error' => $e->friendly], 502);
} catch (SlackError $e) {
    Log::write("$action: " . $e->getMessage());
    respond(['error' => $e->getMessage()], 502);
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    Log::write("$action: " . get_class($e) . ': ' . $e->getMessage());
    respond(['error' => $e->getMessage()], 500);
}

/** Stores the original image (generated or uploaded) so the avatar can be recropped later. */
function saveAvatarSource(array $bot, string $dataUrl): void
{
    if (!preg_match('#^data:([a-z/+.-]+);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || !isset(Bots::SOURCE_TYPES[$m[1]])) {
        throw new InvalidArgumentException('The original image must be PNG, JPEG, WebP, GIF or SVG.');
    }
    [$mime, $bytes] = [$m[1], base64_decode($m[2], true)];
    if ($bytes === false || strlen($bytes) > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('The original image is too big (10 MB max).');
    }
    if ($mime === 'image/svg+xml') {
        $bytes = Generator::cleanSvg($bytes);
    } elseif (!@getimagesizefromstring($bytes)) {
        throw new InvalidArgumentException("That file isn't a readable image.");
    }
    $dir = dirname(Bots::avatarPath($bot['slug']));
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    foreach (Bots::SOURCE_TYPES as $type => $ext) {
        @unlink(Bots::sourcePath($bot['slug'], $type)); // replace any previous original
    }
    file_put_contents(Bots::sourcePath($bot['slug'], $mime), $bytes);
    Bots::setSystem((int) $bot['id'], ['avatar_source_mime' => $mime]);
}

/** Stores the cropped 512x512 PNG (a data URL from the browser). */
function saveAvatar(array $bot, string $dataUrl): void
{
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
        throw new InvalidArgumentException('Avatar must be a PNG.');
    }
    $png = base64_decode($m[1], true);
    $info = $png ? @getimagesizefromstring($png) : false;
    if (!$info || $info[2] !== IMAGETYPE_PNG || $info[0] < 512 || $info[0] > 2000 || $info[0] !== $info[1]) {
        throw new InvalidArgumentException('Avatar must be a square PNG between 512 and 2000 pixels.');
    }
    $dir = dirname(Bots::avatarPath($bot['slug']));
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents(Bots::avatarPath($bot['slug']), $png);
}
