"""Main orchestration: scrape_streams(page_url) -> ScrapeResult.

MovieLinkBD serves the whole player payload for a detail page in an inline
``<script id="mlbdInlinePlayerData">`` element.  The body is XOR-encoded JSON
and contains every episode and every source (stream URL + download URL +
quality + audio) for the title.  That is the primary extraction path.

If the payload is missing (Cloudflare interstitial, new page type) we fall back
to the old Playwright/DOM path, which reads whatever the inline player wrote
into ``<video>`` and captures HLS/MP4 responses.

The ad-gated "UNLOCK & WATCH NOW" button is never clicked and no ad iframe is
followed; only the site's own player data is read.
"""

from __future__ import annotations

import asyncio
import base64
import json
import re
import time
from datetime import datetime, timezone
from urllib.parse import urljoin, urlparse

import httpx
from loguru import logger
from playwright.async_api import Page
from playwright.async_api import TimeoutError as PlaywrightTimeoutError
from tenacity import (
    retry,
    retry_if_exception_type,
    stop_after_attempt,
    wait_exponential,
)

from .browser import USER_AGENT, browser_context
from .downloads import parse_downloads_from_dom, parse_downloads_from_html
from .exceptions import CloudflareBlocked, NoStreamFound, ScraperError
from .hls_parser import parse_master_playlist
from .interceptors import StreamInterceptor, looks_like_stream
from .models import (
    Category,
    DownloadLink,
    Episode,
    EpisodeSource,
    ScrapeResult,
    StreamSource,
    StreamType,
    StreamVariant,
)

PLAYER_WAIT_SECONDS = 25
NUDGE_AFTER_SECONDS = 12
PAYLOAD_TIMEOUT = 30.0
PROBE_TIMEOUT = 8.0
PROBE_CONCURRENCY = 8

# A plain HTTP GET returns a shortened page without the inline payload.  These
# browser-like headers make the server deliver the complete document.
_BROWSER_HEADERS = {
    "user-agent": USER_AGENT,
    "accept": (
        "text/html,application/xhtml+xml,application/xml;q=0.9,"
        "image/avif,image/webp,*/*;q=0.8"
    ),
    "accept-language": "en-US,en;q=0.9",
    "sec-ch-ua": '"Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
    "sec-ch-ua-mobile": "?0",
    "sec-ch-ua-platform": '"Windows"',
    "sec-fetch-dest": "document",
    "sec-fetch-mode": "navigate",
    "sec-fetch-site": "none",
    "sec-fetch-user": "?1",
    "upgrade-insecure-requests": "1",
}

_PAYLOAD_RE = re.compile(
    r'<script\b[^>]*\bid="mlbdInlinePlayerData"[^>]*>(.*?)</script>', re.S | re.I
)
_PAYLOAD_SEED_RE = re.compile(r'\bdata-s="([0-9a-fA-F]+)"')
_PAYLOAD_ROUNDS_RE = re.compile(r'\bdata-r="(\d+)"')

_CLOUDFLARE_TITLE_KEYWORDS = ("Just a moment", "Attention Required")
_CLOUDFLARE_MARKERS = ("Just a moment", "Attention Required", "cf-challenge")

_IFRAME_SELECTOR = (
    'iframe[src*="player"], iframe[src*="embed"], iframe[src*="stream"]'
)

_DOM_STREAMS_JS = """
() => {
  const urls = [];
  const add = (value) => {
    if (!value) return;
    try {
      const url = new URL(value, location.href);
      if (url.protocol === 'http:' || url.protocol === 'https:') {
        if (!urls.includes(url.href)) urls.push(url.href);
      }
    } catch (_) {}
  };
  document.querySelectorAll('#mlbdInlinePlayer video, #mlbdInlinePlayer source, video, source')
    .forEach((el) => {
      add(el.currentSrc);
      add(el.src);
      add(el.getAttribute && el.getAttribute('data-src'));
    });
  return urls;
}
"""

