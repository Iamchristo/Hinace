"""One-time interactive login to create the .session file.

Run this directly on a terminal (not inside a detached/non-interactive
Docker container) once, before deploying bot.py:

    python login.py

It will prompt for your phone number, the login code Telegram sends you,
and your 2FA password if you have one set. This creates
`<TG_SESSION_NAME>.session` in this directory, which bot.py then reuses to
stay logged in. Treat that file like a password: never commit it, never
share it.
"""

from telethon.sync import TelegramClient

from config import load_app_config


def main() -> None:
    cfg = load_app_config()
    with TelegramClient(cfg.session_name, cfg.api_id, cfg.api_hash) as client:
        me = client.get_me()
        print(f"Logged in as {me.first_name} (@{me.username}). Session saved as "
              f"'{cfg.session_name}.session'.")


if __name__ == "__main__":
    main()
