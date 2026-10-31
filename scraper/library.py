"""Local SQLite library for saved MovieLinkBD titles and stream links.

The scraper can run completely locally: the whole catalogue is indexed in
memory (see :mod:`scraper.catalog`), and this module persists every scraped
title, episode, source and download link to a single SQLite file.

Default location: ``<repo>/data/library.db``. Override with ``MLBD_DB_PATH``.
"""

from __future__ import annotations

import json
import os
import sqlite3
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Literal

from .catalog import CatalogItem
from .models import ScrapeResult

DB_ENV = "MLBD_DB_PATH"
_DEFAULT_DB = Path(__file__).resolve().parent.parent / "data" / "library.db"

LibraryStatus = Literal["all", "saved", "pending", "failed", "empty"]

# A title whose page yielded downloads but no playable stream is not useful
# for the stream library, so it is stored as ``empty`` and retried later.
EMPTY_MESSAGE = "No stream links found"

_SCHEMA = """
CREATE TABLE IF NOT EXISTS titles (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    key            TEXT NOT NULL UNIQUE,
    slug           TEXT NOT NULL,
    type           TEXT NOT NULL,
    category       TEXT NOT NULL,
    title          TEXT NOT NULL,
    year           TEXT,
    poster         TEXT,
    adult          INTEGER NOT NULL DEFAULT 0,
    page_url       TEXT,
    content_type   TEXT,
    completed      INTEGER,
    episode_count  INTEGER NOT NULL DEFAULT 0,
    source_count   INTEGER NOT NULL DEFAULT 0,
    download_count INTEGER NOT NULL DEFAULT 0,
    status         TEXT NOT NULL DEFAULT 'pending',
    error          TEXT,
    scraped_at     TEXT,
    updated_at     TEXT NOT NULL,
    raw_json       TEXT
);

CREATE INDEX IF NOT EXISTS idx_titles_status   ON titles(status);
CREATE INDEX IF NOT EXISTS idx_titles_category ON titles(category);
CREATE INDEX IF NOT EXISTS idx_titles_title    ON titles(title COLLATE NOCASE);

CREATE TABLE IF NOT EXISTS episodes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    title_id    INTEGER NOT NULL REFERENCES titles(id) ON DELETE CASCADE,
    site_id     TEXT NOT NULL,
    label       TEXT NOT NULL,
    number      INTEGER,
    season      INTEGER,
    range_start INTEGER,
    range_end   INTEGER,
    kind        TEXT,
    UNIQUE(title_id, site_id)
);

CREATE INDEX IF NOT EXISTS idx_episodes_title ON episodes(title_id);

CREATE TABLE IF NOT EXISTS sources (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    episode_id      INTEGER NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
    source_id       TEXT,
    provider        TEXT,
    quality         TEXT,
    quality_label   TEXT,
    audio           TEXT,
    audio_languages TEXT,
    type            TEXT,
    name            TEXT,
    codec           TEXT,
    hevc            INTEGER,
    is_best         INTEGER NOT NULL DEFAULT 0,
    stream_url      TEXT NOT NULL,
    download_url    TEXT,
    size_bytes      INTEGER,
    content_type    TEXT,
    UNIQUE(episode_id, stream_url)
);

CREATE INDEX IF NOT EXISTS idx_sources_episode ON sources(episode_id);
CREATE INDEX IF NOT EXISTS idx_sources_url     ON sources(stream_url);

CREATE TABLE IF NOT EXISTS downloads (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title_id      INTEGER NOT NULL REFERENCES titles(id) ON DELETE CASCADE,
    episode_label TEXT NOT NULL DEFAULT '',
    label         TEXT NOT NULL,
    quality       TEXT,
    size          TEXT,
    size_bytes    INTEGER,
    url           TEXT NOT NULL,
    type          TEXT,
    audio         TEXT,
    UNIQUE(title_id, url, episode_label)
);

CREATE INDEX IF NOT EXISTS idx_downloads_title ON downloads(title_id);
"""


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def db_path() -> Path:
    """Return the configured SQLite path, creating its parent directory."""
    override = os.environ.get(DB_ENV)
    path = Path(override).expanduser() if override else _DEFAULT_DB
    path.parent.mkdir(parents=True, exist_ok=True)
    return path


