# MLBD Stream Scraper UI

> **Note:** The main project guide is [README.md](README.md). The complete API
> reference lives in [docs/API.md](docs/API.md).

A Next.js frontend + FastAPI backend that searches the **whole MovieLinkBD
catalogue** (anime, movies, series, drama, adult) and, for any title, returns
**every episode's stream URL** plus its **direct download link**, servers,
qualities and audio tracks — as cards or raw JSON. No video playback.

## Flow

1. Type a title (e.g. `hello`, `slime`, `doctor x`) or paste a detail page URL.
2. Title input → `GET /search` → catalogue result cards with posters. Leave
   the box empty and press **Browse** to page through the whole catalogue.
3. Use the category tabs (All / Movies / Series / Drama / Anime / Adult) to
   filter the current query or browse that category.
4. Click a card (or submit a URL) → `GET /scrape` → per-episode stream sources
   (`S03E01`, `E02`, `S03 Ep 45-48`, …), quality/audio/size, and download
   links.
5. Copy a stream URL, open it directly, or open its download link. The raw JSON
   is one tab away.

The ad-gated **"UNLOCK & WATCH NOW"** bridge (`/continue/...`) is never clicked
and no ad iframe or click script is followed. The scraper reads the site's own
inline player payload, which is served with the page.

## How the data is extracted

MovieLinkBD embeds the whole player state in:

```html
<script id="mlbdInlinePlayerData" type="application/json"
        data-s="<hex seed>" data-r="600">…base64…</script>
```

The body is base64 + XOR against an LCG stream (same routine as `data-dl`).
Decoding it yields:

- `title`, `poster`, `content_type`, `adult`, `completed`, `origin_fid`
- `episodes[]` — one entry per episode (or one `Movie`/`Series` entry)
  - `sources[]` — every server/quality/audio variant with:
    - `url` → direct stream URL (`https://fsldownload.com/p/<token>`)
    - `download_url` → direct download URL (`https://fsldownload.com/d/<token>`
      or `/file/<token>`)
    - `quality`, `audio`, `audio_languages`, `provider`, `type`, `name`,
      `codec`, `hevc`, `is_best`

A plain HTTP GET returns a shortened page; the request must carry browser-like
headers (`sec-fetch-*`, `sec-ch-ua*`, full `accept`) to receive the payload. If
the payload is missing (Cloudflare or a new page type), the scraper falls back
to the previous Playwright/DOM path and captures the player's `<video>` URL.

## Structure

- `api/main.py` — FastAPI backend (`/search`, `/scrape`, `/catalog/*`)
- `scraper/catalog.py` — whole-catalogue index built from the site's
  search-suggest manifest + shards, cached on disk
- `scraper/scraper.py` — payload decode, per-episode extraction, media probing,
  Playwright fallback
- `scraper/models.py` — `Episode`, `EpisodeSource`, `ScrapeResult`, downloads
- `scraper/search.py` — the site's own 25-result search (`scope=site`)
- `scraper/downloads.py` — legacy `data-dl` decoder used by the fallback path
- `web/` — Next.js 14 app (Vercel-ready)

## Catalogue index

The site's `/search?q=` page is capped at 25 results and ignores
pagination/category parameters, so it cannot answer "whole server". The scraper
uses the site's own search-suggest index instead:

- `GET /search-suggest/manifest` →
  `{ ready, schema, v, count, shards: 1296, limit: 240, … }`
- shard `GET /assets/search-suggest/v<v>/<key>.json` where `<key>` is
  `[a-z0-9]{2}` (1296 shards)
- each row: `[title, slug, poster, type, year, adult]`
- types: `movie`, `series`, `drama`, `anime`, `download18plus` (adult)
- shards are capped at 240 rows; the live fallback
  `/api/search-suggest-live.php?k=<key>` recovers truncated rows

The merged, de-duplicated index is cached at
`~/.cache/mlbd/catalog-<version>.json` for 6 hours (override the directory with
`MLBD_CACHE_DIR`). A first crawl takes ~10–20s and indexes ~14k titles; later
searches are in-memory and near-instant. The API warms the index on startup.

## API

### `GET /search`

Whole-catalogue search (default) or the site's own search.

| Param | Default | Description |
| --- | --- | --- |
| `q` | `""` | Title fragment; empty browses the category |
| `category` | `all` | `all`, `movie`, `series`, `drama`, `anime`, `adult` |
| `limit` | `24` | 1–100 |
| `offset` | `0` | Pagination offset |
| `scope` | `catalog` | `catalog` or `site` (site = 25-result page) |

```json
{
  "ok": true,
  "data": {
    "query": "hello",
    "category": "all",
    "results": [
      {
        "title": "Hello Bachhon (2026) S01 [Ep 01-05 Completed]",
        "url": "https://movielinkbd.tv/series/hello-bachhon-2026",
        "slug": "hello-bachhon-2026",
        "poster": "https://image.tmdb.org/t/p/original/….jpg",
        "type": "series",
        "category": "series",
        "year": "2026",
        "adult": false,
        "quality": null,
        "language": null,
        "rating": null
      }
    ],
    "total": 25,
    "limit": 24,
    "offset": 0,
    "source": "catalog",
    "catalog_count": 14109,
    "searched_at": "2026-10-29T02:03:56.201Z"
  },
  "error": null,
  "error_type": null
}
```

