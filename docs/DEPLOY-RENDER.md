# Deploy the API on Render

This guide deploys the **FastAPI + Playwright scraper** (`api/` + `scraper/`) to
[Render](https://render.com). The Next.js UI (`web/`) is deployed separately —
usually on Vercel.

> Why not Vercel for the API? Vercel's serverless functions do not ship
> Chromium, have short request limits and no persistent disk. Render runs the
> API as a normal container, which the scraper needs.

---

## What you get

| | |
| --- | --- |
| Service | `https://<service-name>.onrender.com` |
| Health check | `GET /health` |
| Swagger UI | `https://<service-name>.onrender.com/docs` |
| Runtime | Docker (`Dockerfile` at the repo root) |
| Plan | Free by default (upgrade for a persistent disk) |
| Region | Singapore (change in `render.yaml` if you prefer) |

The Docker image installs Python dependencies **and** Chromium with its system
libraries, so the Playwright fallback works.

---

## Prerequisites

1. The repo is pushed to GitHub.
2. A Render account: <https://dashboard.render.com/register>.
3. `render.yaml` and `Dockerfile` are committed (they are in this repo).

---

## Option A — Blueprint (recommended)

1. Open <https://dashboard.render.com/blueprints>.
2. Click **New Blueprint Instance**.
3. Connect GitHub and select
   `senseiCodeHub/MLBD-API_MovieLinkBD_Scraper`.
4. Render reads `render.yaml` and shows the `mlbd-scraper-api` service.
5. Click **Apply**.
6. Wait for the first Docker build (usually 5–10 minutes; later builds are
   cached).

That's it — the service starts with:

```
uvicorn api.main:app --host 0.0.0.0 --port $PORT
```

and the health check at `/health`.

---

## Option B — Manual Web Service

Use this if you do not want Render to read `render.yaml`.

1. Dashboard → **New +** → **Web Service**.
2. Connect the repository.
3. Configure:

| Setting | Value |
| --- | --- |
| Name | `mlbd-scraper-api` |
| Language / Runtime | **Docker** |
| Region | Singapore (or nearest) |
| Branch | `main` |
| Root Directory | *(leave empty)* |
| Dockerfile Path | `./Dockerfile` |
| Docker Build Context | `.` |
| Health Check Path | `/health` |
| Instance Type | Free |

4. Add an environment variable:

| Key | Value |
| --- | --- |
| `MLBD_CACHE_DIR` | `/var/data/mlbd` |

5. Click **Create Web Service**.

> Do **not** set `PORT`; Render injects it and the Dockerfile uses `${PORT}`.

---

## Verify the deployment

Replace `<service>` with your Render service hostname.

```bash
# 1. Liveness
curl https://<service>.onrender.com/health
# {"status":"ok"}

# 2. Catalogue status (may be {"loaded":false} for the first ~20s)
curl https://<service>.onrender.com/catalog/status

# 3. Search
curl "https://<service>.onrender.com/search?q=slime&category=anime&limit=3"

# 4. Scrape one title (probe=false is faster)
curl "https://<service>.onrender.com/scrape?url=https%3A%2F%2Fmovielinkbd.tv%2Fanime%2Fthat-time-i-got-reincarnated-as-a-slime&probe=false"
```

Open `https://<service>.onrender.com/docs` for the interactive API.

### First request is slow

The free plan spins the service down after ~15 minutes of inactivity. The next
request cold-starts the container (30–60 seconds) and re-crawls the catalogue if
there is no persistent disk. This is normal on the free plan.

---

## Connect the Vercel frontend

1. In Vercel, import **only** the `web` folder:
   - **Root Directory:** `web`
   - **Framework Preset:** Next.js
2. Add an environment variable:

```bash
NEXT_PUBLIC_API_BASE=https://<service>.onrender.com
```

3. Redeploy. `NEXT_PUBLIC_*` values are baked in at build time, so changing the
   variable requires a new deployment.

4. Open the site and search for a title. If you see
   **"Couldn't reach the scraper API"**, check the URL and that the Render
   service is awake.

CORS is already open (`allow_origins=["*"]`), so no extra Render setting is
needed.

---

## Persistent catalogue cache (paid plans)

Free instances have an ephemeral filesystem: the index is rebuilt after every
deploy or restart. On **Starter or higher**, add a disk:

1. Render Dashboard → your service → **Disks** → **Add Disk**.
2. Name: `mlbd-cache`
3. Mount Path: `/var/data`
4. Size: `1 GB`

`MLBD_CACHE_DIR=/var/data/mlbd` is already set, so the cache is written to the
disk automatically. You can also declare it in `render.yaml`:

```yaml
    disk:
      name: mlbd-cache
      mountPath: /var/data
      sizeGB: 1
```

A cached index loads instantly; a cold crawl takes ~10–20 seconds.

---

## Free plan limits to know

| Limit | Effect |
| --- | --- |
| Spins down after ~15 min idle | 30–60s cold start on the next request |
| 512 MB RAM | Chromium may be tight; the normal `httpx` path is fine |
| No persistent disk | Catalogue re-crawls after restarts/deploys |
| Monthly instance hours | One always-on free service is within the free allowance |

If the Playwright fallback matters or you want no cold starts, upgrade to a paid
instance (more RAM) and add the disk.

---

## Troubleshooting

### Build fails while installing Chromium

Check the build logs. The Dockerfile uses
`playwright install --with-deps chromium`, which needs network access to
Playwright's CDN and Debian packages. Re-deploy once; transient CDN failures are
common.

### `BrowserError` or Chromium crashes

Usually memory on the free plan. Confirm the normal path works:

```bash
curl "https://<service>.onrender.com/scrape?url=...&probe=false"
```

If only the browser fallback fails, upgrade the instance or accept that rare
fallback failures return an error instead of a wrong result.

### `CloudflareBlocked`

MovieLinkBD served a challenge to the datacenter IP. The API deliberately does
not bypass it. Retry later; a different region/instance may help.

If **every** request fails this way and `/catalog/status` stays `{"loaded":
false}`, the site is challenging the host's IP range rather than a random
challenge — see [Cloud IPs are challenged](#cloud-ips-are-challenged-known-limitation).

### 502 / request timeout

Free cold start or a long scrape. Retry, and use `probe=false` for faster
responses. The API's internal scrape budget is 150 seconds; Render must allow
the request to stay open that long (paid plans are safer here).

### Catalogue stuck at `{"loaded": false}`

Warm it manually:

```bash
curl -X POST https://<service>.onrender.com/catalog/refresh
```

Then check `/catalog/status` again.

### Frontend still calls `localhost:8000`

`NEXT_PUBLIC_API_BASE` was missing at build time. Set it in Vercel and redeploy
the frontend.

---

## Cloud IPs are challenged (known limitation)

MovieLinkBD sits behind Cloudflare and returns `Just a moment...` to cloud and
datacentre IP ranges. On Render (and Railway, Fly.io, Vercel, any VPS in a
DC range) every upstream request is challenged, so the API answers:

| Call | Result |
| --- | --- |
| `GET /health` | `200` — the container itself is fine |
| `GET /catalog/status` | `{"loaded": false}` |
| `GET /search?q=x` | `CloudflareBlocked` |

The same requests succeed from a normal residential connection (e.g. a home BD
line), which is why local development works while the deployment does not. This
is an IP rule on MovieLinkBD's side; no header, retry or region change fixes it.

**Working setup:** keep the API on a network the site serves and give it a
public URL with a tunnel.

```powershell
powershell -ExecutionPolicy Bypass -File scripts/serve-public.ps1
```

The script starts `uvicorn` on port 8000 and opens a Cloudflare quick tunnel,
then prints the public URL, for example:

```
Public API: https://<random-words>.trycloudflare.com
```

Set that as `NEXT_PUBLIC_API_BASE` on Vercel and redeploy the frontend. The
quick-tunnel URL changes on every run, so repeat both steps when you restart
it. For a stable hostname, create a named Cloudflare Tunnel on a domain you own
(`cloudflared tunnel create mlbd`), or run the API on a residential/BD VPS, and
point `NEXT_PUBLIC_API_BASE` at that instead. The machine must stay on while
the deployed UI is in use.

---

## Updating the deployment

`autoDeploy: true` is set in `render.yaml`, so pushing to `main` triggers a new
build. To deploy manually: Dashboard → service → **Manual Deploy** → **Deploy
latest commit**.

---

## Cost

- **Free:** $0 — cold starts, ephemeral cache, 512 MB RAM.
- **Starter:** ~$7/month — no spin-down, persistent disk available.
- **Standard+:** more RAM/CPU for heavier Playwright use.

For a low-traffic personal project, the free plan is enough because the scraper
usually reads the embedded payload over plain HTTP and only needs Chromium as a
fallback.
