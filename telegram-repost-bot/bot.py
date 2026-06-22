import asyncio
import io
import logging
from collections import defaultdict

from telethon import TelegramClient, events
from telethon.errors import FloodWaitError, UserAlreadyParticipantError
from telethon.tl.functions.channels import JoinChannelRequest

from config import load_app_config

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
log = logging.getLogger("repost_bot")

cfg = load_app_config()
client = TelegramClient(cfg.session_name, cfg.api_id, cfg.api_hash)

# grouped_id -> list[Message], buffered while an album is still arriving
_pending_albums: dict[int, list] = defaultdict(list)
_album_flush_tasks: dict[int, asyncio.Task] = {}


async def ensure_joined(source_entities):
    for entity in source_entities:
        try:
            await client(JoinChannelRequest(entity))
        except UserAlreadyParticipantError:
            pass
        except Exception:
            log.exception("Could not join source channel %s", entity)


async def _download_to_buffer(message):
    try:
        data = await client.download_media(message, file=bytes)
    except Exception:
        log.warning(
            "Skipping message %s: media download failed (channel may restrict saving content)",
            message.id,
        )
        return None
    if data is None:
        return None
    buf = io.BytesIO(data)
    buf.name = f"{message.id}{message.file.ext}" if message.file and message.file.ext else f"{message.id}.dat"
    return buf


async def _send_with_retry(send_coro_factory, buffers=()):
    for attempt in range(2):
        for b in buffers:
            b.seek(0)
        try:
            return await send_coro_factory()
        except FloodWaitError as e:
            log.warning("Flood wait: sleeping %s seconds", e.seconds)
            await asyncio.sleep(e.seconds)
    for b in buffers:
        b.seek(0)
    return await send_coro_factory()


async def repost_single(message, destination):
    try:
        if message.media:
            buf = await _download_to_buffer(message)
            if buf is None:
                return
            await _send_with_retry(
                lambda: client.send_file(
                    destination, buf, caption=message.text or "", formatting_entities=message.entities
                ),
                buffers=[buf],
            )
        elif message.text:
            await _send_with_retry(
                lambda: client.send_message(
                    destination, message.text, formatting_entities=message.entities
                )
            )
    except Exception:
        log.exception("Failed to repost message %s from chat %s", message.id, message.chat_id)


async def repost_album(messages, destination):
    messages = sorted(messages, key=lambda m: m.id)
    buffers = [b for b in (await asyncio.gather(*(_download_to_buffer(m) for m in messages))) if b is not None]
    if not buffers:
        return

    caption = next((m.text for m in messages if m.text), "")
    entities = next((m.entities for m in messages if m.text), None)
    try:
        await _send_with_retry(
            lambda: client.send_file(
                destination, buffers, caption=caption, formatting_entities=entities
            ),
            buffers=buffers,
        )
    except Exception:
        log.exception("Failed to repost album (grouped_id=%s)", messages[0].grouped_id)


async def _flush_album(grouped_id, destination):
    await asyncio.sleep(cfg.album_debounce_seconds)
    messages = _pending_albums.pop(grouped_id, [])
    _album_flush_tasks.pop(grouped_id, None)
    if messages:
        await repost_album(messages, destination)


def register_handler(source_entities, destination):
    @client.on(events.NewMessage(chats=source_entities))
    async def handler(event):
        message = event.message
        if message.grouped_id:
            gid = message.grouped_id
            _pending_albums[gid].append(message)
            existing_task = _album_flush_tasks.get(gid)
            if existing_task:
                existing_task.cancel()
            _album_flush_tasks[gid] = asyncio.create_task(_flush_album(gid, destination))
        else:
            await repost_single(message, destination)


async def main():
    await client.start()

    source_entities = [await client.get_entity(source) for source in cfg.channels.sources]
    await ensure_joined(source_entities)

    destination = await client.get_entity(cfg.channels.destination)

    register_handler(source_entities, destination)

    log.info(
        "Watching %d source channel(s), reposting into %s",
        len(source_entities),
        cfg.channels.destination,
    )
    await client.run_until_disconnected()


if __name__ == "__main__":
    asyncio.run(main())
