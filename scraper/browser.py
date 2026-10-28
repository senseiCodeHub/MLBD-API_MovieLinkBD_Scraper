"""Playwright browser context manager with basic stealth."""

from __future__ import annotations

from contextlib import asynccontextmanager
from typing import AsyncIterator

from loguru import logger
from playwright.async_api import (
    async_playwright,
    Browser,
    BrowserContext,
)

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)

STEALTH_JS = """
Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3] });
Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
"""


@asynccontextmanager
async def browser_context(headless: bool = True) -> AsyncIterator[BrowserContext]:
    """Launch headless Chromium with stealth and yield a BrowserContext.

    The context and underlying browser are closed when the block exits.

    Args:
        headless: Whether to run Chromium headless.

    Yields:
        A configured Playwright BrowserContext.
    """
    async with async_playwright() as playwright:
        browser: Browser = await playwright.chromium.launch(
            headless=headless,
            args=[
                "--disable-blink-features=AutomationControlled",
                "--no-sandbox",
                "--disable-setuid-sandbox",
            ],
        )
        context: BrowserContext = await browser.new_context(
            user_agent=USER_AGENT,
            viewport={"width": 1920, "height": 1080},
            locale="en-US",
            timezone_id="America/New_York",
        )
        await context.add_init_script(STEALTH_JS)
        logger.info("Browser context launched (headless={})", headless)
        try:
            yield context
        finally:
            await context.close()
            await browser.close()
            logger.info("Browser context closed")
