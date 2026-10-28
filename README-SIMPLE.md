# MLBD Stream Scraper — Simple Web App with Search

> **Note:** This describes the older single-file `app.py` demo. The current app is
> the Next.js UI + FastAPI stack — see [README.md](README.md) and
> [docs/API.md](docs/API.md).

One file, one command. Opens a website with two tabs:

1. **Search by Name** — type a movie/anime name, it searches movielinkbd.one, finds the detail page, then scrapes stream sources
2. **By Detail URL** — paste a direct movielinkbd.one URL to scrape

Both return JSON with metadata (title, sources, quality variants) — no video playback.

## Run locally

```bash
pip install -r requirements.txt
playwright install chromium
python app.py
```

Then open **http://localhost:8000**

## What happens when you search by name

1. You type a content name (e.g. "Avengers", "Naruto")
2. App opens headless Chromium, goes to movielinkbd.one search page
3. Finds the first matching detail page link (`/movie/` or `/anime/`)
4. Scrapes that page for stream URLs (`.m3u8` / `.mp4`)
5. Parses HLS master playlists into quality variants (1080p, 720p, etc.)
6. Returns JSON: `title`, `sources[]` with `server_name`, `type`, `master_url`, `variants`

## Deploy

Deploy `app.py` on any host that runs Python + Chrome (Render, Railway, Fly.io, VPS):
1. `pip install -r requirements.txt`
2. `playwright install chromium`
3. `python app.py`

## Notes

- Cloudflare challenges are not bypassed — they show as an error
- `# TODO` selectors in `app.py` need verification on the live site (search URL pattern + result link selectors)
- The scraper engine in `scraper/` is unchanged
