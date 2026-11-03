# MLBD Scraper — PHP edition

A self-contained PHP port of the FastAPI backend for shared hosting (Webuzo /
cPanel / XAMPP). It serves the JSON API **and** a small server-rendered UI, and
keeps the saved library in MySQL — no Node, Python or background worker needed.

The API routes and payloads mirror the Python service in `api/`, so the Next.js
UI in `web/` can talk to either one.

## Requirements

| | |
| --- | --- |
| PHP | 8.1+ (tested on 8.4) |
| Extensions | `curl`, `pdo_mysql`, `mbstring`, `json` |
| Database | MySQL 5.7+ / MariaDB 10.3+ (optional — without it you get live search and scrape, but no library) |
| Outbound HTTP | port 443 to `movielinkbd.tv` (Cloudflare may still challenge a datacenter IP) |

## Install

1. Copy the configuration and add your database credentials:

   ```bash
   cp config.example.php config.php
   ```

   `config.php` is git-ignored and denied by `.htaccess`.

2. Database credentials. The app reads them in this order:

   1. real environment variables (`MYSQL_HOST`, `MYSQL_PORT`, `MYSQL_USER`,
      `MYSQL_PASSWORD`, `MYSQL_DATABASE`);
   2. a `.env` file, looked up as `./.env` (inside `php/`, the copy that ships
      with the app) and then `../.env` (repo root, shared with the Python app).
      `app/bootstrap.php` parses it, and existing environment variables are
      never overridden — so a real env var set by the host always wins;
   3. the defaults in `config.php`.

   `php/.env` is git-ignored and `.htaccess` denies every `.env*` file, so it
   can be uploaded with the app. Keeping a copy **outside the document root** is
   still the safer option; without any `.env`, the `db` block in `config.php`
   works as the fallback.

   Use the **exact** database name from the hosting panel: Webuzo/cPanel prefix
   every database with the account name, so it looks like `lnfbojpx_mlbd`.
   Connecting to a bare `mlbd` silently creates a second, empty database and the
   Library tab looks empty.

3. Make the runtime storage writable:

   ```bash
   chmod -R 775 storage
   ```

   `storage/cache` holds the catalogue index, `storage/sync.json` the sync
   progress. Both live behind a deny-all `.htaccess`.

4. Point the document root at this folder (`php/`). The database and its three
   tables (`mlbd_titles`, `mlbd_scrapes`, `mlbd_links`) are created on first use,
   so an empty database is fine.

### Webuzo / cPanel

