"""Supabase-backed store for catalogue titles, scrape snapshots and links.

The scraper only works from a network MovieLinkBD does not challenge, so the
scraper usually runs on a local machine while the UI (and even a second API
instance) can run anywhere. This module is the bridge: everything the scraper
learns is written to Postgres, and the API can answer from it when the site is
unreachable.

Tables (see ``docs/DATABASE.md``):

* ``mlbd_titles``  — one row per catalogue title (slug is the primary key)
* ``mlbd_scrapes`` — one row per scraped detail page, with the full JSON payload
* ``mlbd_links``   — every episode source / download link, flattened

Access uses the Supabase REST API, so no extra dependency is needed. All three
tables have RLS enabled and no policies: only a service-role key can read or
write them.

Configuration (``.env`` or the environment):

* ``SUPABASE_URL``                 — ``https://<ref>.supabase.co``
* ``SUPABASE_SERVICE_ROLE_KEY``    — required for writes (dashboard → Settings → API)
* ``SUPABASE_ANON_KEY``            — optional read-only fallback

CLI::

    python -m scraper.store sync      # crawl the catalogue and push titles
    python -m scraper.store stats     # show row counts
"""

from __future__ import annotations

import asyncio
import json
import os
import sys
from datetime import datetime, timedelta, timezone
from typing import Any, Iterable, Sequence
from urllib.parse import quote

import httpx
from loguru import logger

from .catalog import BASE_URL, CatalogItem, catalog_rows, ensure_catalog
from .exceptions import ScraperError

TITLES_TABLE = "mlbd_titles"
SCRAPES_TABLE = "mlbd_scrapes"
LINKS_TABLE = "mlbd_links"

TITLE_BATCH = 400
LINK_BATCH = 200

CATEGORY_TO_STORED = {
    "movie": "movie",
    "series": "series",
    "drama": "drama",
    "anime": "anime",
    "adult": "adult",
    "download18plus": "adult",
}
STORED_TO_CATEGORY = {
    "movie": "movie",
    "series": "series",
    "drama": "drama",
    "anime": "anime",
    "adult": "adult",
}


def _read_config() -> tuple[str, str]:
    """Return ``(url, key)`` from the environment, or two empty strings."""
    url = (os.getenv("SUPABASE_URL") or "").strip().rstrip("/")
    key = (
        os.getenv("SUPABASE_SERVICE_ROLE_KEY")
        or os.getenv("SUPABASE_KEY")
        or os.getenv("SUPABASE_ANON_KEY")
        or ""
    ).strip()
    return url, key


def configured() -> bool:
    """True when a Supabase URL and key are present."""
    url, key = _read_config()
    return bool(url and key)


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _as_int(value: Any) -> int | None:
    try:
        return int(value) if value is not None else None
    except (TypeError, ValueError):
        return None


def _clean_token(token: str) -> str:
    """Strip PostgREST syntax characters from a search token."""
    return token.replace(",", " ").replace("(", " ").replace(")", " ").replace(
        "*", " "
    ).replace("\\", " ").strip()


