# Supabase mirror (local scraper → database → any UI)

MovieLinkBD serves a Cloudflare challenge to cloud and datacentre IPs, so the
scraper only works from a residential connection. This layer removes the need to
keep that connection on for every visitor: the local scraper writes everything
it learns into Supabase, and any API instance (including one on Render/Vercel)
can answer from the mirror.

```
   ┌──────────────────────────┐        ┌──────────────────────────┐
   │ Local scraper (this PC)  │        │ Deployed API (any host)  │
   │  allowed by MovieLinkBD  │        │  cannot reach the site   │
   └────────────┬─────────────┘        └────────────┬─────────────┘
                │ write (service role)              │ read
                └──────────────┬────────────────────┘
                               ▼
                    ┌─────────────────────┐
                    │ Supabase Postgres   │
                    │  mlbd_titles        │
                    │  mlbd_scrapes       │
                    │  mlbd_links         │
                    └─────────────────────┘
```

---

## Tables

All three tables have RLS enabled. `anon` may **read** (the mirrored metadata
is public); everything else is restricted to the **service role** key.

| Table | Key | Holds |
| --- | --- | --- |
| `mlbd_titles` | `slug` | One row per catalogue title: title, url, poster, kind, category, year, adult, quality, language, rating, `source_version` |
| `mlbd_scrapes` | `page_url` | One row per scraped detail page: title, poster, counts, the full JSON `payload`, `scraped_at`, plus `requested_url` (the URL the caller asked for — the site redirects slugs to token URLs) |
| `mlbd_links` | id | Every stream and download link, flattened: `episode_label`, `season`, `episode_number`, provider, quality, audio, kind, codec, `size_bytes`, `stream_url`, `download_url`, `is_best` |

`mlbd_links` rows are replaced on every scrape of the same page, so the table
always reflects the latest snapshot.

---

## Configuration

Put these in `.env` (gitignored) or in the environment:

| Variable | Required | Purpose |
| --- | --- | --- |
| `SUPABASE_URL` | yes | `https://<project-ref>.supabase.co` |
| `SUPABASE_SERVICE_ROLE_KEY` | for writes | Dashboard → Project Settings → API keys → `service_role`. Never commit or share it. |
| `SUPABASE_ANON_KEY` | for reads | Read-only fallback when no service key is set (`?scope=db`, cached scrapes). |
| `MLBD_SCRAPE_CACHE_SECONDS` | no | How long a stored scrape is served when the live site fails (default `21600`, 6 h). |

`SUPABASE_KEY` is accepted as an alias for either key.

---

## Pushing data

The API must be running, or use the CLI directly (no server needed):

```bash
python -m scraper.store sync      # crawl the catalogue and upsert every title
python -m scraper.store stats     # row counts + latest stored page
```

Through the API:

```bash
curl -X POST http://127.0.0.1:8000/catalog/sync        # uses the local cache
curl -X POST "http://127.0.0.1:8000/catalog/sync?force=true"  # re-crawl first
curl http://127.0.0.1:8000/db/status
```

Every successful `/scrape` writes the page and all of its links automatically,
so a normal browsing session fills `mlbd_scrapes` and `mlbd_links`.

On Windows, a scheduled task can keep the mirror fresh:

```powershell
schtasks /create /tn "MLBD catalog sync" /sc hourly /tr "python -m scraper.store sync"
```

---

## Reading from the mirror

| Call | Behaviour |
| --- | --- |
| `GET /search?scope=db&q=slime` | Searches `mlbd_titles` directly |
| `GET /search?q=slime` | Tries the live catalogue; if MovieLinkBD blocks this host, answers from `mlbd_titles` and sets `"source": "db"` |
| `GET /scrape?url=…` | Tries the live page; if that fails and a snapshot newer than `MLBD_SCRAPE_CACHE_SECONDS` exists for that URL (or its `requested_url`), returns it with `"cached": true` and `"cache_age_seconds"` |

A cached scrape keeps the same shape as a live one, so the UI needs no special
handling beyond an optional "cached" badge.

---

## Deploying the API against the mirror

On Render (or any host troublemaker-free host), set `SUPABASE_URL` and
`SUPABASE_SERVICE_ROLE_KEY` as environment variables. That instance can then
serve searches, cached pages and stored links **without reaching MovieLinkBD at
all** — but it cannot scrape anything new; that still needs the local machine.

---

## Notes

- The mirror stores metadata and links only; no media is hosted.
- Cable/token URLs expire, which is why `cached` responses carry an age.
- Crawling 1,296 shards takes ~20 s and about 36 batched upserts (~14k rows).