Upload `php/` to `public_html` (or set the domain's document root to it). If
`mod_rewrite` is enabled, `.htaccess` routes every request to `index.php`. When
rewriting is unavailable, links and API calls fall back to `?r=/path`, which
needs no server configuration at all.

### XAMPP (local)

Open `http://localhost/<project>/php/`. XAMPP's PHP usually ships **without a CA
store**, so HTTPS scrapes fail with `unable to get local issuer certificate`.
Download `cacert.pem` and point `scraper.ca_bundle` in `config.php` at it:

```php
'scraper' => [
    // ...
    'ca_bundle' => __DIR__ . '/storage/cacert.pem',
],
```

Alternatively set `curl.cainfo` in `php.ini`. Certificate verification is never
disabled.

## How the sync works

Shared hosting cannot keep a background process alive, so the sync is chunked:

1. `POST /library/sync` resets the state and marks a run as running.
2. The page calls `POST /library/sync/step` repeatedly. Each call scrapes
   `sync.titles_per_step` titles, writes them to MySQL and returns progress.
3. The UI keeps looping until `running` is false. Closing the tab pauses the run;
   pressing **Start sync** picks up exactly where it stopped, because the queue is
   rebuilt from the catalogue and the slugs already stored.

Titles with no playable source are stored with status `empty` (and can be retried
with *Retry no-stream titles*), real failures with `failed`. After
`sync.max_consecutive_failures` consecutive failures the run stops rather than
hammering a blocked site.

## Scheduled scraping (cron)

The library can refresh itself without anyone opening the site. Add one cron
job in Webuzo ▸ Cron Jobs (or `crontab -e`):

```cron
*/5 * * * * /usr/bin/php /home/USER/public_html/php/cli/sync.php --mode=refresh --refresh-hours=1 --minutes=3 >> /home/USER/public_html/php/storage/logs/cron.log 2>&1
```

On Webuzo the shipped rows are (PHP 8.2 path and document root filled in):

```cron
# full refresh drip — every 5 minutes, 3 minutes of scraping
*/5 * * * *  /usr/local/apps/php82/bin/php /home/lnfbojpx/public_html/mlbd-scraper/cli/sync.php --mode=refresh --refresh-hours=1 --minutes=3 >> /home/lnfbojpx/public_html/mlbd-scraper/storage/logs/cron.log 2>&1

# dead-link repair — hourly at :00, off the sync slots
0 * * * *    /usr/local/apps/php82/bin/php /home/lnfbojpx/public_html/mlbd-scraper/cli/repair.php --apply --concurrency=16 --minutes=20 >> /home/lnfbojpx/public_html/mlbd-scraper/storage/logs/repair.log 2>&1

# optional weekly: list orphan scrape/catalogue rows (preview, deletes nothing)
0 4 * * 0    /usr/local/apps/php82/bin/php /home/lnfbojpx/public_html/mlbd-scraper/cli/prune.php >> /home/lnfbojpx/public_html/mlbd-scraper/storage/logs/prune.log 2>&1
```

Why a 3-minute run every 5 minutes: one full pass is ~7.5 hours of scraping
(14,144 titles × ~1.9 s), so no single run can cover it. Each run scrapes for
three minutes, stops, and the next continues at the same point. Three minutes
in every five is 14.4 hours of scraping a day, which works out to **about two
complete passes every 24 hours**, and leaves the site two quiet minutes between
runs. Every field is `*`/`*/5`, which every panel validator accepts.

If your panel does accept hour ranges, the same amount of work can be packed
into two blocks (server time) — a morning and an evening pass:

```cron
*/5 6-15 * * *      … --minutes=4   # 06:00–15:59
*/5 18-23,0-3 * * * … --minutes=4   # 18:00–03:59 (skip the wrap form 18-3)
```

For a lighter drip instead, `0 */6 * * *` with `--minutes=25` still works (it
just takes days to come round again).

Adjust the PHP path (`which php`) and the absolute project path. `cli/sync.php`
runs the same job as the UI, one batch at a time, until the catalogue is fresh
or the time budget runs out, then exits.

### What "refresh" means

* A title counts as **fresh** for `sync.refresh_hours` (default 6). Titles that
  are older, failed, or were never scraped are due.
* Each invocation scrapes due titles **oldest first** (by last refresh time) for
  at most `--minutes` (default `sync.cron_minutes`, 25). If it runs out of time
  the job pauses with its progress saved, and the next cron run continues from
  there. Oldest-first ordering matters: with a budget smaller than one pass,
  walking the catalogue in its own order would keep re-scraping the first slice
  and never reach the tail.
* `--limit=N` caps how many catalogue titles a single run considers — handy for
  a first smoke test, e.g. `--limit=5`.
* `--mode=fresh` only picks up titles that were never scraped (first fill),
  `--mode=force` re-scrapes everything in scope, `--retry-empty` retries titles
  whose last scrape had no playable stream.

### Does a re-scrape duplicate rows?

No. `mlbd_scrapes.page_url` is the primary key with `ON DUPLICATE KEY UPDATE`,
and before writing, rows for the same **slug** under a different page URL (the
site hands out a new canonical id per request) are deleted, together with their
`mlbd_links` rows. A refresh therefore replaces the stored snapshot and its
links in place — the library keeps one row per title, with `updated_at` moving
forward. Catalogue titles are upserted the same way on `slug`.

### Scale, honestly

The catalogue holds ~14,000 titles and one detail scrape (page + stream probe)
takes ~1.9 s on a normal connection: **one complete pass is ~7.5 hours** of
uninterrupted scraping, and two passes a day is ~15 hours. That is why the
schedule above uses long blocks of short runs rather than one big run. A
25-minute run covers roughly 750 titles.

Running several PHP processes at once is not supported: `storage/sync.lock` plus
the shared state make a second invocation skip itself.

### What the UI shows about cron

Every `cli/sync.php` invocation appends one record to `storage/cron-runs.json`
(kept to the last 40 runs, counters are per-run deltas). The library page reads
it and shows, under the sync console:

* **updated today / checked today / failed today / runs today**
* the last pass with its outcome (`paused, resumes next run`, `finished`)
* the six most recent runs, newest first

The search page carries a `library updated …` stamp (`updated_24h` come from the
database), and every ledger row and detail page shows its own last refresh as
"12m ago" with the exact UTC time in the tooltip. The panel stays hidden until
the first run is recorded, so a fresh install shows nothing rather than zeros.

### Repairing expired stream links

MovieLinkBD hands out opaque per-request stream tokens (`https://fsldownload.com/p/…`)
that expire after a few hours, so a full refresh of every detail page is mostly
wasted work. `cli/repair.php` checks the stored links instead and only re-scrapes
the titles that actually broke:

```bash
php cli/repair.php                    # preview: count dead links
php cli/repair.php --apply            # re-scrape the broken titles
php cli/repair.php --apply --concurrency=24 --limit=100 --minutes=20
php cli/repair.php --sample=200       # quick sanity check on a random slice
php cli/repair.php --apply --all-links # probe every link, not one per title
```

* It probes one stream per title with a ranged GET (the write callback stops
  after 16 KB, so a server ignoring `Range` cannot stream a whole movie). All the
  URLs of one snapshot are issued in the same scrape, so they expire together -
  one probe answers for the title. `--all-links` probes every stored stream.
* A 2xx/3xx (or 416) answer means the token is alive; 401/403/404/410 means dead.
  A transport failure (status 0: DNS, TLS, reset) is reported as *unreachable*
  and never triggers a re-scrape, so a local CA problem cannot rewrite the
  whole library.
* Repaired titles go through the normal `Scraper` + `saveScrape()` path, so the
  snapshot and all its links are replaced in place and `updated_at` moves.
* Measured on the real library: ~130 ms per link at concurrency 12, so a pass
  over 14k titles is **~30 minutes** at 12 and **~15 minutes** at 24 
  versus ~7.5 hours for a full refresh.
* Each run is recorded in `storage/cron-runs.json` with `mode: repair`, so the
  library page shows it in the cron panel like any other run.

A useful split: dead-link repair every hour, and the full `--mode=refresh` pass
twice a day for new episodes, posters and metadata.

### Running without SSH

No terminal on the host? Everything above is a CLI script, but the Webuzo/Cron
panel runs them for you, and every run reports back in the library page.

1. Cron panel → add a job, choose the PHP binary the working sync job already
   uses (`/usr/local/apps/php82/bin/php` here).
2. Command (one line; `%` must be escaped as `\%` in crontab fields, but the
   schedule goes in the panel's own fields, not in the command):

   ```bash
   cd /home/USER/public_html && php cli/repair.php --apply \
     --concurrency=24 --minutes=25 >> storage/logs/repair.log 2>&1
   ```

3. Set the schedule to *every hour*. A full pass has to probe all ~14k stored
   links first (~20 min at concurrency 24) and only then spends the `--minutes`
   budget re-scraping what broke, so a 10-minute slot is too tight. `flock`
   inside the script makes a second run exit immediately instead of overlapping.
4. Open `/saved` → **cron · Scheduled runs**: each pass appears as
   `repair · all · N checked · N dead`, and the last-pass line shows it above
   the ledger. A preview run (`mode=repair`, `category=preview`) is logged too,
   so you can confirm the checker works without changing any data.
5. Logs are plain text in `storage/logs/` (blocked for the web by
   `storage/.htaccess`). Download them from File Manager if you need the detail.

The same trick runs a one-off task: add the job with `* * * * *`, wait for it to
fire once, read the result in the cron panel, then delete the job.

### Removing retired titles

`mlbd_titles` and `mlbd_scrapes` never drop a title by themselves: when the site
retires a page, the stored row simply stops being refreshable. Clean those up
with the prune command:

```bash
php cli/prune.php                        # preview: what would go
php cli/prune.php --apply                # back up, then delete
php cli/prune.php --apply --keep=5       # keep only 5 prune backups
```

It forces a fresh catalogue, then removes every scrape row and catalogue row
whose slug the site no longer lists. Before deleting anything it writes the full
rows (snapshots, links and titles) to `storage/backups/prune-YYYYmmdd-HHmmss.json`
and refuses to run if the backup cannot be written or if the catalogue came back
empty. It takes the same `sync.lock` as the scraper, so it never runs under a
sync.

### Logs

Every run appends to `storage/logs/sync.log` (and to the file cron redirects to),
including a start line, a progress line every 30 seconds, and the final counters.
Exit code is `0` for done/paused, `1` when the run failed (for example after the
consecutive-failure breaker trips because the host IP is Cloudflare-challenged).

## Routes

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/health` | liveness probe |
| GET/POST | `/scrape?url=&probe=` | scrape one detail page (falls back to a fresh stored snapshot) |
| POST | `/scrape/save` | same, forcing the library write |
| POST | `/compare` | live scrape vs stored snapshot, with a structural diff |
| GET | `/search?q=&category=&limit=&offset=&scope=` | `catalog` (whole index), `site` (25 results), `db` (MySQL mirror) |
| GET | `/catalog/status` | index size and version |
| POST | `/catalog/sync?force=1` | push the index into MySQL |
| POST | `/catalog/refresh` | re-crawl the suggest shards |
| GET | `/db/status` | MySQL connectivity and row counts |
| GET | `/library` | search saved scrapes |
| GET | `/library/{page_url}` | one saved scrape with episodes, sources and links |
| GET | `/library/stats` | library totals and sync state |
| POST | `/library/sync` | start (or report) a run |
| POST | `/library/sync/step` | process the next batch |
| POST | `/library/sync/status` | progress snapshot |
| POST | `/library/sync/cancel` | stop after the current batch |

Pages: `/` (search), `/view?url=` (live scrape), `/saved` (library + sync
controls), `/saved/title?url=` (stored snapshot).

Responses use the same envelope as the Python API:

```json
{ "ok": true, "data": { }, "error": null, "error_type": null }
```

## Layout

```
php/
├── index.php              front controller (API + pages)
├── config.example.php     configuration template
├── app/
│   ├── bootstrap.php      config, autoloader, storage, error handling
│   ├── helpers.php        config/URL/JSON/formatting helpers
│   ├── Http/              HttpClient, Api dispatcher, Web pages
│   ├── Library/           chunked sync job and its state file
│   ├── Scraper/           catalogue index, detail scraper, HLS, diff
│   └── Store/             PDO connection + MySQL library
├── views/                 layout and pages
├── assets/                style.css, app.js
└── storage/               cache, logs, sync state (writable, not public)
```

## Security notes

* `config.php`, `app/` and `storage/` are denied by `.htaccess`; keep those files
  in place if you change hosting.
* There is no login. The API can write to your database, so protect the whole
  folder (HTTP basic auth or an IP allow-list in the panel) before exposing it.
* Cloudflare is treated as a stop signal. The port does not try to bypass the
  challenge — run the scrape from a network the site does not challenge, or serve
  the stored snapshot.

## Differences from the Python service

* No headless browser: scraping uses the inline player payload and a plain-HTML
  fallback. `BrowserError` does not exist here.
* Sync is sequential and browser-driven, not an asyncio background task.
* SQLite is gone; MySQL is the only store.
* Library stats report `size_bytes: 0` (shared hosts rarely allow table-size
  queries) — the Python service behaves the same way.
