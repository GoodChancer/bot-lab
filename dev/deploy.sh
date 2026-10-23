#!/bin/bash
# Uploads web/ to idwants.com/slackbot/, installs the Claude library there, and checks the endpoint answers.
# Connection settings come from dev/server.conf (same server as the IdWants site; never committed).
HERE="$(cd "$(dirname "$0")/.." && pwd)"
source "$HERE/dev/server.conf" || exit 1
SSH="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o LogLevel=ERROR"
REMOTE=public_html/slackbot/

rsync -az --delete -e "$SSH" \
  --exclude=.DS_Store --exclude=vendor/ --exclude=composer.lock \
  "$HERE/web/" "$SSH_TARGET:$REMOTE" && echo "uploaded" || exit 1

$SSH "$SSH_TARGET" "cd ~/$REMOTE && php ~/bin/composer.phar install --no-dev --no-interaction --quiet" \
  && echo "libraries installed" || exit 1

# Smoke check: an unsigned request must be refused (401), which proves PHP and the config load.
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST "https://idwants.com/slackbot/events.php?bot=fox")
case "$CODE" in
  401) echo "endpoint ok" ;;
  503) echo "endpoint up, but ~/slackbot-config.php is missing: run dev/push-config.sh" ;;
  *) echo "ENDPOINT CHECK FAILED: HTTP $CODE"; exit 1 ;;
esac
