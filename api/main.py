"""FastAPI backend wrapping the MovieLinkBD scraper core."""

from __future__ import annotations

import asyncio
import re
from contextlib import asynccontextmanager
from typing import Literal, Optional

from fastapi import FastAPI, HTTPException, Query
from fastapi.middleware.cors import CORSMiddleware
from loguru import logger
from pydantic import BaseModel, Field

from scraper.catalog import (
    CatalogItem,
    CatalogResults,
    catalog_stats,
    ensure_catalog,
    search_catalog,
)
from scraper.exceptions import (
    BrowserError,
    CloudflareBlocked,
    NoStreamFound,
    ScraperError,
)
from scraper.scraper import scrape_streams
from scraper.search import search_content

SCRAPE_TIMEOUT = 150
SEARCH_TIMEOUT = 120

_TYPE_TO_CATEGORY = {
    "movie": "movie",
    "series": "series",
    "drama": "drama",
    "anime": "anime",
    "download18plus": "adult",
    "adult": "adult",
}


async def _warm_catalog() -> None:
    try:
        count = await ensure_catalog()
        logger.info("Catalog warmed: {} titles", count)
    except Exception as exc:  # noqa: BLE001
        logger.warning("Catalog warm-up failed: {}", exc)


@asynccontextmanager
async def lifespan(app: FastAPI):
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
        result = await asyncio.wait_for(
            scrape_streams(req.url, timeout=30, probe=req.probe),
            timeout=SCRAPE_TIMEOUT,
        )
        return ScrapeResponse(ok=True, data=result.model_dump())
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
    scope: Literal["catalog", "site"] = Query(
        "catalog",
        description="catalog = whole server index; site = the site's 25-result search",
    ),
) -> SearchResponse:
    """Search the whole MovieLinkBD catalogue (or the site's own search page)."""
    query = q.strip()

    try:
        if scope == "site":
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
            data = await asyncio.wait_for(
                search_catalog(
                    query,
                    category=category,
                    limit=limit,
                    offset=offset,
                ),
                timeout=SEARCH_TIMEOUT,
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


@app.post("/catalog/refresh")
async def catalog_refresh() -> dict:
    """Force a fresh crawl of the search-suggest shards."""
    try:
        count = await asyncio.wait_for(ensure_catalog(force=True), timeout=SEARCH_TIMEOUT)
        return {"ok": True, "count": count, **(await catalog_stats())}
    except (ScraperError, asyncio.TimeoutError) as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
