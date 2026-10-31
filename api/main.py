"""FastAPI backend wrapping the MovieLinkBD scraper core."""

from __future__ import annotations

import asyncio
import os
import re
from contextlib import asynccontextmanager
from typing import Literal, Optional

try:  # optional: .env is handy for the local service-role key
    from dotenv import load_dotenv

    load_dotenv()
except ImportError:  # pragma: no cover
    pass

from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from loguru import logger
from pydantic import BaseModel, Field

from scraper import store as dbstore
from scraper.catalog import (
    CatalogItem,
    CatalogResults,
    catalog_stats,
    ensure_catalog,
    search_catalog,
)
from scraper.diff import diff_scrapes
from scraper.exceptions import (
    BrowserError,
    CloudflareBlocked,
    NoStreamFound,
    ScraperError,
)
from scraper.scraper import scrape_streams
from scraper.search import search_content

from api.library import router as library_router

SCRAPE_TIMEOUT = 150
SEARCH_TIMEOUT = 120
# How long a stored scrape counts as fresh enough to serve when live fails.
SCRAPE_CACHE_TTL_SECONDS = int(os.getenv("MLBD_SCRAPE_CACHE_SECONDS", "21600"))

_TYPE_TO_CATEGORY = {
    "movie": "movie",
    "series": "series",
    "drama": "drama",
    "anime": "anime",
    "download18plus": "adult",
    "adult": "adult",
}


async def _search_from_db(
    query: str,
    category: str,
    limit: int,
    offset: int,
    reason: str | None = None,
) -> CatalogResults:
    """Search the MySQL mirror of the catalogue index."""
    store = await dbstore.get_store()
    if store is None:
        raise ScraperError(
            reason
            or "MySQL is not configured (set MYSQL_HOST and MYSQL_DATABASE), "
            "and the live catalogue is unreachable"
        )
    items, total = await asyncio.wait_for(
        store.search_titles(
            query, category=category, limit=limit, offset=offset
        ),
        timeout=30,
    )
    if reason:
        logger.warning("Live catalogue unavailable ({}); served from Supabase", reason)
    return CatalogResults(
        query=query,
        category=category,  # type: ignore[arg-type]
        results=items,
        total=total,
        limit=limit,
        offset=offset,
        source="db",
        catalog_count=None,
    )


async def _store_scrape(payload: dict, requested_url: str) -> None:
    """Persist one scrape snapshot; failures never break the response."""
    if not dbstore.writable():
        # MySQL is not writable (no configured connection): skip mirroring.
        return
    store = await dbstore.get_store()
    if store is None:
        return
    try:
        saved = await asyncio.wait_for(
            store.save_scrape(payload, requested_url=requested_url), timeout=60
        )
        logger.info(
            "Stored scrape for {} ({})", payload.get("page_url"), saved
        )
    except (ScraperError, asyncio.TimeoutError) as exc:
        logger.warning("Could not store scrape in MySQL: {}", exc)


async def _cached_scrape(
    page_url: str, reason: str | None = None
) -> dict | None:
    """Return a fresh stored snapshot for ``page_url``, if any."""
    store = await dbstore.get_store()
    if store is None:
        return None
    try:
        cached = await asyncio.wait_for(
            store.cached_scrape(page_url, SCRAPE_CACHE_TTL_SECONDS), timeout=20
        )
    except (ScraperError, asyncio.TimeoutError) as exc:
        logger.warning("Could not read cached scrape: {}", exc)
        return None
    if cached is None:
        return None
    payload, age = cached
    if reason:
        logger.warning("Live scrape failed ({}); serving stored copy", reason)
    return {**payload, "cached": True, "cache_age_seconds": age}


async def _warm_catalog() -> None:
    try:
        count = await ensure_catalog()
        logger.info("Catalog warmed: {} titles", count)
    except Exception as exc:  # noqa: BLE001
        logger.warning("Catalog warm-up failed: {}", exc)


@asynccontextmanager
async def lifespan(app: FastAPI):
    try:
        store = await dbstore.get_store()
        if store is None:
            logger.warning("MySQL not configured — set MYSQL_HOST / MYSQL_DATABASE")
        else:
            await store.stats()
            logger.info("MySQL library database ready")
    except Exception as exc:  # noqa: BLE001
        logger.warning("Could not initialise MySQL library: {}", exc)

    warm_task = asyncio.create_task(_warm_catalog())
    try:
        yield
    finally:
        warm_task.cancel()


app = FastAPI(
    title="MovieLinkBD Scraper API",
    version="2.0.0",
    lifespan=lifespan,
)

app.include_router(library_router)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


