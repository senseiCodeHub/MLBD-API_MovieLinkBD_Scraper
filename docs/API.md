# MLBD Scraper — API Reference

FastAPI service that searches the **whole MovieLinkBD catalogue** and returns
**every episode's stream URL**, server, quality, audio track and download link
for any title.

- **Base URL (local):** `http://localhost:8000`
- **Interactive schema:** [`/docs`](http://localhost:8000/docs) (Swagger UI)
- **OpenAPI JSON:** [`/openapi.json`](http://localhost:8000/openapi.json)
- **Source:** <https://github.com/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper>

> The API only returns scraped metadata. It does not proxy, host, or play video,
> and it never automates MovieLinkBD's ad/unlock bridge (`/continue/...`), ad
> iframes, or click scripts.

---

## Contents

- [Response envelope](#response-envelope)
- [GET /health](#get-health)
- [GET /search](#get-search)
- [GET /scrape](#get-scrape)
- [POST /scrape](#post-scrape)
- [GET /catalog/status](#get-catalogstatus)
- [POST /catalog/refresh](#post-catalogrefresh)
- [Data models](#data-models)
- [Errors](#errors)
- [Catalogue cache](#catalogue-cache)
- [Quick start](#quick-start)
- [Limits and behaviour](#limits-and-behaviour)

---

## Response envelope

Every data endpoint returns the same wrapper:

```json
{
  "ok": true,
  "data": { },
  "error": null,
  "error_type": null
}
```

| Field | Type | Description |
| --- | --- | --- |
| `ok` | boolean | `true` when the request produced data |
| `data` | object \| null | Endpoint-specific payload |
| `error` | string \| null | Human-readable failure message |
| `error_type` | string \| null | Machine-readable failure code (see [Errors](#errors)) |

A failed scrape or search still returns **HTTP 200** with `ok: false`. Only
`POST /catalog/refresh` can return a non-2xx status (`503`) when the crawl
fails.

---

## GET /health

Liveness probe. No parameters.

```bash
curl http://localhost:8000/health
```

```json
{ "status": "ok" }
```

---

## GET /search

Search the whole-catalogue index, or fall back to the site's own 25-result
search page.

### Query parameters

| Param | Type | Default | Description |
| --- | --- | --- | --- |
| `q` | string | `""` | Title fragment. Empty string browses the selected category. Max 120 chars. |
| `category` | enum | `all` | `all`, `movie`, `series`, `drama`, `anime`, `adult` |
| `limit` | integer | `24` | Page size, `1`–`100` |
| `offset` | integer | `0` | Number of matches to skip |
| `scope` | enum | `catalog` | `catalog` = whole-server index; `site` = MovieLinkBD's own `/search` page (25 results, `q` required) |

`category=adult` maps to the site's `download18plus` type. `all` includes adult
titles; filter them out client-side via each result's `adult` flag if needed.

### Example

```bash
curl "http://localhost:8000/search?q=slime&category=anime&limit=5"
```

```json
{
  "ok": true,
  "data": {
    "query": "slime",
    "category": "anime",
    "results": [
      {
        "title": "That Time I Got Reincarnated as a Slime",
        "url": "https://movielinkbd.tv/anime/that-time-i-got-reincarnated-as-a-slime",
        "slug": "that-time-i-got-reincarnated-as-a-slime",
        "poster": "https://image.tmdb.org/t/p/original/….jpg",
        "type": "anime",
        "category": "anime",
        "year": "2018",
        "adult": false,
        "quality": null,
        "language": null,
        "rating": null
      }
    ],
    "total": 3,
    "limit": 5,
    "offset": 0,
    "source": "catalog",
    "catalog_count": 14109,
    "searched_at": "2026-10-29T02:03:56.201000Z"
  },
  "error": null,
  "error_type": null
}
```

### Notes

- Results are ranked by exact title, prefix, substring, then token match;
  ties break on newest year and title.
- `total` is the full match count for the query + category, so
  `results.length < total` means more pages exist.
- `scope=site` requires a non-empty `q` and ignores `category`/`offset`
  (it returns up to `limit` rows from the site's 25-result page).
- Pass a result's `url` to `/scrape` to get its streams.

---

## GET /scrape

Extract every episode and every source from a MovieLinkBD detail page.

### Query parameters

| Param | Type | Default | Description |
| --- | --- | --- | --- |
| `url` | string | **required** | Detail page URL, e.g. `https://movielinkbd.tv/series/heart-beat-2026` |
| `probe` | boolean | `true` | Send a ranged `HEAD`/`GET` per stream URL to fill `size_bytes` and `content_type`. Set `false` for a faster response. |

### Example

```bash
curl "http://localhost:8000/scrape?url=https%3A%2F%2Fmovielinkbd.tv%2Fseries%2Fheart-beat-2026&probe=false"
```

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
            "audio_languages": ["Hindi", "Tamil", "Telugu"],
            "type": "mkv",
            "name": "MovieLinkBD.com - ….mkv",
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
          {
            "quality": "1080p",
            "bandwidth": null,
            "url": "https://fsldownload.com/p/<token>"
          }
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
    "scraped_at": "2026-10-29T02:03:59.000000Z"
  },
  "error": null,
  "error_type": null
}
```

### Field notes

- `episodes[]` is the primary view. Each entry carries **all** sources for that
  episode. A movie has one `Movie` entry; a series/anime/drama has one entry per
  episode, or one `Series` entry for a season-pack page.
- `label` is normalised: `S03E01` when a season is known, `E04` when it is not,
  and `S03 Ep 45-48` for a range/pack entry. `number`, `season`, `range_start`
  and `range_end` keep the raw payload values.
- `sources[].stream_url` is the playable file URL; `type` is `mkv`, `mp4`,
  `webm`, `hls` or `auto`.
- `sources[].download_url` may be a direct file
  (`https://fsldownload.com/d/<token>`) or the site's `/file/<token>` download
  page, which has its own unlock/ad step. Relative values are resolved against
  the detail page; the API never automates that gate.
- `size_bytes` / `content_type` come from the optional media probe and are
  `null` when the host does not advertise them.
- `sources[]` is a flat, de-duplicated compatibility view across all episodes.
- `downloads[]` is the flat download view, labelled with its episode.
- `category` is derived from the page path; `/anime/` wins even when the
  payload says `content_type: "series"`. `adult` is `true` for
  `/download18plus/` pages.
- `page_url` is the final URL after redirects; detail pages use short-lived
  `mKs_…` slugs, so always keep the canonical catalogue URL as your key.

### How it works

MovieLinkBD embeds the full player state in the page:

```html
<script id="mlbdInlinePlayerData" type="application/json"
        data-s="<hex seed>" data-r="600">…base64…</script>
```

The scraper fetches the page with browser-like headers, decodes the body
(base64 + XOR against an LCG keystream), and reads `episodes[]` / `sources[]`
from the JSON. It never clicks **UNLOCK & WATCH NOW**, never loads an ad iframe,
and never follows `/continue/...`. If the payload is missing (Cloudflare or a
new page type), it falls back to the headless-browser DOM path.

---

## POST /scrape

JSON variant of `GET /scrape`, useful for long URLs.

```bash
curl -X POST http://localhost:8000/scrape \
  -H "Content-Type: application/json" \
  -d '{"url": "https://movielinkbd.tv/movie/kaliyugam", "probe": false}'
```

| Body field | Type | Default | Description |
| --- | --- | --- | --- |
| `url` | string | **required** | Detail page URL |
| `probe` | boolean | `true` | Probe media URLs for size/content-type |

The response is identical to [`GET /scrape`](#get-scrape).

---

## GET /catalog/status

Report whether the whole-catalogue index is loaded.

```bash
curl http://localhost:8000/catalog/status
```

```json
{
  "loaded": true,
  "version": "20261028193426-d729e311",
  "count": 14109,
  "types": {
    "movie": 9746,
    "series": 2749,
    "drama": 918,
    "anime": 389,
    "download18plus": 307
  },
  "age_seconds": 412
}
```

Before the first crawl finishes it returns `{"loaded": false}`. The index warms
in the background on API startup.

---

## POST /catalog/refresh

Force a fresh crawl of the site's search-suggest shards and return the new
status.

```bash
curl -X POST http://localhost:8000/catalog/refresh
```

```json
{
  "ok": true,
  "count": 14109,
  "loaded": true,
  "version": "20261028193426-d729e311",
  "types": { "movie": 9746, "series": 2749, "drama": 918, "anime": 389, "download18plus": 307 },
  "age_seconds": 0
}
```

Returns **HTTP 503** with `{"detail": "..."}` if the crawl fails or exceeds the
120-second search budget.

---

## Data models

### `CatalogResults`

| Field | Type | Description |
| --- | --- | --- |
| `query` | string | Echo of the search query |
| `category` | enum | Applied category filter |
| `results` | `CatalogItem[]` | Page of matches |
| `total` | integer | Total matches across all pages |
| `limit` | integer | Page size |
| `offset` | integer | Applied offset |
| `source` | `catalog` \| `site` | Which search path answered |
| `catalog_count` | integer \| null | Titles in the loaded index (`null` for `scope=site`) |
| `searched_at` | ISO 8601 | Server timestamp |

### `CatalogItem`

| Field | Type | Description |
| --- | --- | --- |
| `title` | string | Title as shown on MovieLinkBD |
| `url` | string | Canonical detail page URL (feed this to `/scrape`) |
| `slug` | string | Canonical slug |
| `poster` | string \| null | Poster image URL |
| `type` | string | `movie`, `series`, `drama`, `anime`, `download18plus` |
| `category` | enum | Normalised category (`download18plus` → `adult`) |
| `year` | string \| null | Release year |
| `adult` | boolean | Adult content flag |
| `quality`, `language`, `rating` | string \| null | Present for shape parity with the site search; usually `null` in catalogue results |

### `ScrapeResult`

| Field | Type | Description |
| --- | --- | --- |
| `page_url` | string | Final detail page URL |
| `title` | string \| null | Page title |
| `poster` | string \| null | Poster image URL |
| `content_type` | string \| null | Payload's own type (`movie`, `series`, …) |
| `category` | enum | Normalised category |
| `adult` | boolean | Adult content flag |
| `completed` | boolean \| null | Whether the payload marks the title complete |
| `episodes` | `Episode[]` | Per-episode entries |
| `sources` | `ScrapeSource[]` | Flat compatibility view of all sources |
| `downloads` | `ScrapeDownload[]` | Flat list of download links |
| `scraped_at` | ISO 8601 | Server timestamp |

### `Episode`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string | Stable id within the result |
| `label` | string | Normalised label (`S03E01`, `E04`, `S03 Ep 45-48`) |
| `number` | integer \| null | Raw episode number |
| `season` | integer \| null | Raw season number |
| `range_start`, `range_end` | integer \| null | Raw pack range |
| `kind` | string \| null | Payload kind (`episode`, `Movie`, `Series`, …) |
| `sources` | `EpisodeSource[]` | All servers/qualities/audio for this episode |

### `EpisodeSource`

| Field | Type | Description |
| --- | --- | --- |
| `id` | string \| null | Payload source id |
| `provider` | string \| null | Server/provider name |
| `quality` | string \| null | e.g. `1080p`, `720p`, `480p` |
| `quality_label` | string \| null | Payload's display label, if any |
| `audio` | string \| null | Primary audio language |
| `audio_languages` | string[] | All available audio tracks |
| `type` | enum | `mkv`, `mp4`, `webm`, `hls`, `auto` |
| `name` | string \| null | Original file name |
| `codec` | string \| null | e.g. `h264`, `hevc` |
| `hevc` | boolean \| null | HEVC flag from the payload |
| `is_best` | boolean | Payload's preferred source flag |
| `stream_url` | string | Direct playable URL |
| `download_url` | string \| null | Download file or `/file/<token>` page |
| `size_bytes` | integer \| null | Probed size |
| `content_type` | string \| null | Probed MIME type |

### `ScrapeSource` (compatibility view)

| Field | Type | Description |
| --- | --- | --- |
| `server_name` | string \| null | Provider name |
| `type` | enum | Stream type |
| `master_url` | string | First stream URL for the source |
| `variants` | `{ quality, bandwidth, url }[]` | Quality variants |
| `size_bytes` | integer \| null | Probed size |
| `content_type` | string \| null | Probed MIME type |

### `ScrapeDownload`

| Field | Type | Description |
| --- | --- | --- |
| `label` | string | e.g. `1080p • Hindi • 916 MB` |
| `quality` | string \| null | Quality |
| `size` | string \| null | Human-readable size |
| `size_bytes` | integer \| null | Exact size |
| `episode` | string \| null | Episode label |
| `url` | string | Download URL |
| `type` | enum \| null | File type |
| `audio` | string \| null | Audio language |

---

## Errors

| `error_type` | Meaning | Typical cause |
| --- | --- | --- |
| `CloudflareBlocked` | The site served a challenge page | Rate limiting / bot protection |
| `NoStreamFound` | Page loaded but no streams were found | New page layout or player never started |
| `BrowserError` | Headless browser could not launch/reach the page | Missing Chromium, network failure |
| `Timeout` | Scrape or search exceeded its budget | Slow site (scrape budget: 150 s) |
| `ScraperError` | Unexpected scraper/parse failure, or empty `scope=site` query | Bad input or site change |

Cloudflare challenges are **not** bypassed. The browser fallback is attempted
first, then the API reports the error.

---

## Catalogue cache

The site's `/search?q=` page is capped at 25 results and ignores pagination, so
the API builds its own index from the site's search-suggest files:

1. `GET /search-suggest/manifest` → current version + shard count.
2. Crawl every `[a-z0-9]{2}` shard at
   `/assets/search-suggest/v<version>/<key>.json` (concurrently).
3. Recover rows truncated by the 240-row shard cap from
   `/api/search-suggest-live.php?k=<key>`, and merge the popular feed.

The merged index is de-duplicated by `type:slug`, held in memory, and cached on
disk:

- Default path: `~/.cache/mlbd/catalog-<version>.json`
- Override directory: `MLBD_CACHE_DIR`
- TTL: 6 hours (a new manifest version invalidates the cache immediately)
- First cold crawl: ~10–20 s for ~14k titles; cached loads are instant

---

## Quick start

### Python

```python
import httpx

API = "http://localhost:8000"

with httpx.Client(base_url=API, timeout=180) as client:
    found = client.get("/search", params={"q": "slime", "category": "anime"}).json()
    title = found["data"]["results"][0]

    detail = client.get("/scrape", params={"url": title["url"], "probe": False}).json()
    for episode in detail["data"]["episodes"]:
        print(episode["label"])
        for source in episode["sources"]:
            print(" ", source["quality"], source["audio"], source["stream_url"])
```

### JavaScript

```js
const API = "http://localhost:8000";

const found = await fetch(
  `${API}/search?q=${encodeURIComponent("doctor x")}&category=drama`
).then((r) => r.json());

const detail = await fetch(
  `${API}/scrape?url=${encodeURIComponent(found.data.results[0].url)}&probe=false`
).then((r) => r.json());

console.log(detail.data.episodes.map((e) => e.label));
```

### cURL

```bash
curl "http://localhost:8000/search?q=hello&limit=5"
curl "http://localhost:8000/scrape?url=https%3A%2F%2Fmovielinkbd.tv%2Fseries%2Fhello-bachhon-2026&probe=false"
```

---

## Limits and behaviour

- **CORS:** all origins, methods and headers are allowed.
- **Search:** instant after the catalogue is warm; `scope=site` makes a live
  request to MovieLinkBD and can be slower.
- **Scrape:** typically 2–6 s with `probe=true` (one HEAD per source, bounded
  concurrency, 8 s per probe); much faster with `probe=false`.
- **Timeouts:** scrape 150 s, search 120 s.
- **No video playback or proxying:** stream URLs point at the site's own file
  hosts and may expire.
- **Adult titles** are included in `category=all`; use `category=adult` to
  browse them or filter on `adult`.
- Use responsibly and respect MovieLinkBD's terms and local law.

---

Built and maintained by **Robiul Islam** ([@senseiCodeHub](https://github.com/senseiCodeHub)).
If this API is useful to you, please ⭐ star the repo:
<https://github.com/senseiCodeHub/MLBD-API_MovieLinkBD_Scraper>
