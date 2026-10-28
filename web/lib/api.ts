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
  source: "catalog" | "site";
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

export const API_BASE =
  process.env.NEXT_PUBLIC_API_BASE || "http://localhost:8000";

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
