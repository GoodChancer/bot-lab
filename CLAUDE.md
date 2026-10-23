# Bot Lab

Bot Lab is a self-hosted PHP web app for designing AI Slack bots: generate a persona and avatar with Claude or OpenAI, crop the face, then create and install the Slack app through Slack's API. Public repo (portfolio project); keep personal server details out of committed files.

## Stack and layout
- PHP 8.2+, Composer, SQLite (PDO), libsodium, GD, plain JS (no build). Runs on shared hosting.
- `public/` is the only web-facing folder: `index.php` (studio shell), `api.php` (JSON actions, owner session + CSRF), `events.php?bot=<slug>` (Slack Events API), `oauth.php` (install callback), `assets/` (app.js, cropper.js, app.css).
- `src/`: `Config`, `Db`, `Crypto`, `Auth`, `Settings`, `Bots`, `Reply`, `Log`; `Ai/` (Provider interface, ClaudeProvider via official `anthropic-ai/sdk`, OpenAiProvider via REST, Generator); `Slack/` (Events, Manifest, Apps, WebApi).
- `config.php` (created by `php bin/setup.php`) holds keys, owner password hash, encryption key, models. Never commit it, `deploy.conf`, `data/` or `.env`.

## Conventions
- Slack: Events API only (no Socket Mode; shared hosting forbids long-running processes). Answer Slack within 3 s via `Events::finishResponse()`, then reply.
- Secrets in the DB are encrypted (`Bots::setSecret`, `Settings::setSecret`); `Bots::public()` is what the UI may see.
- Models: chat defaults `claude-haiku-5-5` / `gpt-5-mini`; generation `claude-opus-5-5` / `gpt-5`; images `gpt-image-2`. Claude avatars are a 32x32 pixel grid (rendered with GD) or SVG (sanitized by `Generator::cleanSvg`).
- Slack icons: square PNG, 512–2000 px; pixel art is upscaled with nearest-neighbor in the cropper.
- User-facing text: plain, friendly, short.

## Commands
- Tests: `vendor/bin/phpunit`
- Local: `php -S localhost:8765 -t public` (also `studio` in `.claude/launch.json`); Slack features need the deployed HTTPS copy.
- Moving the studio's URL: deploy `public/` to the new place first, then `php bin/change-url.php https://new/url` on the server (updates config.php and every linked Slack app's event + redirect URLs).
- Deploy: `dev/deploy.sh` (settings in `deploy.conf`, see `deploy.conf.example`). Server log: `data/studio.log` in the app folder.

## Characters
The original bots, now rows in the studio database: **Fixel Fox** (suggests fixes), **Doughbert** (creative chef), **Judgemental Duck** (blunt, specific criticism). Art in `art/`.
