import textwrap

import pytest

from config import load_channel_config


def test_load_channel_config(tmp_path):
    path = tmp_path / "channels.yaml"
    path.write_text(
        textwrap.dedent(
            """
            destination: "@dest"
            sources:
              - "@one"
              - "@two"
            """
        )
    )

    cfg = load_channel_config(path)

    assert cfg.destination == "@dest"
    assert cfg.sources == ["@one", "@two"]


def test_load_channel_config_requires_destination(tmp_path):
    path = tmp_path / "channels.yaml"
    path.write_text("sources:\n  - '@one'\n")

    with pytest.raises(ValueError, match="destination"):
        load_channel_config(path)


def test_load_channel_config_requires_sources(tmp_path):
    path = tmp_path / "channels.yaml"
    path.write_text("destination: '@dest'\n")

    with pytest.raises(ValueError, match="sources"):
        load_channel_config(path)
