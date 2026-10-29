"""Whole-catalogue search for MovieLinkBD.

The public ``/search?q=`` page only returns 25 results and cannot be filtered
or paginated, so it cannot answer "search the whole server".  The site's own
search-suggest index can: a manifest lists the current snapshot version and
the shards (``[a-z0-9]{2}``) that hold compact title rows.

Each shard is a JSON list of rows::

    [title, slug, poster, type, year, adult]

A title can appear in several shards (one per significant word), so rows are
de-duplicated by ``type:slug``.  Shards are capped at 240 rows; for capped
shards we also query the site's live fallback endpoint to recover rows that
were truncated.  The merged index is cached on disk for a few hours.
"""

from __future__ import annotations

import asyncio
import itertools
import json
import os
import string
import time
import unicodedata
from datetime import datetime, timezone
from pathlib import Path
from typing import Literal
from urllib.parse import quote

import httpx
from loguru import logger
from pydantic import BaseModel, Field

from .browser import USER_AGENT
from .exceptions import CloudflareBlocked, ScraperError

BASE_URL = "https://movielinkbd.tv"
MANIFEST_PATH = "/search-suggest/manifest"
SHARD_ROOT = "/assets/search-suggest"
LIVE_PATH = "/api/search-suggest-live.php"

CACHE_TTL_SECONDS = 6 * 60 * 60
MANIFEST_TTL_SECONDS = 30 * 60
HTTP_TIMEOUT = 30.0
CONCURRENCY = 32
SHARD_CAP = 240

CatalogCategory = Literal["all", "movie", "series", "drama", "anime", "adult"]

_VALID_TYPES = {"movie", "series", "drama", "anime", "download18plus"}
_CATEGORY_TYPES = {
    "movie": {"movie"},
    "series": {"series"},
    "drama": {"drama"},
    "anime": {"anime"},
    "adult": {"download18plus"},
}

_HEADERS = {
    "user-agent": USER_AGENT,
    "accept": "application/json, text/plain, */*",
    "accept-language": "en-US,en;q=0.9",
    "referer": f"{BASE_URL}/",
    "sec-fetch-dest": "empty",
    "sec-fetch-mode": "cors",
    "sec-fetch-site": "same-origin",
}

_SHARD_KEYS = [
    "".join(pair)
    for pair in itertools.product(string.ascii_lowercase + string.digits, repeat=2)
]


class CatalogItem(BaseModel):
    """A title row from the catalogue index."""

    title: str = Field(..., description="Title as shown on MovieLinkBD")
    url: str = Field(..., description="Absolute detail page URL")
    slug: str = Field(..., description="Canonical slug")
    poster: str | None = Field(None, description="Poster image URL")
    type: str = Field(..., description='Site type: movie, series, drama, anime, download18plus')
    category: CatalogCategory = Field(
        ..., description="Normalised category, with download18plus -> adult"
    )
    year: str | None = Field(None, description="Release year, if known")
    adult: bool = Field(False, description="Whether the title is adult content")
    # Kept for parity with the site-search item shape.
    quality: str | None = None
    language: str | None = None
    rating: str | None = None


class CatalogResults(BaseModel):
    """A page of whole-catalogue search results."""

    query: str
    category: CatalogCategory = "all"
    results: list[CatalogItem] = Field(default_factory=list)
    total: int = Field(0, description="Total matching titles")
    limit: int = 24
    offset: int = 0
    source: Literal["catalog", "site", "db"] = "catalog"
    catalog_count: int | None = Field(
        None, description="Number of titles in the loaded catalogue index"
    )
    searched_at: datetime = Field(
        default_factory=lambda: datetime.now(timezone.utc)
    )


