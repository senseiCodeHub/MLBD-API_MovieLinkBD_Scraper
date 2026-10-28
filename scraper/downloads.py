"""Parse the download section of a MovieLinkBD detail page.

The site renders download buttons as ``<a href="#" data-dl="..." data-ds="..."
data-dr="...">`` and decodes the real ``/file/<token>`` URL in the browser with
a small XOR routine.  We read the decoded ``href`` from the live DOM first and
fall back to decoding the raw markup if the page script never ran.
"""

from __future__ import annotations

import base64
import html
import re
from urllib.parse import urljoin

from .models import DownloadLink

_TAG = re.compile(r"<[^>]+>")
_SPACE = re.compile(r"\s+")
_QUALITY = re.compile(r"\b(\d{3,4}p)\b", re.I)
_SIZE = re.compile(r"\b(\d+(?:\.\d+)?)\s*(TB|GB|MB|KB|B)\b", re.I)
_DOWNLOAD_WORD = re.compile(r"^\s*(?:⬇\ufe0f?\s*)?download\b[\s:•\-–—\[\]()]*", re.I)

_SIZE_UNITS = {
    "B": 1,
    "KB": 1_000,
    "MB": 1_000_000,
    "GB": 1_000_000_000,
    "TB": 1_000_000_000_000,
}

# <a ... data-dl="..." ... data-ds="..." ... data-dr="..." ...>label</a>
_ANCHOR = re.compile(
    r"<a\b(?P<attrs>[^>]*\bdata-dl=\"[^\"]+\"[^>]*)>(?P<label>.*?)</a>",
    re.S,
)
_DATA_DL = re.compile(r'\bdata-dl="([^"]+)"')
_DATA_DS = re.compile(r'\bdata-ds="([^"]*)"')
_DATA_DR = re.compile(r'\bdata-dr="(\d+)"')


def clean_label(raw: str) -> str:
    """Normalise a download button label into ``"480p • 676 MB"`` form."""
    text = html.unescape(_TAG.sub(" ", raw or ""))
    text = _SPACE.sub(" ", text).strip()
    text = _DOWNLOAD_WORD.sub("", text)
    text = text.replace("•", "•").strip(" •-–—[]()")
    return _SPACE.sub(" ", text).strip()


def parse_size(value: str | None) -> int | None:
    """Parse ``"676 MB"`` into bytes."""
    if not value:
        return None
    match = _SIZE.search(value)
    if not match:
        return None
    amount = float(match.group(1))
    unit = match.group(2).upper()
    return int(amount * _SIZE_UNITS.get(unit, 1))


def build_download(
    url: str, raw_label: str, episode: str | None = None
) -> DownloadLink | None:
    """Turn a decoded href plus its button label into a DownloadLink."""
    if not url:
        return None

    label = clean_label(raw_label)
    quality_match = _QUALITY.search(label)
    size_match = _SIZE.search(label)

    return DownloadLink(
        label=label or "Download",
        quality=quality_match.group(1).lower() if quality_match else None,
        size=_SPACE.sub(" ", size_match.group(0)) if size_match else None,
        size_bytes=parse_size(size_match.group(0) if size_match else None),
        episode=(episode or "").strip() or None,
        url=url,
    )


def decode_download_url(blob: str, seed: str, rounds: int) -> str:
    """Decode the ``data-dl`` payload exactly like the page script does."""
    try:
        state = int(seed, 16) & 0xFFFFFFFF
        for _ in range(rounds):
            state = (state * 1103515245 + 12345) & 0xFFFFFFFF
        raw = base64.b64decode(blob)
    except (ValueError, TypeError):
        return ""

    out: list[str] = []
    for byte in raw:
        state = (state * 1103515245 + 12345) & 0xFFFFFFFF
        out.append(chr(byte ^ ((state >> 16) & 255)))
    return "".join(out)


def parse_downloads_from_html(
    markup: str, base_url: str
) -> list[DownloadLink]:
    """Fallback parser for raw HTML that still carries ``data-dl`` blobs."""
    downloads: list[DownloadLink] = []
    seen: set[str] = set()

    for match in _ANCHOR.finditer(markup):
        attrs = match.group("attrs")
        blob = _DATA_DL.search(attrs)
        if not blob:
            continue

        seed = _DATA_DS.search(attrs)
        rounds = _DATA_DR.search(attrs)
        decoded = decode_download_url(
            blob.group(1),
            seed.group(1) if seed else "",
            int(rounds.group(1)) if rounds else 0,
        )
        if not decoded:
            continue

        url = urljoin(base_url, decoded)
        if url in seen:
            continue
        seen.add(url)

        link = build_download(url, match.group("label"))
        if link:
            downloads.append(link)

    return downloads


def parse_downloads_from_dom(
    items: list[dict], base_url: str
) -> list[DownloadLink]:
    """Convert decoded anchors read from the live DOM into DownloadLinks."""
    downloads: list[DownloadLink] = []
    seen: set[str] = set()

    for item in items:
        href = (item.get("href") or "").strip()
        label = item.get("label") or ""
        if not href or href.startswith(("javascript:", "#")):
            continue

        url = urljoin(base_url, href)
        if url in seen:
            continue
        seen.add(url)

        link = build_download(url, label, item.get("episode"))
        if link:
            downloads.append(link)

    return downloads
