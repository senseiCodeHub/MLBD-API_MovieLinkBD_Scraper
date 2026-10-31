"""Library endpoints and background catalogue sync using MySQL as primary store."""

from __future__ import annotations

import asyncio
import time
from datetime import datetime, timezone
from typing import Any, Literal, Optional

from fastapi import APIRouter, HTTPException, Query
from loguru import logger
from pydantic import BaseModel, Field

from scraper import store as dbstore
from scraper.catalog import CatalogItem, catalog_items
from scraper.exceptions import ScraperError
from scraper.scraper import scrape_streams
from urllib.parse import quote, unquote

router = APIRouter(prefix="/library", tags=["library"])

MAX_CONSECUTIVE_FAILURES = 10


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _ok(data: Any) -> dict[str, Any]:
    return {"ok": True, "data": data, "error": None, "error_type": None}


class SyncRequest(BaseModel):
    """Options for a one-click catalogue sync (saved to MySQL)."""

    category: Literal["all", "movie", "series", "drama", "anime", "adult"] = "all"
    include_adult: bool = Field(
        True, description="Include download18plus/adult titles"
    )
    force: bool = Field(
        False, description="Re-scrape titles that are already saved"
    )
    probe: bool = Field(
        False, description="HEAD each stream URL for size (slower)"
    )
    concurrency: int = Field(
        3, ge=1, le=8, description="Titles scraped in parallel"
    )
    limit: Optional[int] = Field(
        None, ge=1, description="Stop after this many titles (for testing)"
    )
    require_streams: bool = Field(
        True,
        description=(
            "Only mark a title saved when it exposes at least one stream. "
            "Pages with downloads but no playable source become 'empty'."
        ),
    )
    retry_empty: bool = Field(
        False,
        description="Re-scrape titles that previously had no stream links",
    )


class _SyncState:
    def __init__(self) -> None:
        self.running = False
        self.cancelling = False
        self.total = 0
        self.processed = 0
        self.saved = 0
        self.failed = 0
        self.empty = 0
        self.skipped = 0
        self.current: str | None = None
        self.started_at: str | None = None
        self.finished_at: str | None = None
        self.message: str | None = None
        self._started_monotonic = 0.0

    def reset(self) -> None:
        self.running = True
        self.cancelling = False
        self.total = 0
        self.processed = 0
        self.saved = 0
        self.failed = 0
        self.empty = 0
        self.skipped = 0
        self.current = None
        self.started_at = _now()
        self.finished_at = None
        self.message = "Loading catalogue…"
        self._started_monotonic = time.monotonic()

    def snapshot(self) -> dict[str, Any]:
        elapsed = (
            max(0.001, time.monotonic() - self._started_monotonic)
            if self.started_at
            else 0.0
        )
        done = max(0, self.processed - self.skipped)
        rate = done / elapsed if elapsed > 0 and done > 0 else 0.0
        remaining = max(0, self.total - self.processed)
        eta = int(remaining / rate) if self.running and rate > 0 else None
        percent = (
            round((self.processed / self.total) * 100, 2)
            if self.total > 0
            else 0.0
        )
        return {
            "running": self.running,
            "cancelling": self.cancelling,
            "total": self.total,
            "processed": self.processed,
            "saved": self.saved,
            "failed": self.failed,
            "empty": self.empty,
            "skipped": self.skipped,
            "percent": percent,
            "current": self.current,
            "eta_seconds": eta,
            "rate_per_minute": round(rate * 60, 2),
            "started_at": self.started_at,
            "finished_at": self.finished_at,
            "message": self.message,
        }


_sync = _SyncState()
_task: asyncio.Task | None = None
_task_lock = asyncio.Lock()


def _title_rows(items: list[CatalogItem]) -> list[dict[str, Any]]:
    """Convert catalogue items to MySQL title rows."""
    rows = []
    for item in items:
        rows.append(
            {
                "slug": (item.slug or "").strip(),
                "title": (item.title or "").strip(),
                "url": item.url,
                "poster": item.poster,
                "kind": item.type,
                "category": item.category,
                "year": item.year,
                "adult": bool(item.adult),
                "quality": item.quality,
                "language": item.language,
                "rating": item.rating,
            }
        )
    return rows


