from dataclasses import dataclass
from pathlib import Path

import yaml
from dotenv import load_dotenv
import os

load_dotenv()

BASE_DIR = Path(__file__).resolve().parent
CHANNELS_FILE = BASE_DIR / "channels.yaml"


@dataclass(frozen=True)
class ChannelConfig:
    destination: str
    sources: list[str]


@dataclass(frozen=True)
class AppConfig:
    api_id: int
    api_hash: str
    session_name: str
    album_debounce_seconds: float
    channels: ChannelConfig


def load_channel_config(path: Path = CHANNELS_FILE) -> ChannelConfig:
    with open(path, "r") as f:
        data = yaml.safe_load(f) or {}

    destination = data.get("destination")
    sources = data.get("sources") or []

    if not destination:
        raise ValueError(f"{path} must define a 'destination' channel")
    if not sources:
        raise ValueError(f"{path} must define at least one entry under 'sources'")

    return ChannelConfig(destination=str(destination), sources=[str(s) for s in sources])


def load_app_config() -> AppConfig:
    api_id = os.environ.get("TG_API_ID")
    api_hash = os.environ.get("TG_API_HASH")

    if not api_id or not api_hash:
        raise ValueError(
            "TG_API_ID and TG_API_HASH must be set (see .env.example). "
            "Get them from https://my.telegram.org"
        )

    session_name = os.environ.get("TG_SESSION_NAME", "repost_bot")
    session_dir = (BASE_DIR / session_name).parent
    session_dir.mkdir(parents=True, exist_ok=True)

    return AppConfig(
        api_id=int(api_id),
        api_hash=api_hash,
        session_name=session_name,
        album_debounce_seconds=float(os.environ.get("TG_ALBUM_DEBOUNCE_SECONDS", "2")),
        channels=load_channel_config(),
    )
