"""CLI entry point: python -m scraper <url>"""

from __future__ import annotations

import asyncio
import sys

from .scraper import scrape_streams


async def _main() -> None:
    if len(sys.argv) < 2:
        print("Usage: python -m scraper <page_url>", file=sys.stderr)
        sys.exit(1)
    page_url = sys.argv[1]
    result = await scrape_streams(page_url)
    print(result.model_dump_json(indent=2))


if __name__ == "__main__":
    asyncio.run(_main())