_DOM_DOWNLOADS_JS = """
() => Array.from(document.querySelectorAll('a[data-mlbd-dlk], a[href*="/file/"]'))
  .map((a) => {
    const card = a.closest('.ep-card, [data-ep]');
    const titleEl = card ? card.querySelector('.mlbd-episode-title') : null;
    let episode = '';
    if (titleEl) {
      const clone = titleEl.cloneNode(true);
      clone.querySelectorAll('.mlbd-ep-flag').forEach((node) => node.remove());
      episode = (clone.textContent || '').replace(/\\s+/g, ' ').trim();
    } else if (card) {
      const number = card.getAttribute('data-episode-number');
      const season = card.getAttribute('data-season-number');
      if (number) {
        episode = (season ? 'S' + season + ' ' : '') + 'Episode ' + number;
      }
    }
    return {
      href: a.getAttribute('href') || '',
      label: (a.textContent || '').replace(/\\s+/g, ' ').trim(),
      episode,
    };
  });
"""

_DOM_PAGE_JS = """
() => {
  const text = (el) => el ? (el.textContent || '').replace(/\\s+/g, ' ').trim() : null;
  const meta = (selector) => {
    const el = document.querySelector(selector);
    return el ? el.getAttribute('content') : null;
  };
  return {
    h1: text(document.querySelector('h1')),
    ogTitle: meta('meta[property="og:title"]'),
    poster: meta('meta[property="og:image"]'),
  };
}
"""

_NUDGE_JS = """
() => {
  const video = document.querySelector('#mlbdInlinePlayer video, video');
  if (!video) return false;
  try {
    video.muted = true;
    const result = video.play();
    if (result && typeof result.catch === 'function') result.catch(() => {});
    return true;
  } catch (_) {
    return false;
  }
}
"""

_AD_HOSTS = (
    "doubleclick.net",
    "googlesyndication.com",
    "google-analytics.com",
    "googletagmanager.com",
    "jaya9bangla.com",
    "dramalinkbd.tv",
)


# ---------------------------------------------------------------------------
# Payload decoding
# ---------------------------------------------------------------------------


