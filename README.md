# MLBD Scraper — MovieLinkBD Whole-Catalogue API

[![MIT License](https://img.shields.io/badge/license-MIT-f5a524.svg)](LICENSE)
[![Python 3.11+](https://img.shields.io/badge/python-3.11%2B-3776ab.svg)](https://www.python.org/)
[![FastAPI](https://img.shields.io/badge/FastAPI-2.0-009688.svg)](https://fastapi.tiangolo.com/)
[![Next.js 14](https://img.shields.io/badge/Next.js-14-000000.svg)](https://nextjs.org/)
[![Stars](https://img.shields.io/github/stars/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper?style=social)](https://github.com/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper/stargazers)

**Search the whole MovieLinkBD catalogue and pull every episode's stream URL,
server, quality, audio track and download link — as a clean web UI or JSON API.**

> ⭐ **If this project saves you time, please [star the repo](https://github.com/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper).**
> Stars help other people find it and keep the project maintained.

---

## Why this exists

MovieLinkBD's own search is capped at 25 results and its detail pages only show
the currently playing file. This project builds a **whole-server index** and
reads the site's own inline player payload, so one request returns:

- every title in the catalogue — movies, series, drama, anime and adult;
- every **episode** of a series/anime/drama (`S1E1`, `E2`, `E3`, …);
- every **source** for each episode: provider, quality, audio languages, codec,
  file type and size;
- the **stream URL** and **download link** for each source.

It never clicks the site's **UNLOCK & WATCH NOW** bridge, never loads an ad
iframe, and never runs its click scripts.

---

## Features

| | |
| --- | --- |
| 🔎 **Whole-catalogue search** | ~14k titles indexed from the site's own search-suggest files, with category filters, pagination and relevance ranking. |
| 🎬 **Per-episode streams** | Each episode keeps all of its sources instead of just the current player file. |
| 🧩 **Every server/quality** | `1080p`/`720p`/`480p`, `mkv`/`mp4`/`webm`/`hls`, audio languages, codec, HEVC flag, size and MIME type. |
| ⬇️ **Downloads** | Direct file links plus the site's `/file/<token>` pages, labelled with episode, quality and size. |
| 🖥️ **Polished web UI** | Dark cinematic Next.js app with poster cards, category tabs, copy buttons, a JSON view and mobile support. |
| ⚡ **FastAPI backend** | Typed endpoints, CORS enabled, automatic OpenAPI docs at `/docs`. |
| 🛡️ **No ad-gate automation** | Data is decoded from the page's embedded payload; ad/unlock steps are left alone. |

---

## Architecture

```
                     ┌──────────────────────────────┐
  Browser ──────────▶│  Next.js 14 web app (web/)   │
                     │  search · browse · details   │
                     └──────────────┬───────────────┘
                                    │ JSON
                     ┌──────────────▼───────────────┐
                     │  FastAPI (api/main.py)       │
                     │  /search · /scrape · /catalog│
                     └───────┬──────────────┬───────┘
                             │              │
              ┌──────────────▼───┐   ┌──────▼─────────────────┐
              │ scraper/catalog  │   │ scraper/scraper        │
              │ search-suggest   │   │ inline payload decode  │
              │ shards + cache   │   │ per-episode extraction │
              └──────────────┬───┘   └──────┬─────────────────┘
                             │              │
                             └──────┬───────┘
                                    ▼
                         https://movielinkbd.tv
```

---

## Project structure

```
.
├── api/
│   └── main.py              # FastAPI app: /search, /scrape, /catalog/*
├── scraper/
│   ├── catalog.py           # whole-catalogue index + disk cache
│   ├── scraper.py           # payload decode, per-episode extraction, probing
│   ├── models.py            # Episode / EpisodeSource / ScrapeResult
│   ├── search.py            # the site's own 25-result search (scope=site)
│   ├── downloads.py         # legacy data-dl decoder (browser fallback)
│   ├── browser.py           # Playwright fallback
│   ├── store.py             # Supabase mirror: titles, scrapes, links
│   └── exceptions.py
├── web/                     # Next.js 14 frontend (Vercel-ready)
│   ├── app/                 # home page, layout, styles
│   ├── components/          # search, cards, episode list, icons
│   └── lib/                 # API client, types, formatting
├── scripts/
│   └── serve-public.ps1     # local API + public tunnel URL
├── docs/
│   ├── API.md               # full API reference
│   ├── DATABASE.md          # Supabase mirror (local scraper → Postgres)
│   └── DEPLOY-RENDER.md     # Render + Vercel deployment guide
├── Dockerfile               # API image with Playwright/Chromium
├── .dockerignore
├── requirements.txt
├── render.yaml
└── LICENSE
```

---

## Quick start

### 1. Install the scraper

```bash
pip install -r requirements.txt
playwright install chromium
```

### 2. Run the API

```bash
uvicorn api.main:app --reload --port 8000
```

- API: <http://localhost:8000>
- Swagger UI: <http://localhost:8000/docs>
- Catalogue status: <http://localhost:8000/catalog/status>

The catalogue warms in the background on startup (~10–20 s on a cold cache).

### 3. Run the web app

```bash
cd web
npm install
NEXT_PUBLIC_API_BASE=http://localhost:8000 npm run dev
```

Open <http://localhost:3000>.

On Windows PowerShell:

```powershell
cd web
$env:NEXT_PUBLIC_API_BASE = "http://localhost:8000"
npm run dev
```

---

## API at a glance

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/health` | Liveness probe |
| `GET` | `/search?q=&category=&limit=&offset=&scope=` | Whole-catalogue search (or the site's own search with `scope=site`) |
| `GET` | `/scrape?url=&probe=` | Every episode, source and download for a detail page |
| `POST` | `/scrape` | JSON variant of `/scrape` |
| `GET` | `/catalog/status` | Index version, title count and type counts |
| `POST` | `/catalog/refresh` | Force a fresh shard crawl |
| `POST` | `/catalog/sync` | Push the local index into Supabase |
| `GET` | `/db/status` | Supabase mirror status and row counts |

```bash
# Search
curl "http://localhost:8000/search?q=slime&category=anime&limit=5"

# Every episode of the first match
curl "http://localhost:8000/scrape?url=https%3A%2F%2Fmovielinkbd.tv%2Fanime%2Fthat-time-i-got-reincarnated-as-a-slime&probe=false"
```

📖 **Full reference: [`docs/API.md`](docs/API.md)** — parameters, response
schemas, error codes, data models, cache behaviour and client examples.

🚀 **Deploy: [`docs/DEPLOY-RENDER.md`](docs/DEPLOY-RENDER.md)** — Render +
Vercel walkthrough, free-plan limits and troubleshooting.

---

## How the data is extracted

MovieLinkBD embeds its player state in the detail page:

```html
<script id="mlbdInlinePlayerData" type="application/json"
        data-s="<hex seed>" data-r="600">…base64…</script>
```

The body is base64 + XOR against an LCG keystream. After decoding, the payload
contains `title`, `poster`, `content_type`, `adult`, `completed`, and an
`episodes[]` array where each episode has its own `sources[]`:

```json
{
  "url": "https://fsldownload.com/p/<token>",
  "download_url": "https://fsldownload.com/d/<token>",
  "quality": "1080p",
  "audio": "Hindi",
  "audio_languages": ["Hindi", "Tamil"],
  "provider": "MLBD CDN",
  "type": "mkv",
  "codec": "h264",
  "size_bytes": 915849188
}
```

A plain HTTP request gets a shortened page; the scraper sends browser-like
headers (`sec-fetch-*`, `sec-ch-ua*`, full `accept`) to receive the payload.
If the payload is missing (Cloudflare or a new page type), it falls back to the
Playwright DOM path and captures the player's `<video>` URL.

Episode labels are normalised in the API:

| Payload | Label |
| --- | --- |
| season + number | `S03E01` |
| number only | `E04` |
| range pack | `S03 Ep 45-48` |

Raw `number`, `season`, `range_start` and `range_end` are preserved.

---

## Whole-catalogue index

The site's `/search?q=` page cannot answer "search everything", so the scraper
uses the site's own search-suggest files:

1. `GET /search-suggest/manifest` → version + shard count.
2. Crawl all 1,296 shards at
   `/assets/search-suggest/v<version>/<key>.json`.
3. Recover rows truncated by the 240-row shard cap from
   `/api/search-suggest-live.php?k=<key>`, and merge the popular feed.

Rows are `[title, slug, poster, type, year, adult]` with types `movie`,
`series`, `drama`, `anime` and `download18plus` (mapped to `adult`).

The merged index is cached at `~/.cache/mlbd/catalog-<version>.json` for
6 hours. Override the directory with `MLBD_CACHE_DIR`. Cached searches are
in-memory and instant.

---

## Supabase mirror (run the scraper locally, serve from the database)

Because only residential IPs reach MovieLinkBD, the scraper usually runs on a
local machine. Everything it learns can be mirrored into Supabase, so a deployed
API still answers searches and cached pages when the local machine is offline:

```bash
python -m scraper.store sync     # catalogue titles -> mlbd_titles
python -m scraper.store stats    # row counts
```

Every successful `/scrape` also stores the page and all of its links. With
`/search?scope=db` or an unreachable live catalogue, the API answers from the
mirror and reports `"source": "db"`; `/scrape` falls back to a stored snapshot
marked `"cached": true`.

📖 **Details, schema and RLS: [`docs/DATABASE.md`](docs/DATABASE.md)**

---

## Configuration

| Variable | Where | Default | Purpose |
| --- | --- | --- | --- |
| `NEXT_PUBLIC_API_BASE` | web | `http://localhost:8000` | Backend URL used by the UI |
| `MLBD_CACHE_DIR` | API | `~/.cache/mlbd` | Catalogue cache directory |
| `SUPABASE_URL` | API | — | Supabase project URL (mirror) |
| `SUPABASE_SERVICE_ROLE_KEY` | API | — | Key used to write titles, pages and links |
| `SUPABASE_ANON_KEY` | API | — | Read-only key, used when no service key is set |
| `MLBD_SCRAPE_CACHE_SECONDS` | API | `21600` | How long a stored scrape is served when the live site fails |
| `PYTHON_VERSION` | deploy | — | Set to `3.11` on Render/Railway |

---

## Deploy

### API (Render / Railway / Fly.io)

The repo includes a `Dockerfile` that installs Python dependencies **and**
Chromium, so the Playwright fallback works on any container host.

**Render (recommended, one click):** see the step-by-step
[Render deployment guide](docs/DEPLOY-RENDER.md). The included `render.yaml`
creates a Docker web service with a `/health` check and a free-plan default.

> **Host IP matters.** MovieLinkBD challenges cloud/datacentre IPs, so on Render
> the API starts but every search returns `CloudflareBlocked`. Keep the API on a
> residential connection and expose it with
> `scripts/serve-public.ps1` — see
> [Cloud IPs are challenged](docs/DEPLOY-RENDER.md#cloud-ips-are-challenged-known-limitation).

Manual settings:

1. Push the repo and create a **Docker** web service.
2. Dockerfile: `./Dockerfile`
3. Start command (already in the image):
   `uvicorn api.main:app --host 0.0.0.0 --port $PORT`
4. Health check: `/health`
5. Persist `~/.cache/mlbd` (or set `MLBD_CACHE_DIR=/var/data/mlbd` with a
   mounted disk) so the index survives restarts. Without a disk, the API
   re-crawls on cold start (~15 s).

### Web (Vercel)

1. Import the `web/` folder as a project.
2. Add `NEXT_PUBLIC_API_BASE` = your deployed API URL.
3. Deploy.

---

## Notes and disclaimer

- This project returns **metadata only**. It does not host, proxy or play video.
- Stream and download URLs point at MovieLinkBD's own file hosts and may expire.
- The scraper does **not** bypass Cloudflare challenges or automate the site's
  ad/unlock flow. When the site blocks a request, the API returns an error.
- Adult titles are included in `category=all`; use `category=adult` or the
  `adult` flag to filter.
- Use responsibly and follow MovieLinkBD's terms and your local law. The authors
  are not affiliated with MovieLinkBD.

---

## Author

**Robiul Islam** — [@senseiCodeHub](https://github.com/senseiCodeHub)

- GitHub: <https://github.com/senseiCodeHub>
- Repository: <https://github.com/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper>

If this project is useful, please ⭐ **star the repo** — it genuinely helps:

<https://github.com/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper>

---

## License

[MIT](LICENSE) © 2026 Robiul Islam
