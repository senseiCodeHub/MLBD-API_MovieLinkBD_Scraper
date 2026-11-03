"""Per-title badges (language / type / adult) from MovieLinkBD's listings.

The search-suggest shards the catalogue is built from only carry
``[title, slug, poster, type, year, adult]`` — no language — yet the language
is what every "Bangla", "Hindi Dubbed" or "South Indian Hindi" shelf is
answered from.  The listing pages do carry it: every card wears a
``<span class="language">`` badge ("Bangla", "Hindi Dubbed",
``Dual [Hindi-Tamil]`` …), a ``<span class="type">`` badge ("Movie", "Series",
"Drama", "Anime") and a ``data-18`` flag.

Two listings cover the whole catalogue with 30 cards per page:

    /type/movies  /type/series  /drama  /anime  /adult18plus

Card hrefs are opaque per-deployment handles (``/movie/mKs_…``) that cannot be
joined to anything stored, so the **title** is the join key: cards and
catalogue rows describe the same works with the same text.  Titles are
normalised with :func:`scraper.catalog._normalize`, the same function scoring
uses, so both sides agree on case, punctuation and accents.

The result is cached on disk for a few hours like the catalogue index, and the
crawl is polite (a handful of concurrent requests) because it walks every
listing page on the site.
"""

from __future__ import annotations

import asyncio
import html
import json
import os
import re
import time
from pathlib import Path
from typing import Any

import httpx
from loguru import logger

from .browser import USER_AGENT
from .catalog import BASE_URL, _looks_like_challenge, _normalize
from .exceptions import CloudflareBlocked, ScraperError

# Every listing that shows every title once (kind of the card in parentheses).
ROOTS: dict[str, str] = {
    "movie": "/type/movies",
    "series": "/type/series",
    "drama": "/drama",
    "anime": "/anime",
    "download18plus": "/adult18plus",
}

CACHE_FILE = "taxonomy.json"
CACHE_TTL_SECONDS = 6 * 60 * 60
HTTP_TIMEOUT = 30.0
CONCURRENCY = 8

_CARD_SPLIT = re.compile(r'class="movie-card"')
_HREF = re.compile(r'href="/(?P<kind>movie|series|drama|anime|download18plus)/[^"]+"')
_TITLE = re.compile(r'<a[^>]*class="title"[^>]*>(?P<t>[\s\S]*?)</a>')
_LANGUAGE = re.compile(r'<span class="language">(?P<v>[^<]*)</span>')
_TYPE = re.compile(r'<span class="type[^"]*"[^>]*>(?P<v>[^<]*)</span>')
_ADULT = re.compile(r'data-18="(?P<v>\d+)"')
_PAGES = re.compile(r'data-pages="(?P<n>\d+)"')

_HEADERS = {
    "user-agent": USER_AGENT,
    "accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "accept-language": "en-US,en;q=0.9",
    "referer": f"{BASE_URL}/",
}

_state: dict[str, Any] | None = None
_lock = asyncio.Lock()


class TaxonomyIndex:
    """Normalised title → badge triple, as the listings report it."""

    __slots__ = ("fetched_at", "by_title", "counts")

    def __init__(self, fetched_at: float, entries: list[dict[str, Any]]) -> None:
        self.fetched_at = fetched_at
        self.by_title: dict[str, dict[str, Any]] = {}
        self.counts = {"titles": 0, "with_language": 0, "pages": 0}
        for entry in entries:
            key = str(entry.get("key") or "")
            if not key:
                continue
            self.by_title[key] = entry
            self.counts["titles"] += 1
            if entry.get("language"):
                self.counts["with_language"] += 1

    @property
    def count(self) -> int:
        return len(self.by_title)

    def badges_for(self, title: str) -> dict[str, Any] | None:
        """The badges recorded for ``title``, or ``None`` when it is unknown."""
        return self.by_title.get(_normalize(title))


def _cache_dir() -> Path:
    override = os.environ.get("MLBD_CACHE_DIR")
    base = Path(override) if override else Path.home() / ".cache" / "mlbd"
    base.mkdir(parents=True, exist_ok=True)
    return base


def _cache_path() -> Path:
    return _cache_dir() / CACHE_FILE


def _load_cache() -> TaxonomyIndex | None:
    path = _cache_path()
    if not path.exists():
        return None
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        entries = payload.get("entries")
        if not isinstance(entries, list) or not entries:
            return None
        return TaxonomyIndex(float(payload.get("fetched_at") or 0), entries)
    except (OSError, ValueError) as exc:
        logger.warning("Could not read taxonomy cache {}: {}", path, exc)
        return None


def _write_cache(index: TaxonomyIndex) -> None:
    path = _cache_path()
    tmp = path.with_suffix(".tmp")
    try:
        tmp.write_text(
            json.dumps(
                {
                    "fetched_at": index.fetched_at,
                    "count": index.count,
                    "entries": list(index.by_title.values()),
                },
                ensure_ascii=False,
                separators=(",", ":"),
            ),
            encoding="utf-8",
        )
        tmp.replace(path)
    except OSError as exc:
        logger.warning("Could not write taxonomy cache {}: {}", path, exc)
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass


def _decode(raw: str) -> str:
    """Strip tags, decode entities and normalise the dashes of a badge."""
    text = html.unescape(raw or "")
    text = re.sub(r"<[^>]+>", "", text)
    for dash in ("\u2013", "\u2014", "\u2212"):
        text = text.replace(dash, "-")
    return " ".join(text.split()).strip()