class ScrapeRequest(BaseModel):
    url: str
    probe: bool = Field(
        True, description="HEAD each media URL for size and content-type"
    )


class ScrapeResponse(BaseModel):
    ok: bool
    data: Optional[dict] = None
    error: Optional[str] = None
    error_type: Optional[str] = None


class CompareRequest(BaseModel):
    url: str
    probe: bool = Field(
        True, description="HEAD each media URL for size and content-type"
    )


class CompareResponse(BaseModel):
    ok: bool
    data: Optional[dict] = None
    error: Optional[str] = None
    error_type: Optional[str] = None


class SearchResponse(BaseModel):
    ok: bool
    data: Optional[CatalogResults] = None
    error: Optional[str] = None
    error_type: Optional[str] = None


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/scrape", response_model=ScrapeResponse)
async def scrape(req: ScrapeRequest) -> ScrapeResponse:
    """Scrape every episode's stream and download links from a detail page."""
    try:
        try:
            result = await asyncio.wait_for(
                scrape_streams(req.url, timeout=30, probe=req.probe),
                timeout=SCRAPE_TIMEOUT,
            )
        except (
            CloudflareBlocked,
            NoStreamFound,
            BrowserError,
            ScraperError,
            asyncio.TimeoutError,
        ) as exc:
            cached = await _cached_scrape(req.url, reason=str(exc))
            if cached is not None:
                return ScrapeResponse(ok=True, data=cached)
            raise
        payload = result.model_dump(mode="json")
        await _store_scrape(payload, req.url)
        return ScrapeResponse(ok=True, data=payload)
    except CloudflareBlocked as exc:
        return ScrapeResponse(
            ok=False,
            error=str(exc),
            error_type="CloudflareBlocked",
        )
    except NoStreamFound as exc:
        return ScrapeResponse(
            ok=False,
            error=str(exc),
            error_type="NoStreamFound",
        )
    except BrowserError as exc:
        return ScrapeResponse(
            ok=False,
            error=str(exc),
            error_type="BrowserError",
        )
    except asyncio.TimeoutError:
        return ScrapeResponse(
            ok=False,
            error="Scrape timed out",
            error_type="Timeout",
        )
    except ScraperError as exc:
        return ScrapeResponse(
            ok=False,
            error=str(exc),
            error_type="ScraperError",
        )


@app.get("/scrape", response_model=ScrapeResponse)
async def scrape_get(
    url: str = Query(..., description="Detail page URL"),
    probe: bool = Query(True, description="Probe media URLs for size"),
) -> ScrapeResponse:
    """GET variant for simple browser testing."""
    return await scrape(ScrapeRequest(url=url, probe=probe))


@app.post("/compare", response_model=CompareResponse)
async def compare(req: CompareRequest) -> CompareResponse:
    """Compare a fresh live scrape against the stored MySQL snapshot.

    Returns a structural diff (added/removed/changed episodes, sources and
    downloads) plus both payloads so the UI can show what the server has
    updated since the title was last saved.
    """
    try:
        live = await asyncio.wait_for(
            scrape_streams(req.url, timeout=30, probe=req.probe),
            timeout=SCRAPE_TIMEOUT,
        )
    except (
        CloudflareBlocked,
        NoStreamFound,
        BrowserError,
        ScraperError,
        asyncio.TimeoutError,
    ) as exc:
        return CompareResponse(
            ok=False,
            error=str(exc),
            error_type=type(exc).__name__,
        )
    live_payload = live.model_dump(mode="json")

    stored: dict[str, Any] | None = None
    if dbstore.writable():
        store = await dbstore.get_store()
        if store is not None:
            try:
                detail = await asyncio.wait_for(
                    store.get_scrape_detail(req.url), timeout=20
                )
            except (ScraperError, asyncio.TimeoutError) as exc:
                logger.warning("Could not read stored scrape: {}", exc)
                detail = None
            if detail is not None:
                stored = detail.get("payload") or {}

    difference = diff_scrapes(stored, live_payload, req.url)

    return CompareResponse(
        ok=True,
        data={
            "page_url": req.url,
            "stored": stored,
            "live": live_payload,
            "diff": difference.to_dict(),
        },
    )


