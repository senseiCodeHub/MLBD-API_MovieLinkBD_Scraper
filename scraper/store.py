"""MySQL-backed store for catalogue titles, scrape snapshots and links.

The scraper only works from a network MovieLinkBD does not challenge, so the
scraper usually runs on a local machine while the UI (and even a second API
instance) can run anywhere. This module is the bridge: everything the scraper
learns is written to MySQL, and the API can answer from it when the site is
unreachable.

Tables (see ``docs/DATABASE.md``):

* ``mlbd_titles``  — one row per catalogue title (slug is the primary key)
* ``mlbd_scrapes`` — one row per scraped detail page, with the full JSON payload
* ``mlbd_links``   — every episode source / download link, flattened

Access uses the async ``aiomysql`` driver. Writes need a MySQL user with
INSERT/UPDATE/DELETE privileges; reads work with any configured account.

Configuration (``.env`` or the environment):

* ``MYSQL_HOST``        — MySQL host (default ``localhost``)
* ``MYSQL_PORT``        — MySQL port (default ``3306``)
* ``MYSQL_USER``        — MySQL user (default ``root``)
* ``MYSQL_PASSWORD``    — MySQL password
* ``MYSQL_DATABASE``    — database name (default ``mlbd``)

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

from aiomysql import create_pool, DictCursor
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

_SCHEMA = f"""
CREATE TABLE IF NOT EXISTS {TITLES_TABLE} (
    slug            VARCHAR(255) PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    url             TEXT,
    poster          TEXT,
    kind            VARCHAR(64),
    category        VARCHAR(64),
    year            VARCHAR(16),
    adult           TINYINT(1) DEFAULT 0,
    quality         VARCHAR(32),
    language        VARCHAR(32),
    rating          VARCHAR(32),
    source_version  VARCHAR(64),
    updated_at      DATETIME(6),
    INDEX idx_titles_category (category),
    INDEX idx_titles_title (title(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {SCRAPES_TABLE} (
    page_url      VARCHAR(512) PRIMARY KEY,
    requested_url VARCHAR(512),
    slug          VARCHAR(255),
    title         VARCHAR(255),
    poster        TEXT,
    content_type  VARCHAR(64),
    category      VARCHAR(64),
    adult         TINYINT(1) DEFAULT 0,
    completed     TINYINT(1),
    episode_count INT DEFAULT 0,
    source_count  INT DEFAULT 0,
    download_count INT DEFAULT 0,
    status        VARCHAR(16) DEFAULT 'saved',
    error         TEXT,
    payload       JSON,
    scraped_at    DATETIME(6),
    updated_at    DATETIME(6),
    INDEX idx_scrapes_updated (updated_at),
    INDEX idx_scrapes_slug (slug),
    INDEX idx_scrapes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {LINKS_TABLE} (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_url       VARCHAR(512),
    title          VARCHAR(255),
    episode_label  VARCHAR(128),
    season         INT,
    episode_number INT,
    provider       VARCHAR(128),
    quality        VARCHAR(32),
    audio          VARCHAR(64),
    kind           VARCHAR(32),
    codec          VARCHAR(32),
    size_bytes     BIGINT,
    content_type   VARCHAR(64),
    stream_url     TEXT,
    download_url   TEXT,
    is_best        TINYINT(1) DEFAULT 0,
    updated_at     DATETIME(6),
    INDEX idx_links_page (page_url),
    INDEX idx_links_stream (stream_url(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""


def _read_config() -> dict[str, Any]:
    return {
        "host": (os.getenv("MYSQL_HOST") or "localhost").strip(),
        "port": int((os.getenv("MYSQL_PORT") or "3306").strip()),
        "user": (os.getenv("MYSQL_USER") or "root").strip(),
        "password": (os.getenv("MYSQL_PASSWORD") or "").strip(),
        "db": (os.getenv("MYSQL_DATABASE") or "mlbd").strip(),
    }


def configured() -> bool:
    """True when a MySQL host and database are configured."""
    cfg = _read_config()
    return bool(cfg["host"] and cfg["db"])


def writable() -> bool:
    """True when MySQL is configured (writes need a privileged user)."""
    return configured()


async def test_connection() -> dict[str, Any]:
    """Try to connect and run a simple query; return {ok, error}."""
    try:
        pool = await get_pool()
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute("SELECT 1")
        return {"ok": True}
    except Exception as exc:  # noqa: BLE001
        return {"ok": False, "error": str(exc)}


def _now() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def _parse_dt(value: Any) -> datetime | None:
    """Coerce a payload timestamp to a naive UTC ``datetime``.

    Scrape payloads carry ISO-8601 strings ending in ``Z`` (for example
    ``2026-10-30T17:11:31.026399Z``), which MySQL rejects for a ``DATETIME``
    column, so parse the value before binding it.
    """
    if isinstance(value, datetime):
        parsed = value
    elif isinstance(value, str) and value.strip():
        try:
            parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
        except ValueError:
            return None
    else:
        return None
    if parsed.tzinfo is not None:
        parsed = parsed.astimezone(timezone.utc).replace(tzinfo=None)
    return parsed


def _as_int(value: Any) -> int | None:
    try:
        return int(value) if value is not None else None
    except (TypeError, ValueError):
        return None


def _clean_token(token: str) -> str:
    """Strip SQL LIKE wildcards and punctuation from a search token."""
    return token.replace(",", " ").replace("(", " ").replace(")", " ").replace(
        "*", " "
    ).replace("\\", " ").replace("%", " ").replace("_", " ").strip()


_pool: Any = None


async def get_pool() -> Any:
    """Lazily create (and auto-create the database + tables) the shared pool."""
    global _pool
    if _pool is None:
        cfg = _read_config()

        # Connect without a database so we can CREATE DATABASE IF NOT EXISTS.
        admin = await create_pool(
            host=cfg["host"],
            port=cfg["port"],
            user=cfg["user"],
            password=cfg["password"],
            autocommit=True,
            charset="utf8mb4",
        )
        async with admin.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(f"CREATE DATABASE IF NOT EXISTS `{cfg['db']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
        admin.close()
        await admin.wait_closed()

        # Now open the real pool against the target database.
        _pool = await create_pool(
            host=cfg["host"],
            port=cfg["port"],
            user=cfg["user"],
            password=cfg["password"],
            db=cfg["db"],
            autocommit=True,
            charset="utf8mb4",
        )
        async with _pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(_SCHEMA)
    return _pool


async def get_store() -> "MySQLStore | None":
    """Return the process-wide MySQL store, or ``None`` when not configured."""
    cfg = _read_config()
    if not cfg["host"] or not cfg["db"]:
        return None
    pool = await get_pool()
    return MySQLStore(pool)


class MySQLStore:
    """Thin async wrapper over aiomysql for the ``mlbd_*`` tables."""

    def __init__(self, pool: Any) -> None:
        self.pool = pool

    async def _fetchall(
        self, query: str, args: tuple = ()
    ) -> list[dict[str, Any]]:
        async with self.pool.acquire() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(query, args)
                return await cur.fetchall() or []

    async def _executemany(
        self, query: str, rows: Sequence[tuple]
    ) -> None:
        if not rows:
            return
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.executemany(query, rows)

    # -- titles -----------------------------------------------------------
    async def upsert_titles(
        self, rows: Sequence[dict[str, Any]], version: str | None = None
    ) -> int:
        """Insert or update catalogue titles; returns rows sent."""
        if not rows:
            return 0
        payload = []
        now = _now()
        for row in rows:
            slug = (row.get("slug") or "").strip()
            if not slug:
                continue
            payload.append(
                (
                    slug,
                    row.get("title") or slug,
                    row.get("url") or "",
                    row.get("poster"),
                    row.get("kind") or row.get("type"),
                    CATEGORY_TO_STORED.get(
                        str(row.get("category") or row.get("type") or ""), "other"
                    ),
                    row.get("year"),
                    bool(row.get("adult")),
                    row.get("quality"),
                    row.get("language"),
                    row.get("rating"),
                    version,
                    now,
                )
            )

        sent = 0
        for start in range(0, len(payload), TITLE_BATCH):
            chunk = payload[start : start + TITLE_BATCH]
            await self._executemany(
                f"""
                INSERT INTO {TITLES_TABLE}
                    (slug, title, url, poster, kind, category, year, adult,
                     quality, language, rating, source_version, updated_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    title=VALUES(title),
                    url=VALUES(url),
                    poster=VALUES(poster),
                    kind=VALUES(kind),
                    category=VALUES(category),
                    year=VALUES(year),
                    adult=VALUES(adult),
                    quality=VALUES(quality),
                    language=VALUES(language),
                    rating=VALUES(rating),
                    source_version=VALUES(source_version),
                    updated_at=VALUES(updated_at)
                """,
                chunk,
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
        limit = max(0, limit)
        offset = max(0, offset)

        where: list[str] = []
        args: list[Any] = []
        if category != "all":
            stored = CATEGORY_TO_STORED.get(category, category)
            where.append("category = %s")
            args.append(stored)

        tokens = [
            token
            for token in (_clean_token(part) for part in query.split())
            if token
        ][:4]
        for token in tokens:
            where.append("title LIKE %s")
            args.append(f"%{token}%")

        clause = f" WHERE {' AND '.join(where)}" if where else ""

        select_cols = (
            "slug,title,url,poster,kind,category,year,adult,"
            "quality,language,rating"
        )
        count_query = f"SELECT COUNT(*) AS c FROM {TITLES_TABLE}{clause}"
        page_query = (
            f"SELECT {select_cols} FROM {TITLES_TABLE}{clause}"
            f" ORDER BY year DESC, title ASC LIMIT %s OFFSET %s"
        )
        page_args = [*args, limit, offset]

        async with self.pool.acquire() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(count_query, tuple(args))
                total_row = await cur.fetchone()
                total = int(total_row["c"]) if total_row else 0
                await cur.execute(page_query, tuple(page_args))
                rows = await cur.fetchall() or []

        items: list[CatalogItem] = []
        for row in rows:
            stored = str(row.get("category") or "other")
            items.append(
                CatalogItem(
                    title=row.get("title") or "",
                    url=row.get("url") or "",
                    slug=row.get("slug"),
                    poster=row.get("poster"),
                    type=row.get("kind"),
                    category=STORED_TO_CATEGORY.get(stored, "other"),
                    year=row.get("year"),
                    adult=bool(row.get("adult")),
                    quality=row.get("quality"),
                    language=row.get("language"),
                    rating=row.get("rating"),
                )
            )
        return items, total

    async def title_count(self) -> int:
        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(f"SELECT COUNT(*) FROM {TITLES_TABLE}")
                row = await cur.fetchone()
        return int(row[0]) if row else 0

    # -- scrapes and links ------------------------------------------------
    async def save_scrape(
        self, result: dict[str, Any], requested_url: str | None = None
    ) -> dict[str, int]:
        """Store one scrape payload plus its flattened links."""
        page_url = (result.get("page_url") or "").strip()
        if not page_url:
            raise ScraperError("Cannot store a scrape without page_url")

        result = json.loads(json.dumps(result, default=str))

        episodes = result.get("episodes") or []
        downloads = result.get("downloads") or []
        sources = result.get("sources") or []
        scraped_at = _parse_dt(result.get("scraped_at")) or _now()

        row = (
            page_url,
            (requested_url or page_url).strip(),
            page_url.rstrip("/").rsplit("/", 1)[-1],
            result.get("title"),
            result.get("poster"),
            result.get("content_type"),
            result.get("category"),
            bool(result.get("adult")),
            result.get("completed"),
            len(episodes),
            len(sources),
            len(downloads),
            json.dumps(result, ensure_ascii=False),
            scraped_at,
            _now(),
        )

        links = _link_rows(page_url, result.get("title"), result)

        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                # The site hands out a new canonical id per request, so the same
                # requested URL comes back under a different page_url. Drop the
                # older row for this request instead of storing a duplicate.
                key = (requested_url or page_url).strip()
                await cur.execute(
                    f"SELECT page_url FROM {SCRAPES_TABLE} "
                    "WHERE requested_url = %s AND page_url <> %s",
                    (key, page_url),
                )
                stale = [r[0] for r in await cur.fetchall()]
                if stale:
                    marks = ",".join(["%s"] * len(stale))
                    await cur.execute(
                        f"DELETE FROM {LINKS_TABLE} WHERE page_url IN ({marks})",
                        tuple(stale),
                    )
                    await cur.execute(
                        f"DELETE FROM {SCRAPES_TABLE} WHERE page_url IN ({marks})",
                        tuple(stale),
                    )
                await cur.execute(
                    f"""
                    INSERT INTO {SCRAPES_TABLE}
                        (page_url, requested_url, slug, title, poster, content_type,
                         category, adult, completed, episode_count, source_count,
                         download_count, payload, scraped_at, updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                    ON DUPLICATE KEY UPDATE
                        requested_url=VALUES(requested_url),
                        slug=VALUES(slug),
                        title=VALUES(title),
                        poster=VALUES(poster),
                        content_type=VALUES(content_type),
                        category=VALUES(category),
                        adult=VALUES(adult),
                        completed=VALUES(completed),
                        episode_count=VALUES(episode_count),
                        source_count=VALUES(source_count),
                        download_count=VALUES(download_count),
                        payload=VALUES(payload),
                        scraped_at=VALUES(scraped_at),
                        updated_at=VALUES(updated_at)
                    """,
                    row,
                )
                await cur.execute(
                    f"DELETE FROM {LINKS_TABLE} WHERE page_url = %s", (page_url,)
                )
                for start in range(0, len(links), LINK_BATCH):
                    chunk = links[start : start + LINK_BATCH]
                    await cur.executemany(
                        f"""
                        INSERT INTO {LINKS_TABLE}
                            (page_url, title, episode_label, season, episode_number,
                             provider, quality, audio, kind, codec, size_bytes,
                             content_type, stream_url, download_url, is_best, updated_at)
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                        """,
                        [
                            (
                                l["page_url"], l["title"], l["episode_label"],
                                l["season"], l["episode_number"], l["provider"],
                                l["quality"], l["audio"], l["kind"], l["codec"],
                                l["size_bytes"], l["content_type"], l["stream_url"],
                                l["download_url"], l["is_best"], l["updated_at"],
                            )
                            for l in chunk
                        ],
                    )
        return {"links": len(links), "episodes": len(episodes)}

    async def cached_scrape(
        self, page_url: str, max_age_seconds: int
    ) -> tuple[dict[str, Any], int] | None:
        """Return ``(payload, age_seconds)`` when a fresh snapshot is stored."""
        cutoff = _now() - timedelta(seconds=max_age_seconds)
        async with self.pool.acquire() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(
                    f"""
                    SELECT payload, updated_at, scraped_at
                    FROM {SCRAPES_TABLE}
                    WHERE (page_url = %s OR requested_url = %s)
                      AND updated_at >= %s
                    ORDER BY updated_at DESC
                    LIMIT 1
                    """,
                    (page_url, page_url, cutoff),
                )
                rows = await cur.fetchall() or []
        if not rows:
            return None
        entry = rows[0]
        payload = entry.get("payload")
        if isinstance(payload, str):
            try:
                payload = json.loads(payload)
            except ValueError:
                return None
        if not isinstance(payload, dict):
            return None
        updated = entry.get("updated_at")
        age = 0
        if isinstance(updated, datetime):
            now = datetime.now(timezone.utc).replace(tzinfo=None)
            age = max(0, int((now - updated).total_seconds()))
        return payload, age

    async def links_for(self, page_url: str) -> list[dict[str, Any]]:
        """Return stored links for one page (newest first)."""
        return await self._fetchall(
            f"""
            SELECT episode_label, season, episode_number, provider, quality, audio,
                   kind, codec, size_bytes, content_type, stream_url, download_url,
                   is_best
            FROM {LINKS_TABLE}
            WHERE page_url = %s
            ORDER BY episode_number ASC, quality ASC
            """,
            (page_url,),
        )

    # -- library tracking (MySQL as primary store, replacing SQLite) -----------
    async def record_scrape_result(
        self,
        page_url: str,
        slug: str,
        title: str,
        has_streams: bool,
        error_message: str | None,
        payload: dict[str, Any],
        scraped_at: datetime | None = None,
    ) -> None:
        """Save a scrape result, its flattened links, and record its status (saved / empty / failed)."""
        status = "failed" if error_message else ("saved" if has_streams else "empty")
        now = scraped_at or _now()
        result = json.loads(json.dumps(payload, default=str))
        payload_json = json.dumps(result, ensure_ascii=False)

        links = _link_rows(page_url, title, result)

        async with self.pool.acquire() as conn:
            async with conn.cursor() as cur:
                # The catalogue URL and the scraper's canonical page URL differ
                # (``/anime/title-slug`` vs ``/anime/mKs_...``), so one title can
                # be stored under two page_urls. Drop any older row for this slug
                # so a re-scrape updates the title instead of duplicating it.
                await cur.execute(
                    f"SELECT page_url FROM {SCRAPES_TABLE} "
                    "WHERE slug = %s AND page_url <> %s",
                    (slug, page_url),
                )
                stale = [row[0] for row in await cur.fetchall()]
                if stale:
                    marks = ",".join(["%s"] * len(stale))
                    await cur.execute(
                        f"DELETE FROM {LINKS_TABLE} WHERE page_url IN ({marks})",
                        tuple(stale),
                    )
                    await cur.execute(
                        f"DELETE FROM {SCRAPES_TABLE} WHERE page_url IN ({marks})",
                        tuple(stale),
                    )
                await cur.execute(
                    f"""
                    INSERT INTO {SCRAPES_TABLE}
                        (page_url, slug, title, status, error, payload, scraped_at, updated_at)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
                    ON DUPLICATE KEY UPDATE
                        slug=VALUES(slug),
                        title=VALUES(title),
                        status=VALUES(status),
                        error=VALUES(error),
                        payload=VALUES(payload),
                        scraped_at=VALUES(scraped_at),
                        updated_at=VALUES(updated_at)
                    """,
                    (page_url, slug, title, status, error_message, payload_json, now, now),
                )
                await cur.execute(
                    f"DELETE FROM {LINKS_TABLE} WHERE page_url = %s", (page_url,)
                )
                for start in range(0, len(links), LINK_BATCH):
                    chunk = links[start : start + LINK_BATCH]
                    await cur.executemany(
                        f"""
                        INSERT INTO {LINKS_TABLE}
                            (page_url, title, episode_label, season, episode_number,
                             provider, quality, audio, kind, codec, size_bytes,
                             content_type, stream_url, download_url, is_best, updated_at)
                        VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                        """,
                        [
                            (
                                l["page_url"], l["title"], l["episode_label"],
                                l["season"], l["episode_number"], l["provider"],
                                l["quality"], l["audio"], l["kind"], l["codec"],
                                l["size_bytes"], l["content_type"], l["stream_url"],
                                l["download_url"], l["is_best"], l["updated_at"],
                            )
                            for l in chunk
                        ],
                    )

    async def get_scraped_keys(self) -> set[str]:
        """Return page_urls that have already been scraped (saved/empty/failed)."""
        rows = await self._fetchall(
            f"SELECT page_url FROM {SCRAPES_TABLE}"
        )
        return {row["page_url"] for row in rows}

    async def get_scraped_slugs(self) -> set[str]:
        """Return catalogue slugs that have already been scraped.

        The sync queue holds catalogue URLs (``/anime/title-slug``) while rows are
        stored under the scraper's canonical URL (``/anime/mKs_...``), so matching
        on page_url alone never hits. Slugs are stable across both, which is what
        makes "already saved" detection — and therefore *Skipped* — actually work.
        """
        rows = await self._fetchall(
            f"SELECT DISTINCT slug FROM {SCRAPES_TABLE} "
            "WHERE slug IS NOT NULL AND slug <> ''"
        )
        return {row["slug"] for row in rows}

    async def get_scrape_detail(self, page_url: str) -> dict[str, Any] | None:
        """Return one stored scrape with its flattened links (for the detail view)."""
        rows = await self._fetchall(
            f"""
            SELECT page_url, slug, title, status, error, payload, scraped_at, updated_at
            FROM {SCRAPES_TABLE}
            WHERE page_url = %s
            ORDER BY updated_at DESC
            LIMIT 1
            """,
            (page_url,),
        )
        if not rows:
            return None
        row = rows[0]
        payload = row.get("payload")
        if isinstance(payload, str):
            try:
                payload = json.loads(payload)
            except ValueError:
                payload = {}
        if not isinstance(payload, dict):
            payload = {}
        links = await self.links_for(page_url)
        episodes = []
        for ep in (payload.get("episodes") or []):
            ep_sources = []
            for src in (ep.get("sources") or []):
                ep_sources.append({
                    "id": src.get("id"),
                    "provider": src.get("provider"),
                    "quality": src.get("quality"),
                    "quality_label": src.get("quality_label"),
                    "audio": src.get("audio"),
                    "audio_languages": src.get("audio_languages"),
                    "type": src.get("type"),
                    "name": src.get("name"),
                    "codec": src.get("codec"),
                    "hevc": src.get("hevc"),
                    "is_best": src.get("is_best"),
                    "stream_url": src.get("stream_url"),
                    "download_url": src.get("download_url"),
                    "size_bytes": src.get("size_bytes"),
                    "content_type": src.get("content_type"),
                })
            episodes.append({
                "id": ep.get("id"),
                "label": ep.get("label"),
                "number": ep.get("number"),
                "season": ep.get("season"),
                "range_start": ep.get("range_start"),
                "range_end": ep.get("range_end"),
                "kind": ep.get("kind"),
                "sources": ep_sources,
            })
        downloads = [
            {
                "label": d.get("label"),
                "quality": d.get("quality"),
                "size": d.get("size"),
                "size_bytes": d.get("size_bytes"),
                "episode": d.get("episode"),
                "url": d.get("url"),
                "type": d.get("type"),
                "audio": d.get("audio"),
            }
            for d in (payload.get("downloads") or [])
        ]
        return {
            "page_url": row.get("page_url"),
            "slug": row.get("slug"),
            "title": row.get("title"),
            "status": row.get("status"),
            "error": row.get("error"),
            "payload": payload,
            "episodes": episodes,
            "sources": payload.get("sources") or [],
            "downloads": downloads,
            "links": links,
            "scraped_at": str(row.get("scraped_at")) if row.get("scraped_at") else None,
            "updated_at": str(row.get("updated_at")) if row.get("updated_at") else None,
            "saved_at": str(row.get("scraped_at")) if row.get("scraped_at") else None,
        }

    async def search_scrapes(
        self,
        query: str = "",
        category: str = "all",
        status: str = "saved",
        limit: int = 24,
        offset: int = 0,
    ) -> dict[str, Any]:
        """Search stored scrapes with optional status/category filtering."""
        limit = max(0, limit)
        offset = max(0, offset)

        where: list[str] = []
        args: list[Any] = []
        if status and status != "all":
            where.append("status = %s")
            args.append(status)
        tokens = [
            token
            for token in (_clean_token(part) for part in query.split())
            if token
        ][:4]
        for token in tokens:
            where.append("title LIKE %s")
            args.append(f"%{token}%")
        if category and category != "all":
            where.append("category = %s")
            args.append(category)

        clause = f" WHERE {' AND '.join(where)}" if where else ""

        count_query = f"SELECT COUNT(*) AS c FROM {SCRAPES_TABLE}{clause}"
        page_query = (
            f"SELECT page_url, slug, title, status, error, scraped_at, updated_at "
            f"FROM {SCRAPES_TABLE}{clause}"
            f" ORDER BY updated_at DESC LIMIT %s OFFSET %s"
        )
        page_args = [*args, limit, offset]

        async with self.pool.acquire() as conn:
            async with conn.cursor(DictCursor) as cur:
                await cur.execute(count_query, tuple(args))
                total_row = await cur.fetchone()
                total = int(total_row["c"]) if total_row else 0
                await cur.execute(page_query, tuple(page_args))
                rows = await cur.fetchall() or []

        results = []
        for row in rows:
            results.append({
                "page_url": row.get("page_url"),
                "slug": row.get("slug"),
                "title": row.get("title"),
                "status": row.get("status"),
                "error": row.get("error"),
                "scraped_at": str(row.get("scraped_at")) if row.get("scraped_at") else None,
                "updated_at": str(row.get("updated_at")) if row.get("updated_at") else None,
                "saved_at": str(row.get("scraped_at")) if row.get("scraped_at") else None,
            })
        return {
            "query": query,
            "category": category,
            "status": status,
            "results": results,
            "total": total,
            "limit": limit,
            "offset": offset,
        }


    async def stats(self) -> dict[str, Any]:
        """Row counts per table, plus a lightweight connectivity check."""
        counts: dict[str, int] = {}
        latest: dict[str, Any] = {}
        async with self.pool.acquire() as conn:
            async with conn.cursor(DictCursor) as cur:
                for table, key in (
                    (TITLES_TABLE, "titles"),
                    (SCRAPES_TABLE, "pages"),
                    (LINKS_TABLE, "links"),
                ):
                    await cur.execute(f"SELECT COUNT(*) AS c FROM {table}")
                    row = await cur.fetchone()
                    counts[key] = int(row["c"]) if row else 0
                await cur.execute(
                    f"SELECT title, page_url, updated_at FROM {SCRAPES_TABLE} "
                    f"ORDER BY updated_at DESC LIMIT 1"
                )
                row = await cur.fetchone()
                if row:
                    latest = dict(row)
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
                    "updated_at": _now(),
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
                "updated_at": _now(),
            }
        )

    return list(rows.values())


_store: MySQLStore | None = None


async def sync_catalog(force: bool = False) -> dict[str, Any]:
    """Load the local catalogue index and push every title to MySQL."""
    store = await get_store()
    if store is None:
        raise ScraperError(
            "MySQL is not configured: set MYSQL_HOST, MYSQL_USER, "
            "MYSQL_PASSWORD and MYSQL_DATABASE in .env"
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
    logger.info("Synced {} catalogue titles to MySQL", pushed)
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
        store = await get_store()
        if store is None:
            print("MySQL is not configured: set MYSQL_HOST and MYSQL_DATABASE in .env")
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
