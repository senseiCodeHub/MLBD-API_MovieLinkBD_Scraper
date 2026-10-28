# MLBD Stream Scraper — Full Stack

> **Note:** This is a historical deployment note. The current, up-to-date guide
> is the root [README.md](README.md); the API reference is
> [docs/API.md](docs/API.md).

**Important:** You cannot run the Playwright scraper on Vercel alone. Vercel serverless functions have no Chromium binary. Use this split:

| Part | Where it runs | What it does |
|------|--------------|--------------|
| Frontend (Next.js) | **Vercel** | Search UI + JSON result display |
| Backend (FastAPI + Playwright) | **Render / Railway / Fly.io** | Headless Chromium scraping + returns JSON |

## Files

- `scraper/` — core Python scraping engine (unchanged)
- `api/main.py` — FastAPI wrapper around `scraper.scrape_streams`
- `web/` — Next.js 14 frontend (Vercel-ready, `vercel.json` included)
- `render.yaml` — Render deploy config for the API

## Deploy

### 1. Backend (Render / Railway / Fly.io)
Deploy `api/main.py` with `uvicorn api.main:app --host 0.0.0.0 --port $PORT`.
Requires: Python 3.11+, `pip install -r requirements.txt`, `playwright install chromium`.

Example Render config is already in `render.yaml`.

Get your backend URL, e.g. `https://mlbd-scraper-api.onrender.com`.

### 2. Frontend (Vercel)
1. Push this repo to GitHub.
2. In Vercel, import the `web/` folder (or root with `web` as project root).
3. Add Environment Variable:
   - `NEXT_PUBLIC_API_BASE` = `https://your-backend-url.onrender.com`
4. Deploy.

Visit your Vercel URL → enter a `movielinkbd.one` detail page URL → see scraped JSON.

## Local dev

```bash
# Terminal 1 — API
cd api
pip install fastapi uvicorn
uvicorn main:app --reload --port 8000

# Terminal 2 — Web
cd web
npm install
NEXT_PUBLIC_API_BASE=http://localhost:8000 npm run dev
# open http://localhost:3000
```

## What the UI does

- Search box: paste a movielinkbd.one detail page URL
- Calls backend `/scrape?url=...`
- Shows result status (Success / CloudflareBlocked / NoStreamFound / Timeout)
- Pretty-prints full JSON: `page_url`, `title`, `sources[]` with `server_name`, `type`, `master_url`, `variants`
- **No video playback** — JSON only

## Notes

- Cloudflare challenges are never bypassed; they return an error status.
- The scraper engine stays in `scraper/` and is unchanged from your original spec.
