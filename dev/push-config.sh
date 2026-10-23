#!/bin/bash
# Builds ~/slackbot-config.php on the server from .env (Anthropic key, bot tokens, signing secrets).
# The file sits outside the website folder and only your account can read it. Values are never printed.
HERE="$(cd "$(dirname "$0")/.." && pwd)"
source "$HERE/dev/server.conf" || exit 1
SSH="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o LogLevel=ERROR"

CONFIG=$("$HERE/venv/bin/python" - "$HERE/.env" <<'PY'
import sys
from dotenv import dotenv_values
env = dotenv_values(sys.argv[1])
need = ["ANTHROPIC_API_KEY"] + [f"{k}_{f}" for k in ("FOX", "CHEF", "DUCK") for f in ("BOT_TOKEN", "SIGNING_SECRET")]
missing = [n for n in need if not env.get(n)]
if missing:
    sys.exit("Missing from .env: " + ", ".join(missing))
q = lambda v: "'" + v.replace("\\", "\\\\").replace("'", "\\'") + "'"
bots = "\n".join(
    f"        '{k.lower()}' => ['bot_token' => {q(env[k + '_BOT_TOKEN'])}, 'signing_secret' => {q(env[k + '_SIGNING_SECRET'])}],"
    for k in ("FOX", "CHEF", "DUCK")
)
print(f"""<?php
// Slack bot settings. Written by dev/push-config.sh from the Slackbot project's .env. Keep private.
return [
    'anthropic_api_key' => {q(env['ANTHROPIC_API_KEY'])},
    'model' => {q(env.get('CLAUDE_MODEL') or 'claude-haiku-5-5')},
    'bots' => [
{bots}
    ],
];""")
PY
) || exit 1

printf '%s\n' "$CONFIG" | $SSH "$SSH_TARGET" 'umask 077; cat > ~/slackbot-config.php && php -l ~/slackbot-config.php >/dev/null && echo "config uploaded"'