class SupabaseStore:
    """Thin async wrapper over PostgREST for the ``mlbd_*`` tables."""

    def __init__(
        self, url: str, key: str, timeout: float = 30.0, probe: bool = True
    ) -> None:
        self.url = url.rstrip("/")
        self.key = key
        self.timeout = timeout
        self.probe = probe  # whether to verify connectivity in status()

    # -- plumbing ---------------------------------------------------------
    def _headers(self, write: bool = False) -> dict[str, str]:
        headers = {
            "apikey": self.key,
            "Authorization": f"Bearer {self.key}",
            "Accept": "application/json",
        }
        if write:
            headers["Content-Type"] = "application/json"
            headers["Prefer"] = "resolution=merge-duplicates,return=minimal"
        return headers

    async def _request(
        self,
        client: httpx.AsyncClient,
        method: str,
        table: str,
        *,
        params: dict[str, str] | None = None,
        json: Any = None,
        write: bool = False,
        headers: dict[str, str] | None = None,
    ) -> httpx.Response:
        merged = self._headers(write=write)
        if headers:
            merged.update(headers)
        response = await client.request(
            method,
            f"{self.url}/rest/v1/{table}",
            params=params,
            json=json,
            headers=merged,
        )
        if response.status_code >= 400:
            raise ScraperError(
                f"Supabase {method} {table} failed with HTTP "
                f"{response.status_code}: {response.text[:300]}"
            )
        return response

    @staticmethod
    def _total(response: httpx.Response) -> int:
        content_range = response.headers.get("content-range", "")
        if "/" in content_range:
            tail = content_range.rsplit("/", 1)[1]
            if tail.isdigit():
                return int(tail)
        return 0

    # -- titles -----------------------------------------------------------
    async def upsert_titles(
        self, rows: Sequence[dict[str, Any]], version: str | None = None
    ) -> int:
        """Insert or update catalogue titles; returns rows sent."""
        if not rows:
            return 0
        payload = []
        for row in rows:
            slug = (row.get("slug") or "").strip()
            if not slug:
                continue
            payload.append(
                {
                    "slug": slug,
                    "title": row.get("title") or slug,
                    "url": row.get("url") or "",
                    "poster": row.get("poster"),
                    "kind": row.get("kind") or row.get("type"),
                    "category": CATEGORY_TO_STORED.get(
                        str(row.get("category") or row.get("type") or ""),
                        "other",
                    ),
                    "year": row.get("year"),
                    "adult": bool(row.get("adult")),
                    "quality": row.get("quality"),
                    "language": row.get("language"),
                    "rating": row.get("rating"),
                    "source_version": version,
                    "updated_at": _now_iso(),
                }
            )

        sent = 0
        async with httpx.AsyncClient(timeout=self.timeout) as client:
            for start in range(0, len(payload), TITLE_BATCH):
                chunk = payload[start : start + TITLE_BATCH]
                await self._request(
                    client,
                    "POST",
                    TITLES_TABLE,
                    params={"on_conflict": "slug"},
                    json=chunk,
                    write=True,
                )
                sent += len(chunk)
                logger.info("Pushed {}/{} titles", sent, len(payload))
        return sent

    async def search_titles(
        self,
        query: str = "",
        category: str = "all",
        limit: int = 24,
        offset: int = 0,
    ) -> tuple[list[CatalogItem], int]:
        """Search stored titles; returns ``(items, total)``."""
        params: dict[str, str] = {
            "select": (
                "slug,title,url,poster,kind,category,year,adult,"
                "quality,language,rating"
            ),
            "order": "year.desc.nullslast,title.asc",
            "limit": str(max(0, limit)),
            "offset": str(max(0, offset)),
        }
        if category != "all":
            stored = CATEGORY_TO_STORED.get(category, category)
            params["category"] = f"eq.{stored}"

        tokens = [
            token
            for token in (_clean_token(part) for part in query.split())
            if token
        ][:4]
        if tokens:
            clauses = ",".join(
                f"title.ilike.*{token}*" for token in tokens
            )
            params["and"] = f"({clauses})"
            params["title"] = "not.is.null"

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            response = await self._request(
                client,
                "GET",
                TITLES_TABLE,
                params=params,
                headers={"Prefer": "count=exact"},
            )
        rows = response.json()
        items: list[CatalogItem] = []
        for row in rows if isinstance(rows, list) else []:
            stored = str(row.get("category") or "other")
            items.append(
                CatalogItem(
                    title=row.get("title") or "",
                    url=row.get("url") or "",
                    slug=row.get("slug"),
                    poster=row.get("poster"),
                    type=row.get("kind"),
                    category=STORED_TO_CATEGORY.get(stored, "other"),  # type: ignore[arg-type]
                    year=row.get("year"),
                    adult=bool(row.get("adult")),
                    quality=row.get("quality"),
                    language=row.get("language"),
                    rating=row.get("rating"),
                )
            )
        return items, self._total(response)

    async def title_count(self) -> int:
        async with httpx.AsyncClient(timeout=self.timeout) as client:
            response = await self._request(
                client,
                "HEAD",
                TITLES_TABLE,
                params={"select": "slug"},
                headers={"Prefer": "count=exact", "Range": "0-0"},
            )
        return self._total(response)

    # -- scrapes and links ------------------------------------------------
    async def save_scrape(
        self, result: dict[str, Any], requested_url: str | None = None
    ) -> dict[str, int]:
        """Store one scrape payload plus its flattened links."""
        page_url = (result.get("page_url") or "").strip()
        if not page_url:
            raise ScraperError("Cannot store a scrape without page_url")

        # Payloads may carry datetime objects; jsonb only takes JSON types.
        result = json.loads(json.dumps(result, default=str))

        episodes = result.get("episodes") or []
        downloads = result.get("downloads") or []
        sources = result.get("sources") or []
        scraped_at = result.get("scraped_at") or _now_iso()

        row = {
            "page_url": page_url,
            "requested_url": (requested_url or page_url).strip(),
            "slug": page_url.rstrip("/").rsplit("/", 1)[-1],
            "title": result.get("title"),
            "poster": result.get("poster"),
            "content_type": result.get("content_type"),
            "category": result.get("category"),
            "adult": bool(result.get("adult")),
            "completed": result.get("completed"),
            "episode_count": len(episodes),
            "source_count": len(sources),
            "download_count": len(downloads),
            "payload": result,
            "scraped_at": scraped_at,
            "updated_at": _now_iso(),
        }

        links = _link_rows(page_url, result.get("title"), result)

        async with httpx.AsyncClient(timeout=self.timeout) as client:
            await self._request(
                client,
                "POST",
                SCRAPES_TABLE,
                params={"on_conflict": "page_url"},
                json=[row],
                write=True,
            )
            await self._request(
                client,
                "DELETE",
                LINKS_TABLE,
                params={"page_url": f"eq.{page_url}"},
                write=True,
            )
            for start in range(0, len(links), LINK_BATCH):
                chunk = links[start : start + LINK_BATCH]
                headers = {
                    "Prefer": "resolution=ignore-duplicates,return=minimal"
                }
                await self._request(
                    client,
                    "POST",
                    LINKS_TABLE,
                    json=chunk,
                    write=True,
                    headers=headers,
                )
        return {"links": len(links), "episodes": len(episodes)}

    async def cached_scrape(
        self, page_url: str, max_age_seconds: int
    ) -> tuple[dict[str, Any], int] | None:
        """Return ``(payload, age_seconds)`` when a fresh snapshot is stored."""
        cutoff = datetime.now(timezone.utc) - timedelta(seconds=max_age_seconds)
        escaped = page_url.replace(",", "%2C").replace("(", "%28").replace(")", "%29")
        params = {
            "select": "payload,updated_at,scraped_at",
            "or": f"(page_url.eq.{escaped},requested_url.eq.{escaped})",
            "updated_at": f"gte.{cutoff.isoformat()}",
            "order": "updated_at.desc",
            "limit": "1",
        }
        async with httpx.AsyncClient(timeout=self.timeout) as client:
            response = await self._request(
                client, "GET", SCRAPES_TABLE, params=params
            )
        rows = response.json()
        if not isinstance(rows, list) or not rows:
            return None
        entry = rows[0]
        payload = entry.get("payload")
        if not isinstance(payload, dict):
            return None
        updated = entry.get("updated_at") or ""
        age = 0
        try:
            stamp = datetime.fromisoformat(str(updated).replace("Z", "+00:00"))
            age = max(0, int((datetime.now(timezone.utc) - stamp).total_seconds()))
        except ValueError:
            pass
        return payload, age

    async def links_for(self, page_url: str) -> list[dict[str, Any]]:
        """Return stored links for one page (newest first)."""
        params = {
            "select": (
                "episode_label,season,episode_number,provider,quality,audio,"
                "kind,codec,size_bytes,content_type,stream_url,download_url,"
                "is_best"
            ),
            "page_url": f"eq.{page_url}",
            "order": "episode_number.asc.nullslast,quality.asc.nullslast",
        }
        async with httpx.AsyncClient(timeout=self.timeout) as client:
            response = await self._request(
                client, "GET", LINKS_TABLE, params=params
            )
        rows = response.json()
        return rows if isinstance(rows, list) else []

    # -- status -----------------------------------------------------------
    async def stats(self) -> dict[str, Any]:
        """Row counts per table, plus a lightweight connectivity check."""
        counts: dict[str, int] = {}
        async with httpx.AsyncClient(timeout=self.timeout) as client:
            for table, key in (
                (TITLES_TABLE, "titles"),
                (SCRAPES_TABLE, "pages"),
                (LINKS_TABLE, "links"),
            ):
                response = await self._request(
                    client,
                    "HEAD",
                    table,
                    params={"select": "*"},
                    headers={"Prefer": "count=exact", "Range": "0-0"},
                )
                counts[key] = self._total(response)
            latest: dict[str, Any] = {}
            latest_response = await self._request(
                client,
                "GET",
                SCRAPES_TABLE,
                params={
                    "select": "title,page_url,updated_at",
                    "order": "updated_at.desc",
                    "limit": "1",
                },
            )
            rows = latest_response.json()
            if isinstance(rows, list) and rows:
                latest = rows[0]
        return {"ok": True, "tables": counts, "latest_scrape": latest}


