"""Pydantic v2 response models for the scraper."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Literal

from pydantic import BaseModel, Field

StreamType = Literal["hls", "mp4", "mkv", "webm", "auto"]

Category = Literal["movie", "series", "drama", "anime", "adult", "other"]


class StreamVariant(BaseModel):
    """A single quality variant of an HLS stream."""

    quality: str = Field(..., description='e.g. "1080p", "720p", or "auto"')
    bandwidth: int | None = Field(
        None, description="Variant bandwidth in bits per second, if advertised"
    )
    url: str = Field(..., description="Absolute stream URL")


class StreamSource(BaseModel):
    """A stream source (server / mirror) with its quality variants.

    Kept as a flat, episode-agnostic view for backwards compatibility.  The
    richer per-episode data lives in :class:`Episode`.
    """

    server_name: str | None = Field(
        None, description='e.g. "MLBD CDN", "Inline player", or None'
    )
    type: StreamType = Field(
        ..., description="Stream container: hls, mp4, mkv, webm, or auto"
    )
    master_url: str = Field(..., description="Absolute master or direct URL")
    variants: list[StreamVariant] = Field(
        default_factory=list, description="Parsed quality variants (hls only)"
    )
    size_bytes: int | None = Field(
        None, description="File size in bytes, when the server advertises it"
    )
    content_type: str | None = Field(
        None, description='Response Content-Type, e.g. "video/x-matroska"'
    )


class EpisodeSource(BaseModel):
    """One playable source (server / quality / audio) for a single episode."""

    id: str | None = Field(None, description="Site source id")
    provider: str | None = Field(None, description='Provider label, e.g. "MLBD CDN"')
    quality: str | None = Field(None, description='Quality label, e.g. "1080p"')
    quality_label: str | None = Field(
        None, description="Explicit quality label from the site, if any"
    )
    audio: str | None = Field(None, description='Primary audio, e.g. "Hindi"')
    audio_languages: list[str] = Field(
        default_factory=list, description="All advertised audio languages"
    )
    type: StreamType = Field(
        ..., description="Container type: hls, mp4, mkv, webm, or auto"
    )
    name: str | None = Field(None, description="File name shown by the site")
    codec: str | None = Field(None, description='Codec, e.g. "h264"')
    hevc: bool | None = Field(None, description="Whether the source is HEVC")
    is_best: bool = Field(False, description="Site's preferred source flag")
    stream_url: str = Field(..., description="Direct stream URL")
    download_url: str | None = Field(
        None, description="Direct download URL, when the site exposes one"
    )
    size_bytes: int | None = Field(
        None, description="File size in bytes, when the server advertises it"
    )
    content_type: str | None = Field(
        None, description='Response Content-Type, e.g. "video/x-matroska"'
    )


class Episode(BaseModel):
    """One episode (or the single movie entry) with all of its sources."""

    id: str = Field(..., description='Site episode id, e.g. "episode-1"')
    label: str = Field(
        ...,
        description='Normalised label, e.g. "S03E01", "E04" or "S03 Ep 45-48"',
    )
    number: int | None = Field(None, description="Episode number, if any")
    season: int | None = Field(None, description="Season number, if any")
    range_start: int | None = Field(None, description="First episode in the range")
    range_end: int | None = Field(None, description="Last episode in the range")
    kind: str | None = Field(None, description='e.g. "episode", "series", "movie"')
    sources: list[EpisodeSource] = Field(
        default_factory=list, description="Every server/quality for this episode"
    )


class DownloadLink(BaseModel):
    """A quality-labelled download link exposed by the detail page."""

    label: str = Field(..., description='Human label, e.g. "480p • 676 MB"')
    quality: str | None = Field(None, description='Quality tag, e.g. "480p"')
    size: str | None = Field(None, description='Size as shown, e.g. "676 MB"')
    size_bytes: int | None = Field(None, description="Parsed size in bytes")
    episode: str | None = Field(
        None, description='Episode label for series, e.g. "S03E01"'
    )
    url: str = Field(..., description="Absolute download URL")
    type: StreamType | None = Field(None, description="Container type, if known")
    audio: str | None = Field(None, description="Primary audio language, if known")


class ScrapeResult(BaseModel):
    """Result of scraping a detail page for streams and downloads."""

    page_url: str = Field(..., description="Final URL of the detail page scraped")
    title: str | None = Field(None, description="Page title, if available")
    poster: str | None = Field(None, description="Poster image URL, if available")
    content_type: str | None = Field(
        None, description='Site content type, e.g. "movie" or "series"'
    )
    category: Category = Field(
        "other", description="Category derived from the page URL/payload"
    )
    adult: bool = Field(False, description="Whether the title is adult content")
    completed: bool | None = Field(
        None, description="Whether the site marks the title as completed"
    )
    episodes: list[Episode] = Field(
        default_factory=list,
        description="Per-episode sources; a movie has a single entry",
    )
    sources: list[StreamSource] = Field(
        default_factory=list,
        description="Flat, de-duplicated stream sources across all episodes",
    )
    downloads: list[DownloadLink] = Field(
        default_factory=list,
        description="Direct download links across all episodes",
    )
    scraped_at: datetime = Field(
        default_factory=lambda: datetime.now(timezone.utc),
        description="UTC timestamp when scraping finished",
    )