async def _run_sync(req: SyncRequest) -> None:
    consecutive = 0
    aborted = False

    try:
        items = await catalog_items(req.category)
        if not req.include_adult:
            items = [item for item in items if not item.adult]
        if req.limit is not None:
            items = items[: req.limit]

        _sync.message = "Preparing the library…"
        store = await dbstore.get_store()
        if store is None:
            _sync.message = (
                "MySQL is not configured; set MYSQL_HOST, MYSQL_USER, "
                "MYSQL_PASSWORD and MYSQL_DATABASE in .env"
            )
            _sync.running = False
            _sync.finished_at = _now()
            return

        # Seed catalogue titles into MySQL
        rows = _title_rows(items)
        pushed = await store.upsert_titles(rows)
        logger.info("Seeded {} catalogue titles to MySQL", pushed)

        # Determine which titles still need scraping
        if req.force or req.retry_empty:
            queue = list(items)
            _sync.skipped = 0
        else:
            # Match on slug as well as page_url: the catalogue URL (slug form)
            # and the stored canonical URL (mKs_ id) never match, so a URL-only
            # check re-scraped the whole catalogue on every run.
            scraped = await store.get_scraped_keys()
            scraped_slugs = await store.get_scraped_slugs()
            queue = [
                item
                for item in items
                if item.url not in scraped
                and (item.slug or "") not in scraped_slugs
            ]
            _sync.skipped = len(items) - len(queue)

        _sync.total = len(items)
        _sync.processed = _sync.skipped
        _sync.message = (
            f"Scraping {len(queue)} titles"
            if queue
            else "Everything is already saved"
        )

        semaphore = asyncio.Semaphore(req.concurrency)

        async def work(item: CatalogItem) -> None:
            nonlocal consecutive, aborted

            if _sync.cancelling:
                return

            async with semaphore:
                if _sync.cancelling:
                    return

                _sync.current = item.title
                try:
                    result = await scrape_streams(
                        item.url, timeout=30, probe=req.probe
                    )
                    raw = result.model_dump(mode="json")
                    total_sources = sum(
                        len(ep.sources) for ep in result.episodes
                    )
                    has_streams = total_sources > 0
                    consecutive = 0

                    error = None
                    status = "saved" if has_streams or not req.require_streams else "empty"
                    if status == "empty":
                        error = "No stream links found"

                    if has_streams:
                        _sync.saved += 1
                    else:
                        _sync.empty += 1

                    await store.record_scrape_result(
                        page_url=result.page_url,
                        slug=item.slug,
                        title=item.title,
                        has_streams=has_streams,
                        error_message=error,
                        payload=raw,
                    )
                except Exception as exc:  # noqa: BLE001 - one title must not stop the job
                    message = f"{type(exc).__name__}: {exc}"
                    await store.record_scrape_result(
                        page_url=item.url,
                        slug=item.slug,
                        title=item.title,
                        has_streams=False,
                        error_message=message,
                        payload={},
                    )
                    _sync.failed += 1
                    consecutive += 1
                    if consecutive >= MAX_CONSECUTIVE_FAILURES:
                        aborted = True
                        _sync.cancelling = True
                finally:
                    _sync.processed += 1
                    if not _sync.cancelling:
                        _sync.current = None

        await asyncio.gather(*(work(item) for item in queue))

        if aborted:
            _sync.message = (
                f"Stopped after {MAX_CONSECUTIVE_FAILURES} consecutive failures"
            )
        elif _sync.cancelling:
            _sync.message = "Cancelled"
        else:
            _sync.message = "Finished"
    except asyncio.CancelledError:
        _sync.message = "Cancelled"
        raise
    except Exception as exc:  # noqa: BLE001 - surface job-level failures
        _sync.message = f"Sync failed: {exc}"
    finally:
        _sync.running = False
        _sync.cancelling = False
        _sync.finished_at = _now()
        _sync.current = None


