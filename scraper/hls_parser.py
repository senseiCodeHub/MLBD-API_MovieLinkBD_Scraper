"""Parse HLS master playlists into quality variants."""

from __future__ import annotations

from urllib.parse import urljoin

import httpx
import m3u8
from loguru import logger

from .models import StreamVariant


async def parse_master_playlist(
    url: str,
    cookies: dict | None = None,
    headers: dict | None = None,
) -> list[StreamVariant]:
    """Fetch a master .m3u8 playlist and return its quality variants.

    Args:
        url: Absolute URL of the master playlist.
        cookies: Optional cookies to send with the request.
        headers: Optional headers to send with the request.

    Returns:
        A list of StreamVariant objects, one per advertised quality.
        Falls back to a single "auto" variant for non-master playlists
        or when parsing fails.
    """
    try:
        async with httpx.AsyncClient(follow_redirects=True) as client:
            resp = await client.get(
                url, cookies=cookies, headers=headers, timeout=20
            )
            resp.raise_for_status()
    except httpx.HTTPError as exc:
        logger.warning("Failed to fetch master playlist {}: {}", url, exc)
        return []

    playlist = m3u8.loads(resp.text, uri=url)
    # m3u8 >= 6 uses is_variant/playlists; older versions used is_master.
    is_master = getattr(playlist, "is_master", None)
    if is_master is None:
        is_master = bool(playlist.playlists)

    variants: list[StreamVariant] = []

    if is_master and playlist.playlists:
        for pl in playlist.playlists or []:
            info = pl.stream_info
            resolution = info.resolution if info else None
            height = resolution[1] if resolution and len(resolution) > 1 else None
            quality = f"{height}p" if height else "auto"
            variants.append(
                StreamVariant(
                    quality=quality,
                    bandwidth=info.bandwidth if info else None,
                    url=urljoin(url, pl.uri),
                )
            )
    else:
        variants.append(StreamVariant(quality="auto", bandwidth=None, url=url))

    logger.info("Parsed {} variants from {}", len(variants), url)
    return variants