def _connect() -> sqlite3.Connection:
    conn = sqlite3.connect(db_path(), timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("PRAGMA busy_timeout=30000")
    return conn


def init_db() -> None:
    """Create the library schema if it does not exist."""
    conn = _connect()
    try:
        conn.executescript(_SCHEMA)
        # Older rows were marked saved even when the page exposed no stream.
        conn.execute(
            """
            UPDATE titles
               SET status = 'empty',
                   error = COALESCE(error, ?)
             WHERE status = 'saved' AND source_count = 0
            """,
            (EMPTY_MESSAGE,),
        )
        conn.commit()
    finally:
        conn.close()


def title_key(item: CatalogItem) -> str:
    """Stable key for a catalogue title: ``type:slug``."""
    return f"{item.type}:{item.slug}"


def _title_values(item: CatalogItem) -> tuple:
    return (
        title_key(item),
        item.slug,
        item.type,
        item.category,
        item.title,
        item.year,
        item.poster,
        int(bool(item.adult)),
    )


def seed_titles(items: Iterable[CatalogItem]) -> int:
    """Insert catalogue titles as ``pending`` without touching saved rows."""
    rows = [_title_values(item) + (_now(),) for item in items]
    if not rows:
        return 0

    conn = _connect()
    try:
        conn.executemany(
            """
            INSERT INTO titles
                (key, slug, type, category, title, year, poster, adult,
                 status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ON CONFLICT(key) DO UPDATE SET
                slug=excluded.slug,
                type=excluded.type,
                category=excluded.category,
                title=excluded.title,
                year=excluded.year,
                poster=excluded.poster,
                adult=excluded.adult,
                updated_at=excluded.updated_at
            """,
            rows,
        )
        conn.commit()
        return len(rows)
    finally:
        conn.close()


def saved_keys() -> set[str]:
    """Return the keys of titles that already have saved data."""
    conn = _connect()
    try:
        return {
            row["key"]
            for row in conn.execute(
                "SELECT key FROM titles WHERE status = 'saved'"
            )
        }
    finally:
        conn.close()


def attempted_keys() -> set[str]:
    """Return keys that were already scraped, including empty results."""
    conn = _connect()
    try:
        return {
            row["key"]
            for row in conn.execute(
                "SELECT key FROM titles WHERE status IN ('saved', 'empty')"
            )
        }
    finally:
        conn.close()


def save_result(
    item: CatalogItem,
    result: ScrapeResult,
    raw: dict[str, Any] | None = None,
    require_streams: bool = True,
) -> tuple[int, bool]:
    """Persist a scrape result, replacing that title's previous rows.

    Returns ``(title_id, has_streams)``. When ``require_streams`` is true and
    the page exposed no playable source, the row is stored as ``empty`` so the
    next sync retries it instead of treating it as saved.
    """
    key = title_key(item)
    now = _now()
    scraped_at = result.scraped_at.isoformat() if result.scraped_at else now
    raw_json = json.dumps(raw, ensure_ascii=False) if raw is not None else None
    total_sources = sum(len(episode.sources) for episode in result.episodes)
    has_streams = total_sources > 0
    status = "saved" if has_streams or not require_streams else "empty"
    error = None if status == "saved" else EMPTY_MESSAGE

    conn = _connect()
    try:
        conn.execute("BEGIN")
        conn.execute(
            """
            INSERT INTO titles
                (key, slug, type, category, title, year, poster, adult,
                 page_url, content_type, completed, status, error,
                 scraped_at, updated_at, raw_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET
                slug=excluded.slug,
                type=excluded.type,
                category=excluded.category,
                title=excluded.title,
                year=excluded.year,
                poster=excluded.poster,
                adult=excluded.adult,
                page_url=excluded.page_url,
                content_type=excluded.content_type,
                completed=excluded.completed,
                status=CASE
                    WHEN excluded.status = 'empty'
                         AND titles.status = 'saved'
                         AND titles.source_count > 0
                    THEN 'saved'
                    ELSE excluded.status
                END,
                error=CASE
                    WHEN excluded.status = 'empty'
                         AND titles.status = 'saved'
                         AND titles.source_count > 0
                    THEN titles.error
                    ELSE excluded.error
                END,
                scraped_at=excluded.scraped_at,
                updated_at=excluded.updated_at,
                raw_json=excluded.raw_json
            """,
            (
                *_title_values(item),
                result.page_url,
                result.content_type,
                None if result.completed is None else int(result.completed),
                status,
                error,
                scraped_at,
                now,
                raw_json,
            ),
        )

        row = conn.execute(
            "SELECT id FROM titles WHERE key = ?", (key,)
        ).fetchone()
        if row is None:  # pragma: no cover - defensive
            raise RuntimeError(f"Could not insert library title {key}")
        title_id = int(row["id"])

        conn.execute("DELETE FROM episodes WHERE title_id = ?", (title_id,))
        conn.execute("DELETE FROM downloads WHERE title_id = ?", (title_id,))

        seen_episode_ids: set[str] = set()
        for index, episode in enumerate(result.episodes):
            site_id = episode.id or episode.label or f"episode-{index + 1}"
            if site_id in seen_episode_ids:
                site_id = f"{site_id}-{index + 1}"
            seen_episode_ids.add(site_id)

            cursor = conn.execute(
                """
                INSERT INTO episodes
                    (title_id, site_id, label, number, season,
                     range_start, range_end, kind)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    title_id,
                    site_id,
                    episode.label,
                    episode.number,
                    episode.season,
                    episode.range_start,
                    episode.range_end,
                    episode.kind,
                ),
            )
            episode_pk = int(cursor.lastrowid)

            for source in episode.sources:
                conn.execute(
                    """
                    INSERT OR IGNORE INTO sources
                        (episode_id, source_id, provider, quality,
                         quality_label, audio, audio_languages, type, name,
                         codec, hevc, is_best, stream_url, download_url,
                         size_bytes, content_type)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        episode_pk,
                        source.id,
                        source.provider,
                        source.quality,
                        source.quality_label,
                        source.audio,
                        json.dumps(source.audio_languages, ensure_ascii=False),
                        source.type,
                        source.name,
                        source.codec,
                        None if source.hevc is None else int(source.hevc),
                        int(source.is_best),
                        source.stream_url,
                        source.download_url,
                        source.size_bytes,
                        source.content_type,
                    ),
                )

        for download in result.downloads:
            conn.execute(
                """
                INSERT OR IGNORE INTO downloads
                    (title_id, episode_label, label, quality, size,
                     size_bytes, url, type, audio)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    title_id,
                    download.episode or "",
                    download.label,
                    download.quality,
                    download.size,
                    download.size_bytes,
                    download.url,
                    download.type,
                    download.audio,
                ),
            )

        episode_count = conn.execute(
            "SELECT COUNT(*) FROM episodes WHERE title_id = ?", (title_id,)
        ).fetchone()[0]
        source_count = conn.execute(
            """
            SELECT COUNT(*) FROM sources s
            JOIN episodes e ON e.id = s.episode_id
            WHERE e.title_id = ?
            """,
            (title_id,),
        ).fetchone()[0]
        download_count = conn.execute(
            "SELECT COUNT(*) FROM downloads WHERE title_id = ?", (title_id,)
        ).fetchone()[0]

        conn.execute(
            """
            UPDATE titles
               SET episode_count = ?, source_count = ?, download_count = ?
             WHERE id = ?
            """,
            (episode_count, source_count, download_count, title_id),
        )
        conn.commit()
        return title_id, has_streams
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def mark_failed(item: CatalogItem, message: str) -> None:
    """Record a failure without discarding an existing saved result."""
    now = _now()
    conn = _connect()
    try:
        conn.execute(
            """
            INSERT INTO titles
                (key, slug, type, category, title, year, poster, adult,
                 status, error, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?, ?)
            ON CONFLICT(key) DO UPDATE SET
                error=excluded.error,
                status=CASE WHEN titles.status = 'saved'
                            THEN 'saved' ELSE 'failed' END,
                updated_at=excluded.updated_at
            """,
            (*_title_values(item), message, now),
        )
        conn.commit()
    finally:
        conn.close()


def _row_to_item(row: sqlite3.Row) -> dict[str, Any]:
    return {
        "id": row["id"],
        "key": row["key"],
        "slug": row["slug"],
        "type": row["type"],
        "category": row["category"],
        "title": row["title"],
        "year": row["year"],
        "poster": row["poster"],
        "adult": bool(row["adult"]),
        "status": row["status"],
        "page_url": row["page_url"],
        "content_type": row["content_type"],
        "completed": (
            None if row["completed"] is None else bool(row["completed"])
        ),
        "episode_count": row["episode_count"],
        "source_count": row["source_count"],
        "download_count": row["download_count"],
        "error": row["error"],
        "scraped_at": row["scraped_at"],
        "updated_at": row["updated_at"],
    }


def search_library(
    query: str = "",
    category: str = "all",
    status: str = "saved",
    limit: int = 24,
    offset: int = 0,
) -> dict[str, Any]:
    """Search titles stored in the local library."""
    where: list[str] = []
    params: list[Any] = []

    if status and status != "all":
        where.append("status = ?")
        params.append(status)
    if category and category != "all":
        where.append("category = ?")
        params.append(category)
    if query.strip():
        like = f"%{query.strip().lower()}%"
        where.append("(lower(title) LIKE ? OR lower(slug) LIKE ?)")
        params.extend([like, like])

    clause = f" WHERE {' AND '.join(where)}" if where else ""
    conn = _connect()
    try:
        total = int(
            conn.execute(
                f"SELECT COUNT(*) FROM titles{clause}", params
            ).fetchone()[0]
        )
        rows = conn.execute(
            f"""
            SELECT * FROM titles{clause}
            ORDER BY CASE status
                        WHEN 'saved' THEN 0
                        WHEN 'failed' THEN 1
                        ELSE 2
                     END,
                     COALESCE(scraped_at, updated_at) DESC,
                     title COLLATE NOCASE
            LIMIT ? OFFSET ?
            """,
            [*params, max(0, limit), max(0, offset)],
        ).fetchall()
    finally:
        conn.close()

    return {
        "query": query,
        "category": category,
        "status": status,
        "results": [_row_to_item(row) for row in rows],
        "total": total,
        "limit": limit,
        "offset": max(0, offset),
    }


def get_title(title_id: int) -> dict[str, Any] | None:
    """Return one library title with all episodes, sources and downloads."""
    conn = _connect()
    try:
        row = conn.execute(
            "SELECT * FROM titles WHERE id = ?", (title_id,)
        ).fetchone()
        if row is None:
            return None

        episode_rows = conn.execute(
            """
            SELECT * FROM episodes
             WHERE title_id = ?
             ORDER BY COALESCE(season, 0), COALESCE(number, 0), id
            """,
            (title_id,),
        ).fetchall()

        episodes: list[dict[str, Any]] = []
        flat_sources: dict[str, dict[str, Any]] = {}

        for episode_row in episode_rows:
            source_rows = conn.execute(
                "SELECT * FROM sources WHERE episode_id = ? ORDER BY id",
                (episode_row["id"],),
            ).fetchall()
            sources: list[dict[str, Any]] = []
            for source_row in source_rows:
                source = {
                    "id": source_row["source_id"],
                    "provider": source_row["provider"],
                    "quality": source_row["quality"],
                    "quality_label": source_row["quality_label"],
                    "audio": source_row["audio"],
                    "audio_languages": json.loads(
                        source_row["audio_languages"] or "[]"
                    ),
                    "type": source_row["type"] or "auto",
                    "name": source_row["name"],
                    "codec": source_row["codec"],
                    "hevc": (
                        None
                        if source_row["hevc"] is None
                        else bool(source_row["hevc"])
                    ),
                    "is_best": bool(source_row["is_best"]),
                    "stream_url": source_row["stream_url"],
                    "download_url": source_row["download_url"],
                    "size_bytes": source_row["size_bytes"],
                    "content_type": source_row["content_type"],
                }
                sources.append(source)

                master = source["stream_url"]
                flat = flat_sources.get(master)
                variant = {
                    "quality": source["quality"] or "auto",
                    "bandwidth": None,
                    "url": master,
                }
                if flat is None:
                    flat_sources[master] = {
                        "server_name": source["provider"],
                        "type": source["type"],
                        "master_url": master,
                        "variants": [variant],
                        "size_bytes": source["size_bytes"],
                        "content_type": source["content_type"],
                    }
                elif variant["quality"] not in {
                    item["quality"] for item in flat["variants"]
                }:
                    flat["variants"].append(variant)

            episodes.append(
                {
                    "id": episode_row["site_id"],
                    "label": episode_row["label"],
                    "number": episode_row["number"],
                    "season": episode_row["season"],
                    "range_start": episode_row["range_start"],
                    "range_end": episode_row["range_end"],
                    "kind": episode_row["kind"],
                    "sources": sources,
                }
            )

        download_rows = conn.execute(
            "SELECT * FROM downloads WHERE title_id = ? ORDER BY id",
            (title_id,),
        ).fetchall()
        downloads = [
            {
                "label": download_row["label"],
                "quality": download_row["quality"],
                "size": download_row["size"],
                "size_bytes": download_row["size_bytes"],
                "episode": download_row["episode_label"] or None,
                "url": download_row["url"],
                "type": download_row["type"],
                "audio": download_row["audio"],
            }
            for download_row in download_rows
        ]

        data = _row_to_item(row)
        data.update(
            {
                "page_url": row["page_url"] or "",
                "episodes": episodes,
                "sources": list(flat_sources.values()),
                "downloads": downloads,
                "scraped_at": row["scraped_at"] or row["updated_at"],
                "saved_at": row["scraped_at"] or row["updated_at"],
            }
        )
        return data
    finally:
        conn.close()


def library_stats() -> dict[str, Any]:
    """Return row counts, file size and the database path."""
    conn = _connect()
    try:
        counts = {
            row["status"]: int(row["count"])
            for row in conn.execute(
                "SELECT status, COUNT(*) AS count FROM titles GROUP BY status"
            )
        }
        episodes = int(
            conn.execute("SELECT COUNT(*) FROM episodes").fetchone()[0]
        )
        sources = int(
            conn.execute("SELECT COUNT(*) FROM sources").fetchone()[0]
        )
        downloads = int(
            conn.execute("SELECT COUNT(*) FROM downloads").fetchone()[0]
        )
    finally:
        conn.close()

    path = db_path()
    size_bytes = 0
    for candidate in (
        path,
        path.with_name(path.name + "-wal"),
        path.with_name(path.name + "-shm"),
    ):
        try:
            size_bytes += candidate.stat().st_size
        except OSError:
            pass

    return {
        "db_path": str(path),
        "titles": {
            "total": sum(counts.values()),
            "saved": counts.get("saved", 0),
            "pending": counts.get("pending", 0),
            "failed": counts.get("failed", 0),
            "empty": counts.get("empty", 0),
        },
        "episodes": episodes,
        "sources": sources,
        "downloads": downloads,
        "size_bytes": size_bytes,
    }