def parse_cards(page_html: str) -> list[dict[str, Any]]:
    """Every card on one listing page: title, kind and its three badges."""
    out: list[dict[str, Any]] = []
    if not page_html:
        return out
    for chunk in _CARD_SPLIT.split(page_html)[1:]:
        href = _HREF.search(chunk)
        title_match = _TITLE.search(chunk)
        if href is None or title_match is None:
            continue
        title = _decode(title_match.group("t"))
        if not title:
            continue
        language = _LANGUAGE.search(chunk)
        type_badge = _TYPE.search(chunk)
        adult = _ADULT.search(chunk)
        out.append(
            {
                "key": _normalize(title),
                "title": title,
                "kind": href.group("kind"),
                "language": _decode(language.group("v")) if language else "",
                "type": _decode(type_badge.group("v")) if type_badge else "",
                "adult": bool(adult and adult.group("v") == "1"),
            }
        )
    return out


async def _get_html(client: httpx.AsyncClient, url: str, *, strict: bool) -> str | None:
    last: Exception | None = None
    challenge = False
    for attempt in range(3):
        try:
            response = await client.get(url)
            if response.status_code == 404:
                return None
            if _looks_like_challenge(response):
                challenge = True
                last = f"HTTP {response.status_code} Cloudflare challenge"
                break
            response.raise_for_status()
            return response.text
        except httpx.HTTPError as exc:  # noqa: PERF203
            last = exc
            if attempt < 2:
                await asyncio.sleep(0.6 * (attempt + 1))
    if strict and challenge:
        raise CloudflareBlocked(
            "MovieLinkBD is blocking this server's IP with a Cloudflare "
            "challenge, so the language badges cannot be read."
        )
    logger.warning("Taxonomy request failed for {}: {}", url, last)
    return None


async def _crawl(client: httpx.AsyncClient) -> tuple[list[dict[str, Any]], int]:
    """Walk every listing; returns the merged entries and pages fetched."""
    merged: dict[str, dict[str, Any]] = {}
    pages = 0
    semaphore = asyncio.Semaphore(CONCURRENCY)

    def merge(cards: list[dict[str, Any]]) -> None:
        for card in cards:
            key = card["key"]
            if not key:
                continue
            current = merged.get(key)
            if current is None:
                merged[key] = card
            else:
                # A title can surface on several listings; a badge that is
                # missing never erases one already read, and the adult flag
                # sticks as soon as any listing reports it.
                if not current.get("language") and card.get("language"):
                    current["language"] = card["language"]
                if not current.get("type") and card.get("type"):
                    current["type"] = card["type"]
                current["adult"] = current.get("adult") or card.get("adult")

    async def load(url: str, *, strict: bool = False) -> str | None:
        async with semaphore:
            return await _get_html(client, url, strict=strict)

    for kind, path in ROOTS.items():
        base = f"{BASE_URL}{path}"
        first = await load(base, strict=True)
        if not first:
            raise ScraperError(f"Listing {path} did not answer; no badges read")
        cards = parse_cards(first)
        if not cards:
            raise ScraperError(
                f"Listing {path} carried no cards; the badge crawl cannot "
                "trust this response"
            )
        merge(cards)
        pages += 1
        match = _PAGES.search(first)
        page_count = int(match.group("n")) if match else 1

        for start in range(2, page_count + 1, 40):
            urls = [f"{base}/page/{n}" for n in range(start, min(start + 40, page_count + 1))]
            results = await asyncio.gather(*(load(url) for url in urls))
            pages += sum(1 for text in results if text)
            for text in results:
                merge(parse_cards(text or ""))
        logger.info("Taxonomy: {} read ({} pages so far)", kind, pages)

    entries = list(merged.values())
    logger.info(
        "Taxonomy crawl finished: {} titles, {} with a language badge ({} pages)",
        len(entries),
        sum(1 for e in entries if e.get("language")),
        pages,
    )
    return entries, pages


async def taxonomy_index(force: bool = False) -> TaxonomyIndex:
    """Badges for the whole catalogue, cached on disk for a few hours."""
    global _state
    async with _lock:
        now = time.time()
        if _state is not None and not force:
            if now - float(_state.get("fetched_at") or 0) < CACHE_TTL_SECONDS:
                return TaxonomyIndex(
                    float(_state.get("fetched_at") or 0),
                    list(_state.get("entries") or []),
                )

        cached = _load_cache()
        if cached is not None and not force:
            age = now - cached.fetched_at
            if age < CACHE_TTL_SECONDS:
                _state = {"fetched_at": cached.fetched_at, "entries": list(cached.by_title.values())}
                logger.info(
                    "Taxonomy cache loaded: {} titles ({} min old)",
                    cached.count,
                    int(age // 60),
                )
                return cached

        try:
            async with httpx.AsyncClient(
                headers=_HEADERS,
                timeout=HTTP_TIMEOUT,
                follow_redirects=True,
            ) as client:
                entries, _pages = await _crawl(client)
        except (httpx.HTTPError, asyncio.TimeoutError) as exc:
            if cached is not None:
                logger.warning("Taxonomy refresh failed ({}); using stale cache", exc)
                _state = {"fetched_at": cached.fetched_at, "entries": list(cached.by_title.values())}
                return cached
            raise ScraperError(f"Taxonomy crawl failed: {exc}") from exc

        if len(entries) < 1000:
            if cached is not None:
                logger.warning(
                    "Taxonomy crawl returned only {} titles; keeping the cache",
                    len(entries),
                )
                _state = {"fetched_at": cached.fetched_at, "entries": list(cached.by_title.values())}
                return cached
            raise ScraperError("Taxonomy crawl returned almost no titles")

        index = TaxonomyIndex(time.time(), entries)
        _state = {"fetched_at": index.fetched_at, "entries": entries}
        _write_cache(index)
        return index


async def ensure_taxonomy(force: bool = False) -> int:
    """Load (and cache) the badge index; return the number of titles."""
    index = await taxonomy_index(force=force)
    index.counts["pages"] = 0
    return index.count
