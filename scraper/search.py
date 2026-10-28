"""Search MovieLinkBD titles by name.

The public search page (``https://movielinkbd.tv/search?q=...``) is
server-rendered, so a plain HTTP request is enough — no browser needed.
"""

from __future__ import annotations

import html
import re
from datetime import datetime, timezone
from urllib.parse import urljoin

import httpx
from loguru import logger
from pydantic import BaseModel, Field

from .browser import USER_AGENT
from .exceptions import CloudflareBlocked, ScraperError

BASE_URL = "https://movielinkbd.tv"
SEARCH_PATH = "/search"
SEARCH_TIMEOUT = 20.0

_HEADERS = {
    "user-agent": USER_AGENT,
    "accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "accept-language": "en-US,en;q=0.9",
}

_CARD_SPLIT = re.compile(r'<div class="movie-card"', re.I)
_HREF = re.compile(
    r'href="(/(?:movie|series|anime|drama|southIndian|tvseries)/[^"]+)"'
)
_TITLE = re.compile(r'class="title"[^>]*>(.*?)</a>', re.S)
_POSTER = re.compile(r'data-src="([^"]+)"')
_QUALITY = re.compile(r'<span class="quality"[^>]*>([^<]*)</span>')
_LANGUAGE = re.compile(r'<span class="language"[^>]*>([^<]*)</span>')
_TYPE = re.compile(r'<span class="type[^"]*"[^>]*>([^<]*)</span>')
_RATING = re.compile(r'rating-badge">([^<]*)</span>')
_TAG = re.compile(r"<[^>]+>")
_RATING_PREFIX = re.compile(r"^[^\d.]+")

_CLOUDFLARE_MARKERS = ("Attention Required", "Just a moment", "cf-challenge")


class SearchItem(BaseModel):
    """A single title returned by the site search."""

    title: str = Field(..., description="Title as shown on the search page")
    url: str = Field(..., description="Absolute detail page URL")
    poster: str | None = Field(None, description="Poster image URL")
    quality: str | None = Field(None, description='e.g. "WEB-DL", "HDTV"')
    language: str | None = Field(None, description='e.g. "Bangla", "Hindi Dubbed"')
    type: str | None = Field(None, description='e.g. "Movie", "Series"')
    rating: str | None = Field(None, description='Numeric rating, e.g. "6.3"')


class SearchResults(BaseModel):
    """Result set for one search query."""

    query: str
    results: list[SearchItem] = Field(default_factory=list)
    searched_at: datetime = Field(
        default_factory=lambda: datetime.now(timezone.utc)
    )


def _clean(pattern: re.Pattern[str], chunk: str) -> str | None:
    match = pattern.search(chunk)
    if not match:
        return None
    value = html.unescape(_TAG.sub("", match.group(1))).strip()
    return value or None


def parse_search_html(markup: str, limit: int = 24) -> list[SearchItem]:
    """Extract title cards from a MovieLinkBD search page."""
    items: list[SearchItem] = []
    seen: set[str] = set()

    for chunk in _CARD_SPLIT.split(markup)[1:]:
        href = _HREF.search(chunk)
        title = _clean(_TITLE, chunk)
        if not href or not title:
            continue

        url = urljoin(BASE_URL, href.group(1))
        if url in seen:
            continue
        seen.add(url)

        raw_rating = _clean(_RATING, chunk)
        rating = (
            _RATING_PREFIX.sub("", raw_rating).strip() if raw_rating else None
        )

        items.append(
            SearchItem(
                title=title,
                url=url,
                poster=_clean(_POSTER, chunk),
                quality=_clean(_QUALITY, chunk),
                language=_clean(_LANGUAGE, chunk),
                type=_clean(_TYPE, chunk),
                rating=rating or None,
            )
        )

        if len(items) >= limit:
            break

    return items


async def search_content(query: str, limit: int = 24) -> SearchResults:
    """Search MovieLinkBD by title.

    Args:
        query: Free-text title to search for.
        limit: Maximum number of titles to return.

    Returns:
        SearchResults with parsed titles.

    Raises:
        CloudflareBlocked: If the site served a challenge page.
        ScraperError: If the request failed.
    """
    q = query.strip()
    if not q:
        raise ScraperError("Search query is empty")

    try:
        async with httpx.AsyncClient(
            follow_redirects=True,
            timeout=SEARCH_TIMEOUT,
            headers=_HEADERS,
        ) as client:
            response = await client.get(
                f"{BASE_URL}{SEARCH_PATH}", params={"q": q}
            )
    except httpx.HTTPError as exc:
        raise ScraperError(f"Search request failed: {exc}") from exc

    head = response.text[:5000]
    if response.status_code in (403, 429, 503) or any(
        marker.lower() in head.lower() for marker in _CLOUDFLARE_MARKERS
    ):
        raise CloudflareBlocked(f"{BASE_URL}{SEARCH_PATH}?q={q}")

    if response.status_code >= 400:
        raise ScraperError(
            f"Search request failed with HTTP {response.status_code}"
        )

    items = parse_search_html(response.text, limit=limit)
    logger.info("Search '{}' returned {} titles", q, len(items))
    return SearchResults(query=q, results=items)