def decode_inline_player_data(markup: str) -> dict | None:
    """Decode ``#mlbdInlinePlayerData`` into the player JSON payload.

    The payload is base64 + XOR with an LCG stream seeded by ``data-s`` and
    advanced ``data-r`` rounds before the first byte.
    """
    match = _PAYLOAD_RE.search(markup)
    if not match:
        return None

    tag_start = max(0, match.start() - 500)
    tag = markup[tag_start : match.start() + 500]
    seed_match = _PAYLOAD_SEED_RE.search(tag)
    rounds_match = _PAYLOAD_ROUNDS_RE.search(tag)

    try:
        state = int(seed_match.group(1), 16) & 0xFFFFFFFF if seed_match else 0
        rounds = int(rounds_match.group(1)) if rounds_match else 0
        for _ in range(rounds):
            state = (state * 1103515245 + 12345) & 0xFFFFFFFF
        raw = base64.b64decode(match.group(1).strip())
    except (ValueError, TypeError):
        logger.debug("Inline player payload could not be decoded")
        return None

    out = bytearray()
    for byte in raw:
        state = (state * 1103515245 + 12345) & 0xFFFFFFFF
        out.append(byte ^ ((state >> 16) & 255))

    try:
        data = json.loads(out.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as exc:
        logger.debug("Inline player payload is not valid JSON: {}", exc)
        return None
    return data if isinstance(data, dict) else None


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _is_ad_url(url: str) -> bool:
    host = (urlparse(url).hostname or "").lower()
    return any(host == item or host.endswith(f".{item}") for item in _AD_HOSTS)


def _stream_type(
    url: str, content_type: str | None, hint: str | None = None
) -> StreamType:
    hinted = (hint or "").lower().strip().lstrip(".")
    if hinted in ("hls", "mp4", "mkv", "webm"):
        return hinted  # type: ignore[return-value]

    ct = (content_type or "").lower()
    path = urlparse(url).path.lower()
    if "mpegurl" in ct or path.endswith(".m3u8"):
        return "hls"
    if "matroska" in ct or path.endswith(".mkv"):
        return "mkv"
    if "webm" in ct or path.endswith(".webm"):
        return "webm"
    if "mp4" in ct or path.endswith(".mp4"):
        return "mp4"
    return "auto"


def _server_name(url: str, provider: str | None = None) -> str:
    if provider and provider.strip():
        return provider.strip()
    host = (urlparse(url).hostname or "").lower()
    if host == "fsldownload.com" or host.endswith(".fsldownload.com"):
        return "MLBD CDN"
    return host or "Source"


def _quality_text(value: object, label: object = None) -> str | None:
    if isinstance(label, str) and label.strip():
        return label.strip()
    if value is None:
        return None
    if isinstance(value, bool):
        return None
    if isinstance(value, (int, float)):
        number = int(value)
        return f"{number}p" if number > 0 else None
    text = str(value).strip()
    if not text:
        return None
    return f"{text}p" if text.isdigit() else text


def _format_size(size_bytes: int | None) -> str | None:
    if not size_bytes or size_bytes <= 0:
        return None
    units = ["B", "KB", "MB", "GB", "TB"]
    value = float(size_bytes)
    unit = 0
    while value >= 1000 and unit < len(units) - 1:
        value /= 1000.0
        unit += 1
    rounded = round(value) if value >= 100 else round(value, 1)
    return f"{rounded:g} {units[unit]}"


def _category_from_url(page_url: str, payload: dict | None = None) -> Category:
    if payload and payload.get("adult"):
        return "adult"
    segment = ""
    parts = [part for part in urlparse(page_url).path.split("/") if part]
    if parts:
        segment = parts[0].lower()
    mapping: dict[str, Category] = {
        "movie": "movie",
        "movies": "movie",
        "southindian": "movie",
        "series": "series",
        "tvseries": "series",
        "drama": "drama",
        "anime": "anime",
        "adult": "adult",
        "adult18plus": "adult",
        "download18plus": "adult",
    }
    if segment in mapping:
        return mapping[segment]
    content_type = str((payload or {}).get("content_type") or "").lower()
    if content_type in ("movie", "series", "drama", "anime"):
        return content_type  # type: ignore[return-value]
    return "other"


def _is_video_content_type(content_type: str | None) -> bool:
    if not content_type:
        return True
    ct = content_type.lower()
    return (
        ct.startswith("video/")
        or "mpegurl" in ct
        or "dash+xml" in ct
        or ct in ("application/octet-stream", "binary/octet-stream")
    )


async def _probe_media(
    client: httpx.AsyncClient, url: str, referer: str
) -> tuple[str | None, int | None]:
    """Fetch Content-Type and size for a direct media URL."""
    headers = {
        "user-agent": USER_AGENT,
        "referer": referer,
        "accept": "*/*",
    }
    content_type: str | None = None
    size_bytes: int | None = None
    length: str | None = None

    try:
        response = await client.head(url, headers=headers)
        content_type = response.headers.get("content-type")
        length = response.headers.get("content-length")

        if response.status_code >= 400 or not content_type:
            response = await client.get(
                url, headers={**headers, "range": "bytes=0-0"}
            )
            content_type = content_type or response.headers.get("content-type")
            length = (
                length
                or response.headers.get("content-range", "").split("/")[-1]
                or response.headers.get("content-length")
            )
    except httpx.HTTPError as exc:
        logger.debug("Media probe failed for {}: {}", url, exc)
        return content_type, size_bytes

    if length:
        try:
            parsed = int(length)
        except ValueError:
            parsed = None
        if parsed and parsed > 0:
            size_bytes = parsed

    return content_type, size_bytes


async def _probe_many(urls: list[str], referer: str) -> dict[str, tuple[str | None, int | None]]:
    """Probe several media URLs concurrently (bounded)."""
    unique = [url for url in dict.fromkeys(urls) if url]
    results: dict[str, tuple[str | None, int | None]] = {}
    if not unique:
        return results

    semaphore = asyncio.Semaphore(PROBE_CONCURRENCY)

    async with httpx.AsyncClient(
        follow_redirects=True, timeout=PROBE_TIMEOUT
    ) as client:
        async def one(url: str) -> None:
            async with semaphore:
                results[url] = await _probe_media(client, url, referer)

        await asyncio.gather(*(one(url) for url in unique))
    return results


# ---------------------------------------------------------------------------
# Payload path
# ---------------------------------------------------------------------------


async def _fetch_payload(page_url: str) -> tuple[dict | None, str]:
    """Fetch a detail page and decode its inline player payload."""
    async with httpx.AsyncClient(
        headers=_BROWSER_HEADERS,
        timeout=PAYLOAD_TIMEOUT,
        follow_redirects=True,
    ) as client:
        response = await client.get(page_url)

    head = response.text[:6000].lower()
    if response.status_code in (403, 429, 503) or any(
        marker.lower() in head for marker in _CLOUDFLARE_MARKERS
    ):
        raise CloudflareBlocked(page_url)
    if response.status_code >= 400:
        raise ScraperError(
            f"Detail page returned HTTP {response.status_code}"
        )

    return decode_inline_player_data(response.text), str(response.url)


def _episode_label(raw_episode: dict) -> str:
    """Return a consistent, compact label such as ``S03E01`` or ``S03 Ep 45-48``.

    The site mixes formats (``Episode 1``, ``Season 1 Episode 1``, ``S03E01``,
    ``Episode 45-48``).  Normalising keeps the UI and download list aligned
    while preserving the site's range label for season packs.
    """
    site_label = str(raw_episode.get("label") or "").strip()
    number = raw_episode.get("number")
    season = raw_episode.get("season")
    start = raw_episode.get("range_start")
    end = raw_episode.get("range_end")
    kind = str(raw_episode.get("kind") or "").strip().lower()

    if kind == "episode":
        has_season = isinstance(season, int) and season > 0
        if isinstance(start, int) and isinstance(end, int) and start != end:
            return (
                f"S{season:02d} Ep {start}-{end}"
                if has_season
                else f"Ep {start}-{end}"
            )
        if isinstance(number, int) and number > 0:
            return f"S{season:02d}E{number:02d}" if has_season else f"E{number:02d}"

    return site_label or "Source"


def _build_episodes(
    payload: dict,
    probes: dict[str, tuple[str | None, int | None]],
    base_url: str,
) -> tuple[list[Episode], list[StreamSource], list[DownloadLink]]:
    episodes: list[Episode] = []
    flat_sources: list[StreamSource] = []
    downloads: list[DownloadLink] = []
    seen_streams: set[str] = set()
    seen_downloads: set[tuple[str, str | None]] = set()

    raw_episodes = payload.get("episodes")
    if not isinstance(raw_episodes, list):
        raw_episodes = []

    for raw_episode in raw_episodes:
        if not isinstance(raw_episode, dict):
            continue

        label = _episode_label(raw_episode)
        episode_sources: list[EpisodeSource] = []

        raw_sources = raw_episode.get("sources")
        if not isinstance(raw_sources, list):
            raw_sources = []

        for raw_source in raw_sources:
            if not isinstance(raw_source, dict):
                continue
            stream_url = str(raw_source.get("url") or "").strip()
            if not stream_url:
                continue
            stream_url = urljoin(base_url, stream_url)

            content_type, size_bytes = probes.get(stream_url, (None, None))
            if not _is_video_content_type(content_type):
                logger.debug(
                    "Skipping non-video source {} ({})", stream_url, content_type
                )
                continue

            quality = _quality_text(
                raw_source.get("quality"), raw_source.get("quality_label")
            )
            source_type = _stream_type(
                stream_url, content_type, raw_source.get("type")
            )
            download_url = str(raw_source.get("download_url") or "").strip() or None
            if download_url:
                download_url = urljoin(base_url, download_url)
            provider = str(raw_source.get("provider") or "").strip() or None
            audio = str(raw_source.get("audio") or "").strip() or None
            languages = [
                str(item).strip()
                for item in (raw_source.get("audio_languages") or [])
                if str(item).strip()
            ]
            name = str(raw_source.get("name") or "").strip() or None
            codec = str(raw_source.get("codec") or "").strip() or None
            hevc = raw_source.get("hevc")
            is_best = bool(raw_source.get("is_best"))

            episode_sources.append(
                EpisodeSource(
                    id=str(raw_source.get("id") or "").strip() or None,
                    provider=provider,
                    quality=quality,
                    quality_label=str(raw_source.get("quality_label") or "").strip()
                    or None,
                    audio=audio,
                    audio_languages=languages,
                    type=source_type,
                    name=name,
                    codec=codec,
                    hevc=hevc if isinstance(hevc, bool) else None,
                    is_best=is_best,
                    stream_url=stream_url,
                    download_url=download_url,
                    size_bytes=size_bytes,
                    content_type=content_type,
                )
            )

            if stream_url not in seen_streams:
                seen_streams.add(stream_url)
                flat_sources.append(
                    StreamSource(
                        server_name=_server_name(stream_url, provider),
                        type=source_type,
                        master_url=stream_url,
                        variants=[
                            StreamVariant(
                                quality=quality or "auto",
                                bandwidth=None,
                                url=stream_url,
                            )
                        ],
                        size_bytes=size_bytes,
                        content_type=content_type,
                    )
                )

            if download_url:
                key = (download_url, label)
                if key not in seen_downloads:
                    seen_downloads.add(key)
                    size_text = _format_size(size_bytes)
                    parts = [part for part in (quality, audio, size_text) if part]
                    downloads.append(
                        DownloadLink(
                            label=" • ".join(parts) or "Download",
                            quality=quality,
                            size=size_text,
                            size_bytes=size_bytes,
                            episode=label,
                            url=download_url,
                            type=source_type,
                            audio=audio,
                        )
                    )

        number = raw_episode.get("number")
        season = raw_episode.get("season")
        range_start = raw_episode.get("range_start")
        range_end = raw_episode.get("range_end")

        episodes.append(
            Episode(
                id=str(raw_episode.get("id") or label),
                label=label,
                number=number if isinstance(number, int) else None,
                season=season if isinstance(season, int) else None,
                range_start=range_start if isinstance(range_start, int) else None,
                range_end=range_end if isinstance(range_end, int) else None,
                kind=str(raw_episode.get("kind") or "").strip() or None,
                sources=episode_sources,
            )
        )

    return episodes, flat_sources, downloads


async def _scrape_payload(page_url: str, probe: bool = True) -> ScrapeResult:
    payload, final_url = await _fetch_payload(page_url)
    if not payload:
        raise NoStreamFound(
            page_url, debug={"reason": "inline player payload not found"}
        )

    raw_episodes = payload.get("episodes")
    stream_urls: list[str] = []
    if isinstance(raw_episodes, list):
        for episode in raw_episodes:
            if not isinstance(episode, dict):
                continue
            for source in episode.get("sources") or []:
                if isinstance(source, dict) and source.get("url"):
                    stream_urls.append(urljoin(final_url, str(source["url"])))

    probes = (
        await _probe_many(stream_urls, referer=final_url) if probe else {}
    )
    episodes, sources, downloads = _build_episodes(payload, probes, final_url)

    if not episodes and not downloads:
        raise NoStreamFound(
            page_url,
            debug={
                "reason": "payload contained no usable sources",
                "content_type": payload.get("content_type"),
            },
        )

    category = _category_from_url(final_url, payload)
    logger.info(
        "Payload scrape complete: {} episodes, {} sources, {} downloads from {}",
        len(episodes),
        len(sources),
        len(downloads),
        final_url,
    )
    return ScrapeResult(
        page_url=final_url,
        title=str(payload.get("title") or "").strip() or None,
        poster=str(payload.get("poster") or "").strip() or None,
        content_type=str(payload.get("content_type") or "").strip() or None,
        category=category,
        adult=bool(payload.get("adult")) or category == "adult",
        completed=(
            bool(payload.get("completed"))
            if isinstance(payload.get("completed"), bool)
            else None
        ),
        episodes=episodes,
        sources=sources,
        downloads=downloads,
        scraped_at=datetime.now(timezone.utc),
    )


# ---------------------------------------------------------------------------
# Browser fallback
# ---------------------------------------------------------------------------


async def _detect_cloudflare(page: Page) -> bool:
    try:
        title = await page.title()
    except Exception:  # noqa: BLE001
        return False
    return any(kw.lower() in title.lower() for kw in _CLOUDFLARE_TITLE_KEYWORDS)


async def _read_dom_stream_urls(page: Page) -> list[str]:
    try:
        urls = await page.evaluate(_DOM_STREAMS_JS)
    except Exception as exc:  # noqa: BLE001
        logger.debug("Could not read player DOM: {}", exc)
        return []
    return [url for url in urls if url and not _is_ad_url(url)]


async def _nudge_player(page: Page) -> None:
    try:
        await page.evaluate(_NUDGE_JS)
    except Exception as exc:  # noqa: BLE001
        logger.debug("Player nudge failed: {}", exc)


async def _wait_for_player(
    page: Page, interceptor: StreamInterceptor, seconds: int = PLAYER_WAIT_SECONDS
) -> list[str]:
    deadline = time.monotonic() + seconds
    nudged = False

    while time.monotonic() < deadline:
        urls = await _read_dom_stream_urls(page)
        if urls:
            return urls
        if any(
            url.split("?")[0].endswith((".m3u8", ".mpd"))
            for url in interceptor.captured
        ):
            return []

        if not nudged and time.monotonic() > deadline - (seconds - NUDGE_AFTER_SECONDS):
            nudged = True
            await _nudge_player(page)

        await asyncio.sleep(0.75)

    return await _read_dom_stream_urls(page)


async def _read_page_info(page: Page) -> dict:
    try:
        return await page.evaluate(_DOM_PAGE_JS) or {}
    except Exception as exc:  # noqa: BLE001
        logger.debug("Could not read page metadata: {}", exc)
        return {}


async def _read_downloads(page: Page, base_url: str) -> list[DownloadLink]:
    try:
        items = await page.evaluate(_DOM_DOWNLOADS_JS)
    except Exception as exc:  # noqa: BLE001
        logger.debug("Could not read download links from DOM: {}", exc)
        items = []

    downloads = parse_downloads_from_dom(items or [], base_url)
    if downloads:
        return downloads

    try:
        markup = await page.content()
    except Exception:  # noqa: BLE001
        return []
    return parse_downloads_from_html(markup, base_url)


async def _merge_stream_urls(
    dom_urls: list[str], captured: set[str]
) -> list[str]:
    merged: list[str] = []
    for url in dom_urls:
        if url not in merged and not _is_ad_url(url):
            merged.append(url)
    for url in sorted(captured):
        if url not in merged and looks_like_stream(url):
            merged.append(url)
    return merged


async def _follow_player_iframes(
    context, page: Page, interceptor: StreamInterceptor
) -> None:
    try:
        frames = await page.query_selector_all(_IFRAME_SELECTOR)
    except Exception:  # noqa: BLE001
        return

    for frame in frames:
        src = await frame.get_attribute("src")
        if not src or _is_ad_url(src) or "/continue/" in src:
            continue

        child_page = await context.new_page()
        child = StreamInterceptor()
        child.attach(child_page)
        try:
            await child_page.goto(src, wait_until="networkidle", timeout=30000)
            await asyncio.sleep(2)
            interceptor.captured.update(child.captured)
            interceptor.content_types.update(child.content_types)
        except Exception as exc:  # noqa: BLE001
            logger.debug("Iframe navigation failed for {}: {}", src, exc)
        finally:
            await child_page.close()


@retry(
    stop=stop_after_attempt(2),
    wait=wait_exponential(multiplier=1, min=2, max=8),
    retry=retry_if_exception_type(
        (PlaywrightTimeoutError, httpx.TimeoutException)
    ),
    reraise=True,
)
async def _scrape_with_browser(page_url: str, timeout: int = 30) -> ScrapeResult:
    logger.info("Falling back to browser scrape: {}", page_url)

    async with browser_context(headless=True) as context:
        page = await context.new_page()
        interceptor = StreamInterceptor()
        interceptor.attach(page)

        all_response_urls: set[str] = set()
        page.on("response", lambda resp: all_response_urls.add(resp.url))

        try:
            await page.goto(
                page_url, wait_until="networkidle", timeout=timeout * 1000
            )
        except PlaywrightTimeoutError:
            logger.warning("networkidle timed out; continuing with the loaded page")

        if await _detect_cloudflare(page):
            logger.warning("Cloudflare challenge detected: {}", page_url)
            raise CloudflareBlocked(page_url)

        info = await _read_page_info(page)
        title = info.get("h1") or info.get("ogTitle")
        if title:
            title = str(title).replace(" Download | MovieLinkBD TV", "").strip()
        else:
            try:
                title = await page.title()
            except Exception:  # noqa: BLE001
                title = None

        dom_urls = await _wait_for_player(page, interceptor)
        await _follow_player_iframes(context, page, interceptor)
        downloads = await _read_downloads(page, page_url)

        stream_urls = await _merge_stream_urls(dom_urls, interceptor.captured)
        if not stream_urls and not downloads:
            raise NoStreamFound(
                page_url,
                debug={
                    "page_url": page_url,
                    "page_title": title,
                    "captured_stream_urls": sorted(interceptor.captured),
                    "dom_stream_urls": dom_urls,
                    "all_response_urls": sorted(all_response_urls)[:50],
                },
            )

        cookie_dict = {c["name"]: c["value"] for c in await context.cookies()}
        headers = {"referer": page_url, "user-agent": USER_AGENT}
        probes = await _probe_many(stream_urls, referer=page_url)

        sources: list[StreamSource] = []
        for stream_url in stream_urls:
            if stream_url.split("?")[0].endswith(".m3u8"):
                variants = await parse_master_playlist(
                    stream_url, cookies=cookie_dict, headers=headers
                )
                sources.append(
                    StreamSource(
                        server_name=_server_name(stream_url),
                        type="hls",
                        master_url=stream_url,
                        variants=variants,
                    )
                )
                continue

            content_type, size_bytes = probes.get(stream_url, (None, None))
            if not _is_video_content_type(content_type):
                logger.debug(
                    "Skipping non-video URL {} ({})", stream_url, content_type
                )
                continue

            stream_type = _stream_type(stream_url, content_type)
            quality = "auto"
            for link in downloads:
                if link.size_bytes and size_bytes:
                    diff = abs(size_bytes - link.size_bytes) / link.size_bytes
                    if diff <= 0.15 and link.quality:
                        quality = link.quality
                        break

            sources.append(
                StreamSource(
                    server_name=_server_name(stream_url),
                    type=stream_type,
                    master_url=stream_url,
                    variants=[
                        StreamVariant(
                            quality=quality,
                            bandwidth=None,
                            url=stream_url,
                        )
                    ],
                    size_bytes=size_bytes,
                    content_type=content_type,
                )
            )

        category = _category_from_url(page_url)
        logger.info(
            "Browser scrape complete: {} sources, {} downloads from {}",
            len(sources),
            len(downloads),
            page_url,
        )
        return ScrapeResult(
            page_url=page_url,
            title=title or None,
            poster=info.get("poster"),
            content_type=None,
            category=category,
            adult=category == "adult",
            completed=None,
            episodes=[],
            sources=sources,
            downloads=downloads,
            scraped_at=datetime.now(timezone.utc),
        )


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------


async def scrape_streams(
    page_url: str, timeout: int = 30, probe: bool = True
) -> ScrapeResult:
    """Scrape a MovieLinkBD detail page for every episode's stream + download.

    Args:
        page_url: URL of the movie/series/anime/drama detail page.
        timeout: Browser navigation timeout used by the fallback path.
        probe: Whether to HEAD each media URL for size/content-type.

    Returns:
        ScrapeResult with per-episode sources, flat sources, and downloads.

    Raises:
        CloudflareBlocked: If the site served a challenge page.
        NoStreamFound: If neither streams nor download links were found.
        ScraperError: For other request failures.
    """
    logger.info("Starting scrape: {}", page_url)

    try:
        return await _scrape_payload(page_url, probe=probe)
    except (CloudflareBlocked, NoStreamFound, ScraperError) as exc:
        logger.warning("Payload path failed ({}); trying browser", exc)
    except (httpx.HTTPError, asyncio.TimeoutError) as exc:
        logger.warning("Payload request failed ({}); trying browser", exc)

    return await _scrape_with_browser(page_url, timeout=timeout)
