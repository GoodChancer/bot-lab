#!/bin/bash
# Deploys the studio over SSH to a host that serves PHP (shared hosting works).
#   App code -> REMOTE_APP_DIR (outside the web root), public/ -> REMOTE_PUBLIC_DIR.
# Settings come from deploy.conf (copy deploy.conf.example). config.php and data/ on the server are never touched.
# For a second copy (say a demo), put its settings in deploy.NAME.conf and run: dev/deploy.sh NAME
set -e
HERE="$(cd "$(dirname "$0")/.." && pwd)"
CONF="$HERE/deploy${1:+.$1}.conf"
[ -f "$CONF" ] || { echo "Missing $CONF"; exit 1; }
source "$CONF"
SSH="ssh -i $SSH_KEY -p $SSH_PORT -o BatchMode=yes -o LogLevel=ERROR"

echo "Uploading app code..."
rsync -az --delete -e "$SSH" \
  --exclude=/.git/ --exclude=/.env --exclude=/config.php --exclude=/data/ --exclude=/vendor/ --exclude=/public/ \
  --exclude=/tests/ --exclude=/art/ --exclude=/docs/ --exclude=/.claude/ --exclude=/deploy.conf --exclude='/deploy.*.conf' --exclude=/dev/ \
  --exclude=/venv/ --exclude=/web/ --exclude=/.phpunit.cache/ --exclude=.DS_Store \
  "$HERE/" "$SSH_TARGET:$REMOTE_APP_DIR/"

echo "Installing libraries on the server..."
$SSH "$SSH_TARGET" "cd $REMOTE_APP_DIR && $REMOTE_COMPOSER install --no-dev --no-interaction --quiet --optimize-autoloader && mkdir -p data && chmod 700 data"

echo "Uploading the website folder..."
rsync -az --delete -e "$SSH" --exclude=/app-root.php --exclude=.DS_Store "$HERE/public/" "$SSH_TARGET:$REMOTE_PUBLIC_DIR/"
$SSH "$SSH_TARGET" "printf '<?php return %s;\n' \"'\$(cd $REMOTE_APP_DIR && pwd)'\" > $REMOTE_PUBLIC_DIR/app-root.php"

echo "Checking..."
CODE=$(curl -s -o /dev/null -w "%{http_code}" "$SITE_URL/")
[ "$CODE" = "200" ] || [ "$CODE" = "503" ] || { echo "Studio page returned HTTP $CODE"; exit 1; }
[ "$CODE" = "503" ] && echo "Deployed, but config.php is missing on the server: run php bin/setup.php there." || echo "Deployed: $SITE_URL"
