export type StreamType = "hls" | "mp4" | "mkv" | "webm" | "auto";

export type Category = "movie" | "series" | "drama" | "anime" | "adult" | "other";

export type CatalogCategory =
  | "all"
  | "movie"
  | "series"
  | "drama"
  | "anime"
  | "adult";

export interface ScrapeSource {
  server_name: string | null;
  type: StreamType;
  master_url: string;
  variants: Array<{
    quality: string;
    bandwidth: number | null;
    url: string;
  }>;
  size_bytes: number | null;
  content_type: string | null;
}

export interface EpisodeSource {
  id: string | null;
  provider: string | null;
  quality: string | null;
  quality_label: string | null;
  audio: string | null;
  audio_languages: string[];
  type: StreamType;
  name: string | null;
  codec: string | null;
  hevc: boolean | null;
  is_best: boolean;
  stream_url: string;
  download_url: string | null;
  size_bytes: number | null;
  content_type: string | null;
}

export interface Episode {
  id: string;
  label: string;
  number: number | null;
  season: number | null;
  range_start: number | null;
  range_end: number | null;
  kind: string | null;
  sources: EpisodeSource[];
}

export interface ScrapeDownload {
  label: string;
  quality: string | null;
  size: string | null;
  size_bytes: number | null;
  episode: string | null;
  url: string;
  type: StreamType | null;
  audio: string | null;
}

export interface ScrapeResult {
  page_url: string;
  cached?: boolean;
  cache_age_seconds?: number;
  title: string | null;
  poster: string | null;
  content_type: string | null;
  category: Category;
  adult: boolean;
  completed: boolean | null;
  episodes: Episode[];
  sources: ScrapeSource[];
  downloads: ScrapeDownload[];
  scraped_at: string;
}

export interface ScrapeResponse {
  ok: boolean;
  data: ScrapeResult | null;
  error: string | null;
  error_type: string | null;
}

export interface CompareResponse {
  ok: boolean;
  data: {
    page_url: string;
    stored: ScrapeResult | null;
    live: ScrapeResult;
    diff: {
      page_url: string;
      title: { stored: unknown; live: unknown; changed: boolean };
      poster: { stored: unknown; live: unknown; changed: boolean };
      category: { stored: unknown; live: unknown; changed: boolean };
      completed: { stored: unknown; live: unknown; changed: boolean };
      adult: { stored: unknown; live: unknown; changed: boolean };
      episodes: {
        added: Array<{
          label: string;
          number: number | null;
          season: number | null;
          status: string;
          sources_added: unknown[];
          sources_removed: unknown[];
          sources_changed: unknown[];
          downloads_added: unknown[];
          downloads_removed: unknown[];
          downloads_changed: unknown[];
        }>;
        removed: Array<{
          label: string;
          number: number | null;
          season: number | null;
          status: string;
          sources_added: unknown[];
          sources_removed: unknown[];
          sources_changed: unknown[];
          downloads_added: unknown[];
          downloads_removed: unknown[];
          downloads_changed: unknown[];
        }>;
        changed: Array<{
          label: string;
          number: number | null;
          season: number | null;
          status: string;
          sources_added: unknown[];
          sources_removed: unknown[];
          sources_changed: unknown[];
          downloads_added: unknown[];
          downloads_removed: unknown[];
          downloads_changed: unknown[];
        }>;
        same: number;
        added_count: number;
        removed_count: number;
        changed_count: number;
      };
      sources: {
        added_total: number;
        removed_total: number;
        changed_total: number;
      };
      downloads: {
        added_total: number;
        removed_total: number;
        changed_total: number;
      };
    };
  } | null;
  error: string | null;
  error_type: string | null;
}

export interface SearchItem {
  title: string;
  url: string;
  slug?: string;
  poster: string | null;
  quality: string | null;
  language: string | null;
  type: string | null;
  rating: string | null;
  category?: Category;
  year?: string | null;
  adult?: boolean;
}

export interface SearchResultData {
  query: string;
  category: CatalogCategory;
  results: SearchItem[];
  total: number;
  limit: number;
  offset: number;
  source: "catalog" | "site" | "db";
  catalog_count: number | null;
  searched_at: string;
}

export interface SearchResponse {
  ok: boolean;
  data: SearchResultData | null;
  error: string | null;
  error_type: string | null;
}

export interface CatalogStatus {
  loaded: boolean;
  version?: string;
  count?: number;
  types?: Record<string, number>;
  age_seconds?: number;
}

// Production builds fall back to the deployed Render API, so the site works even
// if NEXT_PUBLIC_API_BASE is not set in the hosting dashboard.
const PRODUCTION_API_BASE =
  "https://mlbd-api-movielinkbd-scraper.onrender.com";

export const API_BASE =
  process.env.NEXT_PUBLIC_API_BASE ||
  (process.env.NODE_ENV === "production"
    ? PRODUCTION_API_BASE
    : "http://localhost:8000");

async function getJson<T>(path: string): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    method: "GET",
    headers: { Accept: "application/json" },
    cache: "no-store",
  });
  if (!res.ok) {
    throw new Error(`HTTP ${res.status}`);
  }
  return (await res.json()) as T;
}

export function catalogStatus(): Promise<CatalogStatus> {
  return getJson<CatalogStatus>("/catalog/status");
}

export function scrapeUrl(url: string, probe = true): Promise<ScrapeResponse> {
  return getJson<ScrapeResponse>(
    `/scrape?url=${encodeURIComponent(url)}&probe=${probe ? "true" : "false"}`
  );
}