class _CatalogState:
    """In-memory catalogue index."""

    __slots__ = ("version", "fetched_at", "rows", "by_key", "types")

    def __init__(
        self,
        version: str,
        fetched_at: float,
        rows: list[list],
    ) -> None:
        self.version = version
        self.fetched_at = fetched_at
        self.rows = rows
        self.by_key: dict[str, list] = {}
        self.types: dict[str, int] = {}
        for row in rows:
            key = _row_key(row)
            if not key:
                continue
            self.by_key[key] = row
            self.types[row[3]] = self.types.get(row[3], 0) + 1

    @property
    def count(self) -> int:
        return len(self.by_key)


_state: _CatalogState | None = None
_lock = asyncio.Lock()
_manifest_cache: tuple[float, dict] | None = None


def _cache_dir() -> Path:
    override = os.environ.get("MLBD_CACHE_DIR")
    base = Path(override) if override else Path.home() / ".cache" / "mlbd"
    base.mkdir(parents=True, exist_ok=True)
    return base


def _cache_file(version: str) -> Path:
    safe = "".join(ch for ch in version if ch.isalnum() or ch in "-_")
    return _cache_dir() / f"catalog-{safe}.json"


def _row_key(row: list) -> str | None:
    if not isinstance(row, list) or len(row) < 6:
        return None
    title, slug, _poster, kind = row[0], row[1], row[2], row[3]
    if not isinstance(title, str) or not title.strip():
        return None
    if not isinstance(slug, str) or not slug.strip():
        return None
    if kind not in _VALID_TYPES:
        return None
    return f"{kind}:{slug.strip()}"


def _normalize(value: str) -> str:
    text = unicodedata.normalize("NFKD", str(value or ""))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = text.lower()
    cleaned = []
    for ch in text:
        cleaned.append(ch if ch.isalnum() else " ")
    return " ".join("".join(cleaned).split())


def _year_value(year: str | None) -> int:
    try:
        return int(str(year)[:4])
    except (TypeError, ValueError):
        return 0


def _score(title: str, query: str, tokens: list[str]) -> int:
    if not query:
        return 1
    title_norm = _normalize(title)
    if not title_norm:
        return 0
    if title_norm == query:
        return 1000
    if title_norm.startswith(query):
        return 800
    if query in title_norm:
        return 600

    title_tokens = title_norm.split()
    if tokens and all(
        any(token.startswith(part) for token in title_tokens) for part in tokens
    ):
        return 400
    if tokens and all(
        any(part in token for token in title_tokens) for part in tokens
    ):
        return 300
    return 0


def _to_item(row: list) -> CatalogItem:
    title, slug, poster, kind, year, adult = row[:6]
    category: CatalogCategory = "adult" if kind == "download18plus" else kind  # type: ignore[assignment]
    return CatalogItem(
        title=title.strip(),
        url=f"{BASE_URL}/{kind}/{quote(slug.strip(), safe='')}",
        slug=slug.strip(),
        poster=poster or None,
        type=kind,
        category=category,
        year=str(year).strip() if year else None,
        adult=bool(adult) or kind == "download18plus",
    )


_CHALLENGE_MARKERS = ("just a moment", "challenge-platform", "cf-chl")


def _looks_like_challenge(response: httpx.Response) -> bool:
    """True when the response is a Cloudflare challenge (IP/ASN based)."""
    if response.headers.get("cf-mitigated"):
        return True
    if response.status_code not in (403, 429, 503):
        return False
    head = response.text[:4000].lower()
    return any(marker in head for marker in _CHALLENGE_MARKERS)


async def _get_json(
    client: httpx.AsyncClient, url: str, *, strict: bool = False
) -> object:
    last: Exception | None = None
    challenge = False
    for attempt in range(3):
        try:
            response = await client.get(url)
            if response.status_code == 404:
                return []
            if _looks_like_challenge(response):
                challenge = True
                last = f"HTTP {response.status_code} Cloudflare challenge"
                break
            response.raise_for_status()
            return response.json()
        except (httpx.HTTPError, ValueError) as exc:  # noqa: PERF203
            last = exc
            if attempt < 2:
                await asyncio.sleep(0.6 * (attempt + 1))
    logger.warning("Catalog request failed for {}: {}", url, last)
    if strict and challenge:
        raise CloudflareBlocked(
            "MovieLinkBD is blocking this server's IP with a Cloudflare "
            "challenge, so the scraper cannot read the catalogue. Cloud and "
            "datacentre IPs are challenged; the API works from a residential "
            "connection. Run the API from such a network (or tunnel it) and "
            "point the UI at that URL."
        )
    return []