### `GET /scrape?url=…` (or `POST /scrape`)

Returns every episode and every source for the title. `probe=false` skips the
HEAD requests used for `size_bytes`/`content_type`.

```json
{
  "ok": true,
  "data": {
    "page_url": "https://movielinkbd.tv/series/mKs_…",
    "title": "Heart Beat (2026)",
    "poster": "https://…jpg",
    "content_type": "series",
    "category": "series",
    "adult": false,
    "completed": false,
    "episodes": [
      {
        "id": "episode-1",
        "label": "S03E01",
        "number": 1,
        "season": 3,
        "range_start": null,
        "range_end": null,
        "kind": "episode",
        "sources": [
          {
            "id": "src-…",
            "provider": "MLBD CDN",
            "quality": "1080p",
            "quality_label": null,
            "audio": "Hindi",
            "audio_languages": ["Hindi", "Tamil", "Telugu", "Malayalam", "Kannada"],
            "type": "mkv",
            "name": "MovieLinkBD.com - …mkv",
            "codec": "h264",
            "hevc": false,
            "is_best": false,
            "stream_url": "https://fsldownload.com/p/<token>",
            "download_url": "https://fsldownload.com/d/<token>",
            "size_bytes": 915849188,
            "content_type": "video/x-matroska"
          }
        ]
      }
    ],
    "sources": [
      {
        "server_name": "MLBD CDN",
        "type": "mkv",
        "master_url": "https://fsldownload.com/p/<token>",
        "variants": [
          { "quality": "1080p", "bandwidth": null, "url": "https://fsldownload.com/p/<token>" }
        ],
        "size_bytes": 915849188,
        "content_type": "video/x-matroska"
      }
    ],
    "downloads": [
      {
        "label": "1080p • Hindi • 916 MB",
        "quality": "1080p",
        "size": "916 MB",
        "size_bytes": 915849188,
        "episode": "S03E01",
        "url": "https://fsldownload.com/d/<token>",
        "type": "mkv",
        "audio": "Hindi"
      }
    ],
    "scraped_at": "2026-10-29T02:03:59.000Z"
  },
  "error": null,
  "error_type": null
}
```

Field notes:

- `episodes[]` is the primary view: each entry carries **all** sources for that
  episode. A movie has one `Movie` entry; a series/anime/drama has one entry per
  episode (or a single `Series` entry for a season-pack page).
- `label` is normalised: `S03E01` when the payload has a season, `E04` when it
  does not, and `S03 Ep 45-48` for a range/pack entry. `number`, `season`,
  `range_start` and `range_end` keep the raw values.
- `episodes[].sources[].stream_url` is the direct playable file. `type` is
  `mkv`, `mp4`, `webm`, `hls` or `auto`.
- `download_url` is the site's download link for that source. Some titles expose
  a direct file (`https://fsldownload.com/d/<token>`); others expose the site's
  `/file/<token>` download page, which may show its own unlock/ad step. Relative
  `/file/<token>` values are resolved against the detail page. The scraper never
  automates that gate.
- `size_bytes` / `content_type` come from a ranged `HEAD`/`GET` probe of the
  stream URL; `null` when the server does not advertise them.
- `sources[]` is a flat, de-duplicated compatibility view across all episodes.
- `downloads[]` is the flat download view, labelled with the episode.
- `category` is derived from the page path (`/anime/` wins even when the payload
  says `content_type: "series"`); `adult` is true for `/download18plus/`.

### Other endpoints

- `GET /health` — liveness check
- `GET /catalog/status` — loaded version, title count, type counts, age
- `POST /catalog/refresh` — force a fresh shard crawl

A scrape typically takes 2–6s (one HEAD per source, bounded concurrency); the
API allows 150s. Catalogue search is instant after the first crawl.

## Run locally

### 1. Install scraper deps
```bash
pip install -r requirements.txt
playwright install chromium
```

### 2. Run API
```bash
uvicorn api.main:app --reload --port 8000
```

### 3. Run web
```bash
cd web
npm install
NEXT_PUBLIC_API_BASE=http://localhost:8000 npm run dev
```

Open http://localhost:3000.

## Deploy

### API (Render / Railway / Fly.io)
1. Push this repo.
2. Connect `render.yaml` (Render) or deploy `api/main.py` with `uvicorn`.
3. Set `PYTHON_VERSION=3.11`.
4. Persist `~/.cache/mlbd` (or set `MLBD_CACHE_DIR`) so the catalogue survives
   restarts. Without a disk, the API re-crawls on cold start (~15s).

### Web (Vercel)
1. Import the `web/` folder in Vercel.
2. Add env var: `NEXT_PUBLIC_API_BASE` = your deployed API URL.
3. Deploy.

## Notes

- The API does not play video — it only returns scraped metadata as JSON.
- Detail pages redirect to request-specific `mKs_…` slugs; canonical slugs from
  the catalogue (e.g. `/series/heart-beat-2026`) work and `page_url` reflects
  the final URL.
- Per scraper policy, Cloudflare challenges are not bypassed; they return an
  error (the browser fallback is tried first).
