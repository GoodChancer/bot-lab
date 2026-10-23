# Slackbot

Personal Slack AI agents, each a separate Slack app with its own name and pixel-art icon.

## Characters
- **Fixel Fox**: suggests how to improve and fix things.
- **Doughbert** (the chef): creative, contributes creative ideas.
- **Judgemental Duck**: gives blunt, harsh criticism (specific, not cruel).

## Plan
- Free Slack workspace; one Slack app per character (3 of the 10 free app slots).
- Live version: PHP on idwants.com (Namecheap shared hosting) using Slack's web request mode (Events API). Shared hosting forbids always-running processes, so no Socket Mode there.
- `bot.py` (Python, Slack Bolt, Socket Mode) is the local alternative. It only works if an app's Socket Mode is turned back on.
- Each character = its own system prompt; all share one Anthropic API key and model (a small, cheap model like Haiku).
- Conversation memory: fetch recent DM history and send it with each request.
- Secrets (2 Slack tokens per app + Anthropic API key) go in a `.env` file, never in code. `.env` must be in `.gitignore`.
- Character art goes in `art/`. Slack icons: square, at least 512x512; upscale pixel art with nearest-neighbor scaling.

## Website version (live)
- Code in `web/`: `events.php` (the only public file), `lib.php`, `characters.json` (prompts, shared with `bot.py`).
- Slack Request URLs: `https://idwants.com/slackbot/events.php?bot=fox|chef|duck`. Every request's Slack signature is checked.
- Answers Slack immediately (3-second limit), then calls Claude and posts the reply in the same request (`litespeed_finish_request`).
- Deploy code: `dev/deploy.sh` (rsync to `~/public_html/slackbot/`, composer install on the server, smoke check).
- Secrets: `dev/push-config.sh` builds `~/slackbot-config.php` on the server from `.env` (outside the web root, mode 600). Rerun after changing `.env`.
- Log: `~/slackbot.log` on the server. SSH settings: `dev/server.conf` (copied from IdWants appdesk; not committed).
- Model: `claude-haiku-5-5` at low effort (set `CLAUDE_MODEL` in `.env` and push config to change it).

## Running locally instead
- `bot.py` runs every character with bot + app tokens in `.env` (template: `.env.example`). Needs Socket Mode on in the Slack app.
- Setup: `python3 -m venv venv && venv/bin/pip install -r requirements.txt`, then `venv/bin/python bot.py`.

## Icon tool
- `python3 tools/crop_server.py` → http://localhost:8765 (also the `crop-tool` entry in `.claude/launch.json`). Stdlib only.
- Crop a square around each character's face and save a 512x512 nearest-neighbor icon to `art/icons/<name>-icon.png`.
- Pick each character's Slack background color; saved to `art/icons/colors.json`.
- To add a character, add an entry to `SPRITES` in `tools/crop.html`.
