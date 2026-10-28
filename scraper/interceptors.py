"""Network response interception for capturing stream URLs."""

from __future__ import annotations

from urllib.parse import urlparse

from loguru import logger
from playwright.async_api import Page, Response

_STREAM_EXTENSIONS = (".m3u8", ".mpd", ".mp4", ".mkv", ".webm")

_STREAM_CT_TYPES = (
    "application/vnd.apple.mpegurl",
    "application/x-mpegurl",
    "application/dash+xml",
    "video/",
)

# MovieLinkBD serves the inline player file from this host with a token path
# (e.g. ``https://fsldownload.com/p/<token>``) and no file extension.
_STREAM_HOSTS = ("fsldownload.com",)

# Never treat ad / analytics creatives as stream sources.
_AD_HOSTS = (
    "doubleclick.net",
    "googlesyndication.com",
    "google-analytics.com",
    "googletagmanager.com",
    "jaya9bangla.com",
    "dramalinkbd.tv",
    "facebook.net",
)


def _host_matches(host: str, candidates: tuple[str, ...]) -> bool:
    return any(host == item or host.endswith(f".{item}") for item in candidates)


def looks_like_stream(url: str, content_type: str | None = None) -> bool:
    """Return True when a URL/response looks like playable media."""
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()
    path = parsed.path.lower()
    ct = (content_type or "").lower()

    if _host_matches(host, _AD_HOSTS):
        return False

    if path.endswith(_STREAM_EXTENSIONS):
        return True

    # Subtitle/sprite tracks live on the same file host; never treat them as
    # video just because of the hostname.
    if ct and ct.startswith(
        ("text/", "image/", "application/json", "application/xml")
    ):
        return False

    if _host_matches(host, _STREAM_HOSTS):
        return True

    return any(item in ct for item in _STREAM_CT_TYPES)


class StreamInterceptor:
    """Listens to page responses and captures stream URLs."""

    def __init__(self) -> None:
        self.captured: set[str] = set()
        self.content_types: dict[str, str] = {}

    def _on_response(self, response: Response) -> None:
        try:
            content_type = response.headers.get("content-type", "")
            if looks_like_stream(response.url, content_type):
                self.captured.add(response.url)
                if content_type:
                    self.content_types[response.url] = content_type
                logger.debug("Captured stream URL: {}", response.url)
        except Exception as exc:  # noqa: BLE001
            logger.debug("Interceptor ignored a response: {}", exc)

    def attach(self, page: Page) -> None:
        """Attach the response listener to a Playwright page."""
        page.on("response", self._on_response)
        logger.debug("Interceptor attached to page: {}", page.url)