@app.get("/search", response_model=SearchResponse)
async def search(
    q: str = Query(
        "",
        max_length=120,
        description="Title to search for; empty browses the catalogue",
    ),
    category: Literal[
        "all", "movie", "series", "drama", "anime", "adult"
    ] = Query("all", description="Category filter"),
    limit: int = Query(24, ge=1, le=100),
    offset: int = Query(0, ge=0),
    scope: Literal["catalog", "site", "db"] = Query(
        "catalog",
        description=(
            "catalog = whole server index; site = the site's 25-result search; "
            "db = the Supabase mirror"
        ),
    ),
) -> SearchResponse:
    """Search the whole MovieLinkBD catalogue (or the site's own search page)."""
    query = q.strip()

    try:
        if scope == "db":
            data = await _search_from_db(query, category, limit, offset)
        elif scope == "site":
            if not query:
                raise ScraperError("Search query is empty")
            site = await asyncio.wait_for(
                search_content(query, limit=limit), timeout=30
            )
            results: list[CatalogItem] = []
            for item in site.results:
                kind = (item.type or "").strip().lower()
                if kind not in _TYPE_TO_CATEGORY:
                    path = item.url.rstrip("/").split("/")[-2:-1]
                    kind = path[0].lower() if path else "movie"
                if kind not in _TYPE_TO_CATEGORY:
                    kind = "movie"
                year_match = re.search(r"\((\d{4})\)", item.title)
                results.append(
                    CatalogItem(
                        title=item.title,
                        url=item.url,
                        slug=item.url.rstrip("/").split("/")[-1],
                        poster=item.poster,
                        type=kind,
                        category=_TYPE_TO_CATEGORY[kind],  # type: ignore[arg-type]
                        year=year_match.group(1) if year_match else None,
                        adult=kind in ("download18plus", "adult"),
                        quality=item.quality,
                        language=item.language,
                        rating=item.rating,
                    )
                )
            data = CatalogResults(
                query=query,
                category="all",
                results=results,
                total=len(results),
                limit=limit,
                offset=0,
                source="site",
                catalog_count=None,
                searched_at=site.searched_at,
            )
        else:
            try:
                data = await asyncio.wait_for(
                    search_catalog(
                        query,
                        category=category,
                        limit=limit,
                        offset=offset,
                    ),
                    timeout=SEARCH_TIMEOUT,
                )
            except (CloudflareBlocked, ScraperError) as exc:
                data = await _search_from_db(
                    query, category, limit, offset, reason=str(exc)
                )
        return SearchResponse(ok=True, data=data)
    except CloudflareBlocked as exc:
        return SearchResponse(
            ok=False,
            error=str(exc),
            error_type="CloudflareBlocked",
        )
    except asyncio.TimeoutError:
        return SearchResponse(
            ok=False,
            error="Search timed out",
            error_type="Timeout",
        )
    except ScraperError as exc:
        return SearchResponse(
            ok=False,
            error=str(exc),
            error_type="ScraperError",
        )


@app.get("/catalog/status")
async def catalog_status() -> dict:
    """Report whether the whole-catalogue index is loaded and how big it is."""
    return await catalog_stats()


@app.post("/catalog/sync")
async def catalog_sync(force: bool = Query(False)) -> dict:
    """Push the local catalogue index into Supabase (titles table)."""
    try:
        return await asyncio.wait_for(
            dbstore.sync_catalog(force=force), timeout=600
        )
    except (ScraperError, asyncio.TimeoutError) as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc


@app.get("/db/status")
async def db_status() -> dict:
    """Report whether MySQL is configured and what the mirror holds."""
    cfg = dbstore._read_config()
    if not cfg["host"] or not cfg["db"]:
        return {
            "configured": False,
            "mysql": False,
            "mysql_writable": False,
            "hint": (
                "Set MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD and "
                "MYSQL_DATABASE in .env to use MySQL storage"
            ),
        }
    try:
        connection = await asyncio.wait_for(dbstore.test_connection(), timeout=10)
    except (ScraperError, asyncio.TimeoutError) as exc:
        return {
            "configured": True,
            "mysql": False,
            "mysql_writable": False,
            "ok": False,
            "error": str(exc),
        }
    if not connection["ok"]:
        return {
            "configured": True,
            "mysql": False,
            "mysql_writable": False,
            "ok": False,
            "error": connection["error"],
        }
    try:
        store = await dbstore.get_store()
        stats = await asyncio.wait_for(store.stats(), timeout=30)
    except (ScraperError, asyncio.TimeoutError) as exc:
        return {
            "configured": True,
            "mysql": True,
            "mysql_writable": True,
            "ok": False,
            "error": str(exc),
        }
    return {
        "configured": True,
        "mysql": True,
        "mysql_writable": True,
        "ok": True,
        **stats,
    }


@app.post("/catalog/refresh")
async def catalog_refresh() -> dict:
    """Force a fresh crawl of the search-suggest shards."""
    try:
        count = await asyncio.wait_for(ensure_catalog(force=True), timeout=SEARCH_TIMEOUT)
        return {"ok": True, "count": count, **(await catalog_stats())}
    except (ScraperError, asyncio.TimeoutError) as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