def _link_rows(
    page_url: str, title: str | None, result: dict[str, Any]
) -> list[dict[str, Any]]:
    """Flatten episodes, sources and downloads into link rows (deduplicated)."""
    rows: dict[tuple[str, str], dict[str, Any]] = {}

    def add(row: dict[str, Any]) -> None:
        if not row.get("stream_url"):
            return
        key = (str(row.get("episode_label") or ""), str(row["stream_url"]))
        rows.setdefault(key, row)

    for episode in result.get("episodes") or []:
        label = str(episode.get("label") or "")
        for source in episode.get("sources") or []:
            add(
                {
                    "page_url": page_url,
                    "title": title,
                    "episode_label": label,
                    "season": _as_int(episode.get("season")),
                    "episode_number": _as_int(episode.get("number")),
                    "provider": source.get("provider"),
                    "quality": source.get("quality")
                    or source.get("quality_label"),
                    "audio": source.get("audio"),
                    "kind": source.get("type"),
                    "codec": source.get("codec"),
                    "size_bytes": _as_int(source.get("size_bytes")),
                    "content_type": source.get("content_type"),
                    "stream_url": source.get("stream_url"),
                    "download_url": source.get("download_url"),
                    "is_best": bool(source.get("is_best")),
                    "updated_at": _now_iso(),
                }
            )

    for download in result.get("downloads") or []:
        add(
            {
                "page_url": page_url,
                "title": title,
                "episode_label": str(download.get("episode") or ""),
                "season": None,
                "episode_number": None,
                "provider": None,
                "quality": download.get("quality"),
                "audio": download.get("audio"),
                "kind": download.get("type"),
                "codec": None,
                "size_bytes": _as_int(download.get("size_bytes")),
                "content_type": None,
                "stream_url": download.get("url"),
                "download_url": download.get("url"),
                "is_best": False,
                "updated_at": _now_iso(),
            }
        )

    return list(rows.values())


