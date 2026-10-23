"""Slack character bots: Fixel Fox, Doughbert, and Judgemental Duck.

Each character is its own Slack app. This script starts every character whose
two tokens are in .env, all in Socket Mode, so it runs locally with no server.

Run:  venv/bin/python bot.py
"""
import json
import logging
import os
import re
import threading

import anthropic
from dotenv import load_dotenv
from slack_bolt import App
from slack_bolt.adapter.socket_mode import SocketModeHandler

load_dotenv()
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(name)s %(message)s")
log = logging.getLogger("slackbot")

MODEL = os.environ.get("CLAUDE_MODEL", "claude-haiku-5-5")
HISTORY_LIMIT = 20  # how many recent DM messages to send as memory

# Character prompts are shared with the website version (web/characters.json).
with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "web", "characters.json")) as f:
    _shared = json.load(f)
SLACK_STYLE = _shared["slack_style"]
CHARACTERS = {key.upper(): c for key, c in _shared["characters"].items()}

claude = anthropic.Anthropic()  # reads ANTHROPIC_API_KEY from the environment


def ask_claude(system_prompt, messages):
    """Send the conversation to Claude and return the reply text."""
    try:
        response = claude.messages.create(
            model=MODEL,
            max_tokens=4000,
            system=f"{system_prompt}\n\n{SLACK_STYLE}",
            messages=messages,
            output_config={"effort": "low"},
        )
    except anthropic.RateLimitError:
        return "I'm getting too many requests right now. Try again in a minute."
    except anthropic.APIStatusError as e:
        log.error("Claude API error %s: %s", e.status_code, e.message)
        return "Something went wrong talking to my brain. Check the bot logs."
    except anthropic.APIConnectionError:
        log.exception("Could not reach the Claude API")
        return "I couldn't reach the Claude API. Is the internet working?"

    if response.stop_reason == "refusal":
        return "I can't help with that one."
    text = "".join(b.text for b in response.content if b.type == "text").strip()
    return text or "(I came up empty. Try asking another way.)"


def clean(text, bot_user_id):
    """Remove the bot's own @mention from a message."""
    return re.sub(rf"<@{bot_user_id}>\s*", "", text or "").strip()


def to_claude_messages(slack_messages, bot_user_id):
    """Turn Slack messages (oldest first) into Claude's alternating user/assistant format."""
    out = []
    for m in slack_messages:
        if m.get("subtype") not in (None, "bot_message"):
            continue
        is_bot = m.get("user") == bot_user_id or "bot_id" in m
        role = "assistant" if is_bot else "user"
        text = clean(m.get("text"), bot_user_id)
        if not text:
            continue
        if out and out[-1]["role"] == role:
            out[-1]["content"] += "\n\n" + text  # merge back-to-back messages from one side
        else:
            out.append({"role": role, "content": text})
    while out and out[0]["role"] != "user":  # must start with the user
        out.pop(0)
    return out


def build_app(key, character):
    bot_token = os.environ[f"{key}_BOT_TOKEN"]
    app = App(token=bot_token)
    bot_user_id = app.client.auth_test()["user_id"]
    name = character["name"]

    @app.event("message")
    def on_dm(event, client, say):
        # Only answer direct messages from people.
        if event.get("channel_type") != "im" or event.get("bot_id") or event.get("subtype"):
            return
        history = client.conversations_history(channel=event["channel"], limit=HISTORY_LIMIT)
        messages = to_claude_messages(reversed(history["messages"]), bot_user_id)
        if not messages:
            return
        log.info("%s got a DM", name)
        say(ask_claude(character["prompt"], messages))

    @app.event("app_mention")
    def on_mention(event, say):
        text = clean(event.get("text"), bot_user_id)
        if not text:
            text = "Someone mentioned you with no message. Say hi briefly."
        log.info("%s was mentioned", name)
        reply = ask_claude(character["prompt"], [{"role": "user", "content": text}])
        say(text=reply, thread_ts=event.get("thread_ts") or event["ts"])

    return app


def main():
    if not os.environ.get("ANTHROPIC_API_KEY"):
        raise SystemExit("ANTHROPIC_API_KEY is missing from .env")

    started = []
    for key, character in CHARACTERS.items():
        if not (os.environ.get(f"{key}_BOT_TOKEN") and os.environ.get(f"{key}_APP_TOKEN")):
            log.info("Skipping %s (no tokens in .env)", character["name"])
            continue
        try:
            app = build_app(key, character)
            SocketModeHandler(app, os.environ[f"{key}_APP_TOKEN"]).connect()
        except Exception as e:
            # One bad token shouldn't take down the other characters.
            log.error("Could not start %s: %s. Check its tokens in .env.", character["name"], e)
            continue
        started.append(character["name"])

    if not started:
        raise SystemExit("No characters have tokens in .env yet.")
    log.info("Running: %s. Press Ctrl+C to stop.", ", ".join(started))
    try:
        threading.Event().wait()
    except KeyboardInterrupt:
        log.info("Stopped.")


if __name__ == "__main__":
    main()