export function compareUrl(
  url: string,
  probe = true
): Promise<CompareResponse | null> {
  const params = new URLSearchParams({
    url,
    probe: probe ? "true" : "false",
  });
  return requestJson<CompareResponse>(`/compare?${params.toString()}`, {
    method: "POST",
  }).then((envelope) => envelope.data);
}

export function searchContent(
  query: string,
  options: {
    category?: CatalogCategory;
    limit?: number;
    offset?: number;
    scope?: "catalog" | "site";
  } = {}
): Promise<SearchResponse> {
  const {
    category = "all",
    limit = 24,
    offset = 0,
    scope = "catalog",
  } = options;
  const params = new URLSearchParams({
    q: query,
    category,
    limit: String(limit),
    offset: String(offset),
    scope,
  });
  return getJson<SearchResponse>(`/search?${params.toString()}`);
}

// ---------------------------------------------------------------------------
// Local library + one-click catalogue sync
// ---------------------------------------------------------------------------

export type LibraryStatus = "all" | "saved" | "pending" | "failed" | "empty";

export interface ApiEnvelope<T> {
  ok: boolean;
  data: T | null;
  error: string | null;
  error_type: string | null;
}

export interface SyncStatus {
  running: boolean;
  cancelling: boolean;
  total: number;
  processed: number;
  saved: number;
  failed: number;
  empty: number;
  skipped: number;
  percent: number;
  current: string | null;
  eta_seconds: number | null;
  rate_per_minute: number;
  started_at: string | null;
  finished_at: string | null;
  message: string | null;
}

export interface LibraryStats {
  db_path: string;
  titles: {
    total: number;
    saved: number;
    pending: number;
    failed: number;
    empty: number;
  };
  episodes: number;
  sources: number;
  downloads: number;
  size_bytes: number;
  mysql: boolean;
  mysql_writable: boolean;
  sync: SyncStatus;
}

export interface LibraryItem {
  page_url: string;
  slug: string;
  type: string;
  category: Category;
  title: string;
  year: string | null;
  poster: string | null;
  adult: boolean;
  status: "saved" | "pending" | "failed" | "empty";
  content_type: string | null;
  completed: boolean | null;
  error: string | null;
  scraped_at: string | null;
  updated_at: string;
  saved_at: string | null;
}

export interface LibrarySearchData {
  query: string;
  category: CatalogCategory;
  status: LibraryStatus;
  results: LibraryItem[];
  total: number;
  limit: number;
  offset: number;
}

export interface LibraryTitle extends LibraryItem {
  page_url: string;
  episodes: Episode[];
  sources: ScrapeSource[];
  downloads: ScrapeDownload[];
  saved_at: string;
}

export interface SyncOptions {
  category?: CatalogCategory;
  include_adult?: boolean;
  force?: boolean;
  probe?: boolean;
  concurrency?: number;
  limit?: number | null;
  require_streams?: boolean;
  retry_empty?: boolean;
}

async function requestJson<T>(
  path: string,
  init?: RequestInit
): Promise<ApiEnvelope<T>> {
  const res = await fetch(`${API_BASE}${path}`, {
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    cache: "no-store",
    ...init,
  });

  let body: unknown = null;
  try {
    body = await res.json();
  } catch {
    body = null;
  }

  if (!res.ok) {
    const detail =
      body && typeof body === "object" && "detail" in body
        ? String((body as { detail: unknown }).detail)
        : `HTTP ${res.status}`;
    throw new Error(detail);
  }

  return body as ApiEnvelope<T>;
}

function unwrap<T>(envelope: ApiEnvelope<T>): T {
  if (!envelope.ok || envelope.data === null) {
    throw new Error(envelope.error || "The API returned an empty response");
  }
  return envelope.data;
}

export async function getLibraryStats(): Promise<LibraryStats> {
  return unwrap(await requestJson<LibraryStats>("/library/stats"));
}

export async function getSyncStatus(): Promise<SyncStatus> {
  return unwrap(await requestJson<SyncStatus>("/library/sync/status"));
}

export async function startLibrarySync(
  options: SyncOptions = {}
): Promise<SyncStatus> {
  const payload = {
    category: options.category ?? "all",
    include_adult: options.include_adult ?? true,
    force: options.force ?? false,
    probe: options.probe ?? false,
    concurrency: options.concurrency ?? 3,
    limit: options.limit ?? null,
    require_streams: options.require_streams ?? true,
    retry_empty: options.retry_empty ?? false,
  };
  return unwrap(
    await requestJson<SyncStatus>("/library/sync", {
      method: "POST",
      body: JSON.stringify(payload),
    })
  );
}

export async function cancelLibrarySync(): Promise<SyncStatus> {
  return unwrap(
    await requestJson<SyncStatus>("/library/sync/cancel", { method: "POST" })
  );
}

export async function searchLibrary(
  query = "",
  options: {
    category?: CatalogCategory;
    status?: LibraryStatus;
    limit?: number;
    offset?: number;
  } = {}
): Promise<LibrarySearchData> {
  const {
    category = "all",
    status = "saved",
    limit = 24,
    offset = 0,
  } = options;
  const params = new URLSearchParams({
    q: query,
    category,
    status,
    limit: String(limit),
    offset: String(offset),
  });
  return unwrap(
    await requestJson<LibrarySearchData>(`/library?${params.toString()}`)
  );
}

export async function getLibraryTitle(page_url: string): Promise<LibraryTitle> {
  return unwrap(
    await requestJson<LibraryTitle>(
      `/library/${encodeURIComponent(page_url)}`
    )
  );
}