async def _fetch_manifest(client: httpx.AsyncClient, force: bool = False) -> dict:
    global _manifest_cache
    now = time.time()
    if (
        not force
        and _manifest_cache
        and now - _manifest_cache[0] < MANIFEST_TTL_SECONDS
    ):
        return _manifest_cache[1]

    bucket = int(now // 30000)
    data = await _get_json(
        client, f"{BASE_URL}{MANIFEST_PATH}?r={bucket}", strict=True
    )
    if not isinstance(data, dict) or not data.get("v") or not data.get("ready"):
        raise ScraperError(
            "MovieLinkBD catalogue manifest is not ready "
            "(the site returned an unexpected response)"
        )

    _manifest_cache = (now, data)
    return data


async def _crawl(
    manifest: dict, client: httpx.AsyncClient
) -> list[list]:
    version = str(manifest["v"])
    semaphore = asyncio.Semaphore(CONCURRENCY)
    rows: dict[str, list] = {}
    shard_lengths: dict[str, int] = {}

    async def fetch_static(key: str) -> None:
        async with semaphore:
            data = await _get_json(
                client, f"{BASE_URL}{SHARD_ROOT}/v{version}/{key}.json"
            )
        shard_rows = data if isinstance(data, list) else []
        shard_lengths[key] = len(shard_rows)
        for row in shard_rows:
            row_key = _row_key(row)
            if row_key and row_key not in rows:
                rows[row_key] = row

    logger.info("Catalog crawl started: {} shards", len(_SHARD_KEYS))
    started = time.time()
    await asyncio.gather(*(fetch_static(key) for key in _SHARD_KEYS))

    # Shards are capped, so query the live fallback for the truncated ones.
    capped = [key for key, size in shard_lengths.items() if size >= SHARD_CAP]
    if capped:
        async def fetch_live(key: str) -> None:
            async with semaphore:
                data = await _get_json(
                    client, f"{BASE_URL}{LIVE_PATH}?k={key}"
                )
            for row in data if isinstance(data, list) else []:
                row_key = _row_key(row)
                if row_key and row_key not in rows:
                    rows[row_key] = row

        await asyncio.gather(*(fetch_live(key) for key in capped))

    # A few recently-added titles only appear in the popular feed.
    popular = await _get_json(client, f"{BASE_URL}{LIVE_PATH}?popular=1")
    for row in popular if isinstance(popular, list) else []:
        row_key = _row_key(row)
        if row_key and row_key not in rows:
            rows[row_key] = row

    logger.info(
        "Catalog crawl finished in {:.1f}s: {} unique titles",
        time.time() - started,
        len(rows),
    )
    return list(rows.values())


def _load_cache(version: str) -> _CatalogState | None:
    path = _cache_file(version)
    if not path.exists():
        return None
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
        if payload.get("version") != version:
            return None
        rows = payload.get("rows")
        if not isinstance(rows, list):
            return None
        fetched_at = float(payload.get("fetched_at") or 0)
        return _CatalogState(version, fetched_at, rows)
    except (OSError, ValueError) as exc:
        logger.warning("Could not read catalog cache {}: {}", path, exc)
        return None


def _write_cache(state: _CatalogState) -> None:
    path = _cache_file(state.version)
    payload = {
        "version": state.version,
        "fetched_at": state.fetched_at,
        "count": len(state.rows),
        "rows": state.rows,
    }
    tmp = path.with_suffix(".tmp")
    try:
        tmp.write_text(
            json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
            encoding="utf-8",
        )
        tmp.replace(path)
    except OSError as exc:
        logger.warning("Could not write catalog cache {}: {}", path, exc)
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass


async def _load_state(force: bool = False) -> _CatalogState:
    global _state
    async with _lock:
        async with httpx.AsyncClient(
            headers=_HEADERS,
            timeout=HTTP_TIMEOUT,
            follow_redirects=True,
        ) as client:
            manifest = await _fetch_manifest(client, force=force)
            version = str(manifest["v"])

            if _state and _state.version == version and not force:
                age = time.time() - _state.fetched_at
                if age < CACHE_TTL_SECONDS:
                    return _state

            cached = _load_cache(version)
            if cached and not force:
                age = time.time() - cached.fetched_at
                if age < CACHE_TTL_SECONDS:
                    _state = cached
                    logger.info(
                        "Catalog cache loaded: {} titles ({} min old)",
                        cached.count,
                        int(age // 60),
                    )
                    return cached

            try:
                rows = await _crawl(manifest, client)
            except (httpx.HTTPError, asyncio.TimeoutError) as exc:
                if cached:
                    logger.warning(
                        "Catalog refresh failed ({}); using stale cache",
                        exc,
                    )
                    _state = cached
                    return cached
                raise ScraperError(f"Catalog crawl failed: {exc}") from exc

            state = _CatalogState(version, time.time(), rows)
            if state.count == 0:
                if cached:
                    _state = cached
                    return cached
                raise ScraperError("Catalog crawl returned no titles")

            _state = state
            _write_cache(state)
            return state


async def ensure_catalog(force: bool = False) -> int:
    """Load (and cache) the catalogue index; return the number of titles."""
    state = await _load_state(force=force)
    return state.count


async def catalog_rows() -> tuple[str | None, list[list]]:
    """Return the catalogue version and its raw ``[title, slug, …]`` rows."""
    state = await _load_state()
    return state.version, list(state.rows)


async def catalog_stats() -> dict:
    """Return lightweight status information about the loaded index."""
    if _state is None:
        return {"loaded": False}
    return {
        "loaded": True,
        "version": _state.version,
        "count": _state.count,
        "types": _state.types,
        "age_seconds": int(time.time() - _state.fetched_at),
    }


async def search_catalog(
    query: str = "",
    category: CatalogCategory = "all",
    limit: int = 24,
    offset: int = 0,
) -> CatalogResults:
    """Search every title in the catalogue, optionally filtered by category.

    Args:
        query: Free-text title fragment. Empty returns a browse page.
        category: ``all``, ``movie``, ``series``, ``drama``, ``anime`` or
            ``adult`` (``download18plus``).
        limit: Page size.
        offset: Number of matches to skip.

    Returns:
        A :class:`CatalogResults` page with ``total`` and ``offset`` metadata.
    """
    state = await _load_state()
    q = _normalize(query)
    tokens = q.split()
    allowed = _CATEGORY_TYPES.get(category)

    scored: list[tuple[int, int, str, list]] = []
    for row in state.rows:
        if allowed is not None and row[3] not in allowed:
            continue
        score = _score(row[0], q, tokens)
        if score <= 0:
            continue
        scored.append((score, _year_value(row[4]), row[0].lower(), row))

    scored.sort(key=lambda item: (-item[0], -item[1], item[2]))
    total = len(scored)
    start = max(0, offset)
    page = scored[start : start + max(0, limit)]

    logger.info(
        "Catalog search '{}' category={} -> {} matches (page {}+{})",
        query,
        category,
        total,
        start,
        len(page),
    )
    return CatalogResults(
        query=query,
        category=category,
        results=[_to_item(item[3]) for item in page],
        total=total,
        limit=limit,
        offset=start,
        source="catalog",
        catalog_count=state.count,
    )
