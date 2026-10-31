# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two audiences share one surface:

- End viewers: search the MovieLinkBD catalogue by name or URL, open a title, and get every episode's stream URL, server, quality, and download link as browseable UI or JSON.
- Scraper operators: run the background catalogue sync to MySQL, browse saved titles in the library, view per-title sources/downloads, and monitor sync progress.

Both jobs happen on the same page surface: search results feed into scrapes, and scrapes feed into the saved library.

## Product Purpose

A whole-catalogue MovieLinkBD scraper UI that turns a title name or detail URL into per-episode stream and download sources, then optionally persists everything to a cloud MySQL library so the site never needs to be scraped again for saved titles.

## Positioning

One interface that covers search-by-name, detail-URL scrape, stored-versus-live comparison, and background whole-catalogue sync to MySQL — where the scraper stops at Cloudflare instead of bypassing it, and serves from the saved snapshot when the live site is unreachable.

## Operating Context

Users open the web app against a local or Render-deployed FastAPI backend. They type a title, pick a category, scrape a detail page, then choose whether to save it to MySQL via a background sync job that runs across the whole catalogue. Sessions may close while sync continues; the API keeps working. MySQL configuration is optional — the UI degrades to local-only when not configured.

## Capabilities and Constraints

- Search the whole catalogue index (catalog scope), the site's own search (site scope), or the MySQL mirror (db scope).
- Scrape any detail page for per-episode sources, flat sources, and downloads; compare live scrape against stored snapshot.
- Background sync of every catalogue title to MySQL with concurrency control, retry-empty, force-re-scrape, and 10-consecutive-failure circuit breaker.
- Library browse with category/status filters, search, load-more, per-title detail view (sources/JSON tabs).
- Works without MySQL (local library only) but full cloud persistence requires MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE.

## Brand Commitments

MLBD scraper branding; amber accent on dark surfaces; MIT licensed; built by the project author.

## Evidence on Hand

Existing Next.js web app at `web/`, FastAPI backend at `api/`, Python scraper core at `scraper/`. No visual DESIGN.md exists yet.

## Product Principles

- The site's own player data is the only extraction path; no ad iframes are followed.
- Cloudflare is a stop signal, not a target to bypass.
- Saved data lives in the user's own MySQL, browsable without re-scraping.
- The UI degrades gracefully when the API or MySQL is unreachable.

## Accessibility & Inclusion

Keyboard focus states, readable contrast on dark amber surfaces, responsive composition across desktop and mobile breakpoints. No product-specific standard was established beyond these.