@router.get("/stats")
async def library_stats() -> dict[str, Any]:
    """Row counts, database size and the current sync state from MySQL."""
    store = await dbstore.get_store()
    if store is None:
        return _ok(
            {
                "mysql": False,
                "mysql_writable": False,
                "sync": _sync.snapshot(),
                "titles": {"total": 0, "saved": 0, "pending": 0, "failed": 0, "empty": 0},
                "episodes": 0,
                "sources": 0,
                "downloads": 0,
                "size_bytes": 0,
                "tables": {"titles": 0, "pages": 0, "links": 0},
            }
        )
    stats = await store.stats()
    tables = stats.get("tables", {})
    total_titles = int(tables.get("titles", 0))
    total_pages = int(tables.get("pages", 0))
    total_links = int(tables.get("links", 0))
    # Status breakdown from scrapes table
    saved = 0
    empty = 0
    failed = 0
    try:
        status_rows = await store._fetchall(
            "SELECT status, COUNT(*) AS c FROM mlbd_scrapes GROUP BY status"
        )
        for row in status_rows:
            st = str(row["status"])
            cnt = int(row["c"])
            if st == "saved":
                saved = cnt
            elif st == "empty":
                empty = cnt
            elif st == "failed":
                failed = cnt
    except Exception:  # noqa: BLE001
        pass
    pending = max(0, total_titles - saved - empty - failed)
    return _ok(
        {
            **stats,
            "mysql": True,
            "mysql_writable": True,
            "sync": _sync.snapshot(),
            "titles": {
                "total": total_titles,
                "saved": saved,
                "pending": pending,
                "failed": failed,
                "empty": empty,
            },
            "episodes": total_links,
            "sources": total_links,
            "downloads": total_links,
            "size_bytes": 0,
        }
    )


@router.get("/sync/status")
async def sync_status() -> dict[str, Any]:
    """Progress of the background catalogue sync."""
    return _ok(_sync.snapshot())


@router.post("/sync")
async def start_sync(req: SyncRequest) -> dict[str, Any]:
    """Start saving every catalogue title directly to MySQL."""
    global _task

    async with _task_lock:
        if _task is not None and not _task.done():
            raise HTTPException(
                status_code=409, detail="A library sync is already running"
            )
        _sync.reset()
        _task = asyncio.create_task(_run_sync(req))

    return _ok(_sync.snapshot())


@router.post("/sync/cancel")
async def cancel_sync() -> dict[str, Any]:
    """Ask the running sync to stop after the current titles finish."""
    if _task is not None and not _task.done():
        _sync.cancelling = True
        _sync.message = "Cancelling…"
    return _ok(_sync.snapshot())


@router.get("")
async def search_library(
    q: str = Query("", max_length=120),
    category: Literal[
        "all", "movie", "series", "drama", "anime", "adult"
    ] = Query("all"),
    status: Literal["all", "saved", "pending", "failed", "empty"] = Query(
        "saved"
    ),
    limit: int = Query(24, ge=1, le=100),
    offset: int = Query(0, ge=0),
) -> dict[str, Any]:
    """Search titles already stored in MySQL."""
    store = await dbstore.get_store()
    if store is None:
        return _ok(
            {
                "query": q,
                "category": category,
                "status": status,
                "results": [],
                "total": 0,
                "limit": limit,
                "offset": offset,
            }
        )
    data = await store.search_scrapes(
        query=q,
        category=category,
        status=status,
        limit=limit,
        offset=offset,
    )
    return _ok(data)


@router.get("/{page_url:path}")
async def library_title(page_url: str) -> dict[str, Any]:
    """Return one saved scrape with all episodes, sources and downloads."""
    # The UI puts the whole URL in the path (``/library/https%3A%2F%2F…``), so
    # the captured segment still arrives percent-encoded depending on the server.
    if "%" in page_url:
        page_url = unquote(page_url)
    store = await dbstore.get_store()
    if store is None:
        raise HTTPException(status_code=503, detail="MySQL is not configured")
    data = await store.get_scrape_detail(page_url)
    if data is None:
        raise HTTPException(status_code=404, detail="Title not found")
    return _ok(data)
