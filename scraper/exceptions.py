"""Custom exceptions for the scraper package."""


class ScraperError(Exception):
    """Base exception for all scraper errors."""


class CloudflareBlocked(ScraperError):
    """Raised when a Cloudflare challenge page is detected.

    Per project policy we do not attempt to bypass Cloudflare; we fail
    gracefully and surface this error to the caller.
    """


class NoStreamFound(ScraperError):
    """Raised when no stream URLs could be captured from a page."""

    def __init__(self, page_url: str, debug: dict | None = None):
        super().__init__(page_url)
        self.page_url = page_url
        self.debug = debug or {}


class BrowserError(ScraperError):
    """Raised when browser launch or navigation fails."""
