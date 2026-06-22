# Telegram Auto Copy & Repost Bot

Watches one or more public Telegram channels you don't administer and
reposts every new message (text, photos, videos, albums) into a channel
you own — as a clean copy, no "Forwarded from" tag.

This is a standalone tool, independent of the rest of the Hinace
repository; it has its own dependencies and deployment.

## How it works

The Telegram Bot API can't read messages from a channel unless your bot is
added as an admin to it — which doesn't help when you only want to
*subscribe* to someone else's public channel. So instead this uses
[Telethon](https://docs.telethon.dev/) to log in as a regular **user
account** (the same way the Telegram mobile/desktop app does), join the
source channels as an ordinary member, and re-send each new message into
your destination channel.

## Setup

1. Get an `api_id` and `api_hash` from <https://my.telegram.org> (API
   development tools) using your phone number.
2. `cp .env.example .env` and fill in `TG_API_ID` / `TG_API_HASH`.
3. Edit `channels.yaml`: set `destination` to your channel (you must
   already be a member with posting rights), and list the `sources` you
   want to watch.
4. Install dependencies and log in **once**, interactively, to create the
   session file:
   ```bash
   python -m venv venv && source venv/bin/activate
   pip install -r requirements.txt
   python login.py
   ```
   You'll be prompted for your phone number, the login code Telegram sends
   you, and your 2FA password if you have one. This creates a
   `<TG_SESSION_NAME>.session` file — treat it like a password: it's a live
   login credential. It's already excluded via `.gitignore`; never commit
   it.
5. Run the bot:
   ```bash
   python bot.py
   ```

## Running with Docker

```bash
cp .env.example .env   # fill in values, and set TG_SESSION_NAME=data/repost_bot
python login.py        # run once, outside the container, to create the session file
mkdir -p data && mv repost_bot.session* data/   # or login.py directly if TG_SESSION_NAME was already data/repost_bot
docker compose up -d --build
```

The `./data` folder is bind-mounted into the container so the session
persists across rebuilds/restarts.

## Known limitations

- **Channels with "restrict saving content" enabled** block media
  downloads for non-admin accounts. The bot logs a warning and skips that
  message rather than crashing.
- **No edit/delete sync** — this is a one-way, fire-and-forget repost. If
  the source edits or deletes a post afterwards, your copy is unaffected.
- **Account risk**: this automates a real Telegram user account. Telegram
  may rate-limit or restrict accounts that join channels or send messages
  too aggressively. The bot honors Telegram's `FloodWait` responses by
  backing off, but you should avoid configuring an excessive number of
  source channels on a brand-new account.