_store: SupabaseStore | None = None


def get_store() -> SupabaseStore | None:
    """Return the process-wide store, or ``None`` when Supabase is unset."""
    global _store
    url, key = _read_config()
    if not url or not key:
        return None
    if _store is None or _store.url != url or _store.key != key:
        _store = SupabaseStore(url, key)
        logger.info("Supabase store ready at {}", url)
    return _store


async def sync_catalog(force: bool = False) -> dict[str, Any]:
    """Load the local catalogue index and push every title to Supabase."""
    store = get_store()
    if store is None:
        raise ScraperError(
            "Supabase is not configured: set SUPABASE_URL and "
            "SUPABASE_SERVICE_ROLE_KEY in .env"
        )

    count = await ensure_catalog(force=force)
    version, raw_rows = await catalog_rows()

    rows: list[dict[str, Any]] = []
    for row in raw_rows:
        title, slug, poster, kind, year, adult = row[:6]
        rows.append(
            {
                "slug": (slug or "").strip(),
                "title": (title or "").strip(),
                "url": f"{BASE_URL}/{kind}/{quote(str(slug).strip(), safe='')}",
                "poster": poster,
                "kind": kind,
                "category": CATEGORY_TO_STORED.get(str(kind), "other"),
                "year": str(year).strip() if year else None,
                "adult": bool(adult) or kind == "download18plus",
            }
        )

    pushed = await store.upsert_titles(rows, version=version)
    logger.info("Synced {} catalogue titles to Supabase", pushed)
    return {
        "ok": True,
        "local_titles": count,
        "pushed": pushed,
        "version": version,
        "tables": (await store.stats())["tables"],
    }


async def _cli(argv: Iterable[str]) -> int:
    args = list(argv)
    command = args[0] if args else "stats"

    if command == "sync":
        result = await sync_catalog(force="--force" in args)
        print(result)
        return 0

    if command == "stats":
        store = get_store()
        if store is None:
            print("Supabase is not configured: set SUPABASE_URL and a key in .env")
            return 1
        stats = await store.stats()
        print(stats)
        return 0

    print("Usage: python -m scraper.store [sync [--force] | stats]")
    return 2


def main(argv: Sequence[str] | None = None) -> int:
    try:
        from dotenv import load_dotenv
    except ImportError:  # pragma: no cover - python-dotenv is optional
        load_dotenv = None  # type: ignore[assignment]
    if load_dotenv is not None:
        load_dotenv()
    return asyncio.run(_cli(sys.argv[1:] if argv is None else argv))


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
