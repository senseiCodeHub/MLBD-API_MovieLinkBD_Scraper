"""Comparison between a stored scrape and a fresh live scrape.

Used by the ``/compare`` API endpoint so the UI can surface what the server
has added, removed or changed since a title was last saved to MySQL.

Matching rules:

* Episodes are matched by ``(season, number)`` when both sides have them,
  falling back to ``label``. An episode present only on the live side is
  ``added``; present only on the stored side is ``removed``; present on both
  but with different sources/downloads is ``changed``.
* Sources within an episode are matched by ``stream_url``.
* Downloads are matched by ``download_url``.

All comparison is structural (URLs, qualities, labels), so it works regardless
of when each scrape ran.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


def _key_episode(episode: dict[str, Any]) -> tuple:
    season = episode.get("season")
    number = episode.get("number")
    if isinstance(season, int) and isinstance(number, int):
        return ("n", season, number)
    label = str(episode.get("label") or "").strip().lower()
    return ("l", label)


def _key_source(source: dict[str, Any]) -> str:
    return str(source.get("stream_url") or "")


def _key_download(download: dict[str, Any]) -> str:
    return str(download.get("url") or "")


def _diff_lists(
    stored: list[dict[str, Any]],
    live: list[dict[str, Any]],
    key: callable,
) -> dict[str, list[dict[str, Any]]]:
    """Diff two lists of dicts keyed by ``key``."""
    stored_by_key: dict[Any, dict[str, Any]] = {}
    for item in stored:
        stored_by_key.setdefault(key(item), item)
    live_by_key: dict[Any, dict[str, Any]] = {}
    for item in live:
        live_by_key.setdefault(key(item), item)

    added = [live_by_key[k] for k in live_by_key if k not in stored_by_key]
    removed = [stored_by_key[k] for k in stored_by_key if k not in live_by_key]
    changed = []
    for k in stored_by_key:
        if k in live_by_key and stored_by_key[k] != live_by_key[k]:
            changed.append(
                {"stored": stored_by_key[k], "live": live_by_key[k], "key": k}
            )
    return {"added": added, "removed": removed, "changed": changed}


@dataclass
class FieldDiff:
    stored: Any
    live: Any
    changed: bool


@dataclass
class EpisodeDiff:
    label: str
    number: int | None
    season: int | None
    status: str  # "added" | "removed" | "changed" | "same"
    sources: dict[str, list[dict[str, Any]]] = field(default_factory=dict)
    downloads: dict[str, list[dict[str, Any]]] = field(default_factory=dict)


@dataclass
class ContentDiff:
    page_url: str
    title: FieldDiff
    poster: FieldDiff
    category: FieldDiff
    completed: FieldDiff
    adult: FieldDiff
    episodes_added: list[EpisodeDiff] = field(default_factory=list)
    episodes_removed: list[EpisodeDiff] = field(default_factory=list)
    episodes_changed: list[EpisodeDiff] = field(default_factory=list)
    episodes_same: int = 0
    sources_added_total: int = 0
    sources_removed_total: int = 0
    sources_changed_total: int = 0
    downloads_added_total: int = 0
    downloads_removed_total: int = 0
    downloads_changed_total: int = 0

    def to_dict(self) -> dict[str, Any]:
        return {
            "page_url": self.page_url,
            "title": {
                "stored": self.title.stored,
                "live": self.title.live,
                "changed": self.title.changed,
            },
            "poster": {
                "stored": self.poster.stored,
                "live": self.poster.live,
                "changed": self.poster.changed,
            },
            "category": {
                "stored": self.category.stored,
                "live": self.category.live,
                "changed": self.category.changed,
            },
            "completed": {
                "stored": self.completed.stored,
                "live": self.completed.live,
                "changed": self.completed.changed,
            },
            "adult": {
                "stored": self.adult.stored,
                "live": self.adult.live,
                "changed": self.adult.changed,
            },
            "episodes": {
                "added": [
                    {
                        "label": e.label,
                        "number": e.number,
                        "season": e.season,
                        "status": e.status,
                        "sources_added": e.sources.get("added", []),
                        "sources_removed": e.sources.get("removed", []),
                        "sources_changed": e.sources.get("changed", []),
                        "downloads_added": e.downloads.get("added", []),
                        "downloads_removed": e.downloads.get("removed", []),
                        "downloads_changed": e.downloads.get("changed", []),
                    }
                    for e in self.episodes_added
                ],
                "removed": [
                    {
                        "label": e.label,
                        "number": e.number,
                        "season": e.season,
                        "status": e.status,
                        "sources_added": e.sources.get("added", []),
                        "sources_removed": e.sources.get("removed", []),
                        "sources_changed": e.sources.get("changed", []),
                        "downloads_added": e.downloads.get("added", []),
                        "downloads_removed": e.downloads.get("removed", []),
                        "downloads_changed": e.downloads.get("changed", []),
                    }
                    for e in self.episodes_removed
                ],
                "changed": [
                    {
                        "label": e.label,
                        "number": e.number,
                        "season": e.season,
                        "status": e.status,
                        "sources_added": e.sources.get("added", []),
                        "sources_removed": e.sources.get("removed", []),
                        "sources_changed": e.sources.get("changed", []),
                        "downloads_added": e.downloads.get("added", []),
                        "downloads_removed": e.downloads.get("removed", []),
                        "downloads_changed": e.downloads.get("changed", []),
                    }
                    for e in self.episodes_changed
                ],
                "same": self.episodes_same,
                "added_count": len(self.episodes_added),
                "removed_count": len(self.episodes_removed),
                "changed_count": len(self.episodes_changed),
            },
            "sources": {
                "added_total": self.sources_added_total,
                "removed_total": self.sources_removed_total,
                "changed_total": self.sources_changed_total,
            },
            "downloads": {
                "added_total": self.downloads_added_total,
                "removed_total": self.downloads_removed_total,
                "changed_total": self.downloads_changed_total,
            },
        }


def _normalize_episode(episode: dict[str, Any]) -> dict[str, Any]:
    """Return a plain dict view of an episode for comparison."""
    sources = episode.get("sources") or []
    downloads = episode.get("downloads") or []
    if not isinstance(sources, list):
        sources = []
    if not isinstance(downloads, list):
        downloads = []
    return {
        "label": episode.get("label"),
        "number": episode.get("number"),
        "season": episode.get("season"),
        "kind": episode.get("kind"),
        "sources": sources,
        "downloads": downloads,
    }


def _diff_episode_sources(
    stored: list[dict[str, Any]], live: list[dict[str, Any]]
) -> dict[str, list[dict[str, Any]]]:
    return _diff_lists(stored, live, _key_source)


def _diff_episode_downloads(
    stored: list[dict[str, Any]], live: list[dict[str, Any]]
) -> dict[str, list[dict[str, Any]]]:
    return _diff_lists(stored, live, _key_download)


def diff_scrapes(
    stored: dict[str, Any] | None, live: dict[str, Any], page_url: str
) -> ContentDiff:
    """Compare a stored scrape snapshot against a fresh live scrape.

    ``stored`` may be ``None`` when nothing has been saved yet; in that case
    everything on the live side is reported as ``added``.
    """
    live_episodes = [
        _normalize_episode(ep)
        for ep in (live.get("episodes") or [])
        if isinstance(ep, dict)
    ]
    live_downloads = live.get("downloads") or []
    if not isinstance(live_downloads, list):
        live_downloads = []

    diff = ContentDiff(
        page_url=page_url,
        title=FieldDiff(
            (stored or {}).get("title"), live.get("title"),
            (stored or {}).get("title") != live.get("title"),
        ),
        poster=FieldDiff(
            (stored or {}).get("poster"), live.get("poster"),
            (stored or {}).get("poster") != live.get("poster"),
        ),
        category=FieldDiff(
            (stored or {}).get("category"), live.get("category"),
            (stored or {}).get("category") != live.get("category"),
        ),
        completed=FieldDiff(
            (stored or {}).get("completed"), live.get("completed"),
            (stored or {}).get("completed") != live.get("completed"),
        ),
        adult=FieldDiff(
            (stored or {}).get("adult"), live.get("adult"),
            (stored or {}).get("adult") != live.get("adult"),
        ),
    )

    if stored is None:
        for ep in live_episodes:
            src_diff = _diff_episode_sources([], ep["sources"])
            dl_diff = _diff_episode_downloads([], ep["downloads"])
            diff.episodes_added.append(
                EpisodeDiff(
                    label=str(ep.get("label") or ""),
                    number=ep.get("number"),
                    season=ep.get("season"),
                    status="added",
                    sources=src_diff,
                    downloads=dl_diff,
                )
            )
            diff.sources_added_total += len(src_diff["added"])
            diff.downloads_added_total += len(dl_diff["added"])
        diff.episodes_same = 0
        return diff

    stored_episodes = [
        _normalize_episode(ep)
        for ep in (stored.get("episodes") or [])
        if isinstance(ep, dict)
    ]
    stored_downloads = stored.get("downloads") or []
    if not isinstance(stored_downloads, list):
        stored_downloads = []

    stored_by_key: dict[Any, dict[str, Any]] = {}
    for ep in stored_episodes:
        stored_by_key.setdefault(_key_episode(ep), ep)
    live_by_key: dict[Any, dict[str, Any]] = {}
    for ep in live_episodes:
        live_by_key.setdefault(_key_episode(ep), ep)

    # Matched episodes present on both sides.
    for key in stored_by_key:
        if key not in live_by_key:
            continue
        stored_ep = stored_by_key[key]
        live_ep = live_by_key[key]
        src_diff = _diff_episode_sources(stored_ep["sources"], live_ep["sources"])
        dl_diff = _diff_episode_downloads(
            stored_ep["downloads"], live_ep["downloads"]
        )
        if src_diff["added"] or src_diff["removed"] or src_diff["changed"] or \
           dl_diff["added"] or dl_diff["removed"] or dl_diff["changed"]:
            diff.episodes_changed.append(
                EpisodeDiff(
                    label=str(live_ep.get("label") or ""),
                    number=live_ep.get("number"),
                    season=live_ep.get("season"),
                    status="changed",
                    sources=src_diff,
                    downloads=dl_diff,
                )
            )
            diff.sources_added_total += len(src_diff["added"])
            diff.sources_removed_total += len(src_diff["removed"])
            diff.sources_changed_total += len(src_diff["changed"])
            diff.downloads_added_total += len(dl_diff["added"])
            diff.downloads_removed_total += len(dl_diff["removed"])
            diff.downloads_changed_total += len(dl_diff["changed"])
        else:
            diff.episodes_same += 1

    # Episodes only on the live side -> added.
    for key in live_by_key:
        if key in stored_by_key:
            continue
        ep = live_by_key[key]
        src_diff = _diff_episode_sources([], ep["sources"])
        dl_diff = _diff_episode_downloads([], ep["downloads"])
        diff.episodes_added.append(
            EpisodeDiff(
                label=str(ep.get("label") or ""),
                number=ep.get("number"),
                season=ep.get("season"),
                status="added",
                sources=src_diff,
                downloads=dl_diff,
            )
        )
        diff.sources_added_total += len(src_diff["added"])
        diff.downloads_added_total += len(dl_diff["added"])

    # Episodes only on the stored side -> removed.
    for key in stored_by_key:
        if key in live_by_key:
            continue
        ep = stored_by_key[key]
        diff.episodes_removed.append(
            EpisodeDiff(
                label=str(ep.get("label") or ""),
                number=ep.get("number"),
                season=ep.get("season"),
                status="removed",
            )
        )

    return diff
