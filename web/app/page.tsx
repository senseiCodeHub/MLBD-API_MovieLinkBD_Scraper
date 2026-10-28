"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { CategoryTabs } from "@/components/CategoryTabs";
import { DownloadList } from "@/components/DownloadList";
import { EpisodeList } from "@/components/EpisodeList";
import { HomeExtras, StarButton } from "@/components/HomeSections";
import { JsonViewer } from "@/components/JsonViewer";
import { SearchForm } from "@/components/SearchForm";
import { SearchResultGrid } from "@/components/SearchResultGrid";
import { SourceCard } from "@/components/SourceCard";
import {
  AlertIcon,
  ArrowLeftIcon,
  ExternalIcon,
  FilmIcon,
  GitHubIcon,
  PlayIcon,
  SpinnerIcon,
} from "@/components/icons";
import { cx } from "@/lib/cx";
import { formatCount } from "@/lib/format";
import { parseInput } from "@/lib/input";
import { AUTHOR } from "@/lib/site";
import {
  API_BASE,
  catalogStatus,
  searchContent,
  scrapeUrl,
  type CatalogCategory,
  type CatalogStatus,
  type ScrapeResponse,
  type ScrapeResult,
  type SearchResultData,
} from "@/lib/api";

const API_HOST = API_BASE.replace(/^https?:\/\//, "").replace(/\/$/, "");

const CATEGORY_LABELS: Record<CatalogCategory, string> = {
  all: "All",
  movie: "Movies",
  series: "Series",
  drama: "Drama",
  anime: "Anime",
  adult: "Adult",
};

const RESULT_CATEGORY_LABELS: Record<string, string> = {
  movie: "Movie",
  series: "Series",
  drama: "Drama",
  anime: "Anime",
  adult: "Adult",
  other: "Title",
};

const ERROR_COPY: Record<string, { title: string; hint: string }> = {
  CloudflareBlocked: {
    title: "Blocked by Cloudflare",
    hint: "The site served a challenge page. The scraper stops here instead of trying to bypass it.",
  },
  NoStreamFound: {
    title: "No stream found",
    hint: "The page loaded, but no stream URLs appeared. The player may not have started.",
  },
  BrowserError: {
    title: "Browser failed",
    hint: "The headless browser could not launch or reach the page. Try again in a moment.",
  },
  Timeout: {
    title: "Request timed out",
    hint: "The site took too long to answer. Try again.",
  },
  ScraperError: {
    title: "Request failed",
    hint: "The scraper hit an unexpected error. Try again.",
  },
};

type Tab = "sources" | "json";

type SearchState =
  | { status: "idle" }
  | { status: "loading"; query: string; category: CatalogCategory }
  | {
      status: "ready";
      data: SearchResultData;
      query: string;
      category: CatalogCategory;
      loadingMore: boolean;
    }
  | {
      status: "error";
      error: string;
      errorType: string | null;
      query: string;
      category: CatalogCategory;
    };

function formatTime(value: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

function formatIndexVersion(version?: string) {
  if (!version) return "live";
  const stamp = version.slice(0, 8);
  if (/^\d{8}$/.test(stamp)) {
    return `v${stamp.slice(0, 4)}-${stamp.slice(4, 6)}-${stamp.slice(6, 8)}`;
  }
  return `v${version.slice(0, 8)}`;
}

export default function Home() {
  const [result, setResult] = useState<ScrapeResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [networkError, setNetworkError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("sources");
  const [search, setSearch] = useState<SearchState>({ status: "idle" });
  const [category, setCategory] = useState<CatalogCategory>("all");
  const [lastQuery, setLastQuery] = useState("");
  const [catalog, setCatalog] = useState<CatalogStatus | null>(null);
  const resultsRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let alive = true;
    catalogStatus()
      .then((status) => {
        if (alive && status.loaded) setCatalog(status);
      })
      .catch(() => {
        // The hero falls back to static copy when the API is offline.
      });
    return () => {
      alive = false;
    };
  }, []);

  const scrollToResults = useCallback(() => {
    resultsRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, []);

  const runScrape = useCallback(
    async (url: string) => {
      setLoading(true);
      setNetworkError(null);
      setResult(null);
      setTab("sources");
      scrollToResults();
      try {
        setResult(await scrapeUrl(url));
      } catch (error) {
        setNetworkError(
          error instanceof Error ? error.message : "The request failed"
        );
      } finally {
        setLoading(false);
      }
    },
    [scrollToResults]
  );

  const runSearch = useCallback(
    async (
      query: string,
      nextCategory: CatalogCategory,
      offset = 0,
      append = false
    ) => {
      setResult(null);
      setNetworkError(null);

      if (append) {
        setSearch((previous) =>
          previous.status === "ready"
            ? { ...previous, loadingMore: true }
            : previous
        );
      } else {
        setSearch({ status: "loading", query, category: nextCategory });
        scrollToResults();
      }

      try {
        const response = await searchContent(query, {
          category: nextCategory,
          limit: 24,
          offset,
        });
        if (!response.ok || !response.data) {
          setSearch({
            status: "error",
            error: response.error ?? "Search failed",
            errorType: response.error_type,
            query,
            category: nextCategory,
          });
          return;
        }

        const data = response.data;
        setLastQuery(query);
        setCategory(nextCategory);

        if (append) {
          setSearch((previous) => {
            if (previous.status !== "ready") {
              return {
                status: "ready",
                data,
                query,
                category: nextCategory,
                loadingMore: false,
              };
            }
            const seen = new Set(
              previous.data.results.map((item) => item.url)
            );
            const merged = [
              ...previous.data.results,
              ...data.results.filter((item) => !seen.has(item.url)),
            ];
            return {
              status: "ready",
              data: { ...data, results: merged, offset: 0 },
              query,
              category: nextCategory,
              loadingMore: false,
            };
          });
        } else {
          setSearch({
            status: "ready",
            data,
            query,
            category: nextCategory,
            loadingMore: false,
          });
        }
      } catch (error) {
        setSearch({
          status: "error",
          error: error instanceof Error ? error.message : "Search failed",
          errorType: null,
          query,
          category: nextCategory,
        });
      }
    },
    [scrollToResults]
  );

  const handleSubmit = useCallback(
    async (raw: string) => {
      const parsed = parseInput(raw);
      if (parsed.kind === "url") {
        await runScrape(parsed.url);
      } else if (parsed.kind === "query") {
        await runSearch(parsed.query, category, 0);
      } else {
        await runSearch(raw.trim(), category, 0);
      }
    },
    [category, runScrape, runSearch]
  );

  const handleCategoryChange = useCallback(
    (next: CatalogCategory) => {
      setCategory(next);
      void runSearch(lastQuery, next, 0);
    },
    [lastQuery, runSearch]
  );

  const handleLoadMore = useCallback(() => {
    if (search.status !== "ready") return;
    void runSearch(
      search.query,
      search.category,
      search.data.results.length,
      true
    );
  }, [runSearch, search]);

  const data = result?.ok ? result.data : null;
  const apiError = result && !result.ok ? result : null;
  const showScrapeView =
    loading || networkError !== null || apiError !== null || data !== null;
  const canGoBack =
    search.status === "ready" ||
    (search.status === "loading" && search.query.length > 0);

  function backToResults() {
    setResult(null);
    setNetworkError(null);
    scrollToResults();
  }

  return (
    <>
      <header className="sticky top-0 z-30 border-b border-line/80 bg-canvas/85 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-5xl items-center gap-2.5 px-4 sm:px-6">
          <span className="grid h-7 w-7 place-items-center rounded-lg bg-amber text-[#1a1205]">
            <PlayIcon size={13} />
          </span>
          <span className="text-sm font-semibold tracking-tight">
            MLBD Scraper
          </span>
          <span className="hidden rounded border border-line bg-surface px-1.5 py-0.5 font-mono text-[10px] text-faint sm:inline">
            v2
          </span>
          <div className="ml-auto flex items-center gap-1.5">
            <a
              href={`${API_BASE}/docs`}
              target="_blank"
              rel="noreferrer"
              className="hidden h-9 items-center rounded-lg border border-line bg-surface px-3 text-xs font-medium text-muted transition-colors duration-150 hover:border-line-strong hover:text-ink sm:inline-flex"
            >
              API
            </a>
            <a
              href={AUTHOR.repo}
              target="_blank"
              rel="noreferrer"
              aria-label="Open the GitHub repository"
              className="grid h-9 w-9 place-items-center rounded-lg border border-line bg-surface text-muted transition-colors duration-150 hover:border-line-strong hover:text-ink"
            >
              <GitHubIcon size={16} />
            </a>
            <StarButton label="Star" className="h-9" />
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-5xl px-4 pb-16 pt-10 sm:px-6 sm:pt-14">
        <section className="relative">
          <div
            aria-hidden="true"
            className="pointer-events-none absolute -left-28 -top-28 h-64 w-64 rounded-full bg-amber/[0.08] blur-3xl"
          />
          <div className="relative">
            <span className="inline-flex items-center gap-2 rounded-full border border-line bg-surface/80 px-2.5 py-1 text-[11px] text-muted">
              <span className="relative flex h-1.5 w-1.5">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-mint opacity-70" />
                <span className="relative inline-flex h-1.5 w-1.5 rounded-full bg-mint" />
              </span>
              Whole-catalogue index
              <span className="font-mono text-[10px] text-faint">
                {formatIndexVersion(catalog?.version)}
              </span>
            </span>

            <h1 className="mt-4 max-w-2xl text-3xl font-semibold leading-[1.08] tracking-[-0.02em] sm:text-[2.6rem]">
              Search the whole{" "}
              <span className="text-amber">MovieLinkBD</span> catalogue
            </h1>
            <p className="mt-3 max-w-xl text-sm leading-relaxed text-muted sm:text-[0.95rem]">
              Anime, movies, series, drama and adult — one index. Pick a title
              to pull every episode&apos;s stream URL, server, quality, and
              download link.
            </p>

            <div className="mt-8 rounded-2xl border border-line bg-surface p-3 shadow-[0_1px_0_0_rgba(255,255,255,0.03)_inset] sm:p-4">
              <SearchForm onSubmit={handleSubmit} loading={loading} />
            </div>

            <dl className="mt-5 grid max-w-2xl grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line sm:grid-cols-4">
              {[
                [
                  catalog?.count ? formatCount(catalog.count) : "14k+",
                  "Titles indexed",
                ],
                ["5", "Categories"],
                ["S1E1…", "Per episode"],
                ["MIT", "Open source"],
              ].map(([value, label]) => (
                <div key={label} className="bg-surface px-3.5 py-3">
                  <dt className="text-[10px] uppercase tracking-[0.12em] text-faint">
                    {label}
                  </dt>
                  <dd className="mt-0.5 text-base font-semibold tabular-nums tracking-tight text-ink">
                    {value}
                  </dd>
                </div>
              ))}
            </dl>

            <div className="mt-4 flex flex-wrap items-center gap-1.5 text-[11px] text-faint">
              <span className="mr-0.5">Try</span>
              {["slime", "doctor x", "hello", "kaliyugam"].map((term) => (
                <button
                  key={term}
                  type="button"
                  onClick={() => void handleSubmit(term)}
                  disabled={loading}
                  className="rounded-md border border-line bg-surface px-2 py-1 font-mono text-muted transition-colors duration-150 hover:border-amber/50 hover:text-amber-soft disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {term}
                </button>
              ))}
            </div>
          </div>
        </section>

        <div
          ref={resultsRef}
          className="mt-10 scroll-mt-6"
          aria-live="polite"
        >
          {showScrapeView ? (
            <div className="space-y-4">
              {canGoBack && !loading && (
                <button
                  type="button"
                  onClick={backToResults}
                  className="inline-flex items-center gap-1.5 text-xs font-medium text-muted transition-colors duration-150 hover:text-amber"
                >
                  <ArrowLeftIcon size={14} />
                  Back to results
                </button>
              )}

              {loading ? (
                <ResultsSkeleton />
              ) : networkError ? (
                <NetworkError message={networkError} />
              ) : apiError ? (
                <ApiError
                  error={apiError.error}
                  errorType={apiError.error_type}
                />
              ) : data ? (
                <Results data={data} tab={tab} onTabChange={setTab} />
              ) : null}
            </div>
          ) : (
            <div className="space-y-5">
              <CategoryTabs
                value={category}
                onChange={handleCategoryChange}
                disabled={search.status === "loading"}
              />

              {search.status === "loading" ? (
                <SearchSkeleton />
              ) : search.status === "ready" ? (
                search.data.results.length === 0 ? (
                  <NoSearchResults
                    query={search.data.query}
                    category={search.data.category}
                  />
                ) : (
                  <>
                    <SearchResultGrid
                      items={search.data.results}
                      query={search.data.query}
                      total={search.data.total}
                      onSelect={(item) => void runScrape(item.url)}
                      disabled={loading}
                    />
                    {search.data.results.length < search.data.total && (
                      <div className="flex justify-center pt-1">
                        <button
                          type="button"
                          onClick={handleLoadMore}
                          disabled={search.loadingMore}
                          className="inline-flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-4 text-xs font-medium text-muted transition-colors duration-150 hover:border-line-strong hover:bg-raised hover:text-ink disabled:cursor-not-allowed disabled:opacity-60"
                        >
                          {search.loadingMore && (
                            <SpinnerIcon size={14} />
                          )}
                          {search.loadingMore
                            ? "Loading…"
                            : `Load more (${formatCount(
                                search.data.results.length
                              )} of ${formatCount(search.data.total)})`}
                        </button>
                      </div>
                    )}
                  </>
                )
              ) : search.status === "error" ? (
                <ApiError
                  error={search.error}
                  errorType={search.errorType}
                />
              ) : (
                <EmptyState />
              )}
            </div>
          )}
        </div>

        <footer className="mt-16 border-t border-line pt-6">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-3">
              <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-amber/30 bg-amber/10 text-[11px] font-semibold text-amber-soft">
                RI
              </span>
              <div className="min-w-0">
                <p className="text-xs text-ink">
                  Built by {AUTHOR.name} ·{" "}
                  <a
                    href={AUTHOR.github}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted transition-colors duration-150 hover:text-amber"
                  >
                    @{AUTHOR.handle}
                  </a>
                </p>
                <p className="mt-0.5 text-[11px] text-faint">
                  MovieLinkBD whole-catalogue scraper · MIT licensed
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-faint">
              <a
                href={AUTHOR.apiDocs}
                target="_blank"
                rel="noreferrer"
                className="transition-colors duration-150 hover:text-ink"
              >
                API guide
              </a>
              <a
                href={`${API_BASE}/docs`}
                target="_blank"
                rel="noreferrer"
                className="transition-colors duration-150 hover:text-ink"
              >
                OpenAPI
              </a>
              <a
                href={AUTHOR.repo}
                target="_blank"
                rel="noreferrer"
                className="transition-colors duration-150 hover:text-ink"
              >
                GitHub
              </a>
              <StarButton className="h-8" />
            </div>
          </div>
        </footer>
      </main>
    </>
  );
}

function Results({
  data,
  tab,
  onTabChange,
}: {
  data: ScrapeResult;
  tab: Tab;
  onTabChange: (tab: Tab) => void;
}) {
  const hasEpisodes = data.episodes.length > 0;
  const categoryLabel =
    RESULT_CATEGORY_LABELS[data.category] ?? RESULT_CATEGORY_LABELS.other;

  return (
    <section className="animate-rise">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 gap-4">
          {data.poster && (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={data.poster}
              alt=""
              referrerPolicy="no-referrer"
              className="hidden h-[7.5rem] w-20 shrink-0 rounded-lg border border-line object-cover sm:block"
            />
          )}
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h2
                className="truncate text-xl font-semibold tracking-tight"
                title={data.title ?? undefined}
              >
                {data.title?.trim() || "Untitled page"}
              </h2>
              <span className="rounded-md border border-line bg-raised px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider text-muted">
                {categoryLabel}
              </span>
              {data.adult && (
                <span className="rounded-md bg-rose/90 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                  18+
                </span>
              )}
            </div>
            <a
              href={data.page_url}
              target="_blank"
              rel="noreferrer"
              className="mt-1 inline-flex max-w-full items-center gap-1.5 text-sm text-muted transition-colors duration-150 hover:text-amber"
            >
              <span className="truncate">{data.page_url}</span>
              <ExternalIcon size={13} className="shrink-0" />
            </a>
            <p className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-faint">
              {hasEpisodes && (
                <>
                  <span>
                    <span className="tabular-nums">
                      {data.episodes.length}
                    </span>{" "}
                    {data.episodes.length === 1 ? "entry" : "episodes"}
                  </span>
                  <span aria-hidden="true">·</span>
                </>
              )}
              <span>
                <span className="tabular-nums">{data.sources.length}</span>{" "}
                {data.sources.length === 1 ? "source" : "sources"}
              </span>
              <span aria-hidden="true">·</span>
              <span>
                <span className="tabular-nums">{data.downloads.length}</span>{" "}
                {data.downloads.length === 1 ? "download" : "downloads"}
              </span>
              <span aria-hidden="true">·</span>
              <span>
                scraped at{" "}
                <span title={data.scraped_at}>
                  {formatTime(data.scraped_at)}
                </span>
              </span>
            </p>
          </div>
        </div>

        <div
          role="group"
          aria-label="Result view"
          className="inline-flex shrink-0 self-start rounded-lg border border-line bg-panel p-0.5"
        >
          {(
            [
              ["sources", "Sources"],
              ["json", "JSON"],
            ] as const
          ).map(([value, label]) => (
            <button
              key={value}
              type="button"
              onClick={() => onTabChange(value)}
              aria-pressed={tab === value}
              className={cx(
                "rounded-md px-3 py-1.5 text-xs font-medium transition-colors duration-150",
                tab === value
                  ? "bg-raised text-ink"
                  : "text-faint hover:text-muted"
              )}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      <div className="mt-5">
        {tab === "json" ? (
          <JsonViewer data={data} />
        ) : (
          <>
            {hasEpisodes ? (
              <EpisodeList episodes={data.episodes} />
            ) : data.sources.length === 0 ? (
              <p className="rounded-xl border border-dashed border-line-strong px-4 py-8 text-center text-sm text-faint">
                {data.downloads.length > 0
                  ? "The player did not expose a playable URL. The page's download links are below."
                  : "This page returned no sources."}
              </p>
            ) : (
              <div className="space-y-3">
                {data.sources.map((source, index) => (
                  <SourceCard
                    key={`${source.master_url}-${index}`}
                    source={source}
                    index={index}
                  />
                ))}
              </div>
            )}

            {data.downloads.length > 0 && (
              <details
                className="mt-6 rounded-xl border border-line bg-surface"
                open={data.downloads.length <= 6}
              >
                <summary className="cursor-pointer px-4 py-3 text-sm font-medium text-ink marker:text-faint">
                  All download links
                  <span className="ml-2 text-xs font-normal text-faint">
                    {data.downloads.length}
                  </span>
                </summary>
                <div className="border-t border-line px-4 pb-1">
                  <DownloadList downloads={data.downloads} showHeading={false} />
                </div>
              </details>
            )}
          </>
        )}
      </div>
    </section>
  );
}

function EmptyState() {
  return (
    <div className="space-y-5">
      <section className="rounded-xl border border-dashed border-line-strong/80 px-6 py-8">
        <div className="flex items-center gap-2 text-sm font-medium text-ink">
          <FilmIcon size={16} className="text-amber" />
          Start with a title — or browse everything
        </div>
        <p className="mt-2 max-w-lg text-sm leading-relaxed text-muted">
          Type a title to search every anime, movie, series, drama and adult
          entry, pick a category above to browse it, or leave the box empty and
          press <span className="font-medium text-ink">Browse</span>. Selecting
          a title returns each episode&apos;s stream URL and direct download
          link.
        </p>
        <div className="mt-5 flex flex-wrap gap-1.5 text-[11px] text-muted">
          {[
            "S1E1 · E2 · E3",
            "1080p · 720p · 480p",
            "MKV · MP4",
            "Hindi · Tamil · English",
          ].map((chip) => (
            <span
              key={chip}
              className="rounded-md border border-line bg-surface px-2 py-1 font-mono"
            >
              {chip}
            </span>
          ))}
        </div>
      </section>

      <HomeExtras />
    </div>
  );
}

function SearchSkeleton() {
  return (
    <section aria-busy="true">
      <span className="sr-only">Searching the MovieLinkBD catalogue…</span>
      <div className="animate-pulse">
        <div className="h-3.5 w-56 max-w-full rounded bg-panel" />
        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, index) => (
            <div
              key={index}
              className={cx(
                "overflow-hidden rounded-xl border border-line bg-surface",
                index > 3 && "hidden sm:block"
              )}
            >
              <div className="aspect-[2/3] w-full bg-panel" />
              <div className="space-y-2 p-2.5">
                <div className="h-3 w-full rounded bg-panel/80" />
                <div className="h-3 w-2/3 rounded bg-panel/60" />
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function ResultsSkeleton() {
  return (
    <section aria-busy="true">
      <span className="sr-only">Scraping every episode…</span>
      <div className="animate-pulse">
        <div className="h-6 w-64 max-w-full rounded bg-panel" />
        <div className="mt-2.5 h-4 w-80 max-w-full rounded bg-panel/70" />
        <div className="mt-2.5 h-3 w-40 rounded bg-panel/60" />

        <div className="mt-5 space-y-3">
          {[0, 1].map((index) => (
            <div
              key={index}
              className="overflow-hidden rounded-xl border border-line bg-surface"
            >
              <div className="flex items-center gap-3 border-b border-line px-4 py-3">
                <div className="h-4 w-10 rounded bg-panel" />
                <div className="h-3.5 w-32 rounded bg-panel" />
                <div className="ml-auto h-7 w-28 rounded-lg bg-panel" />
              </div>
              <div className="space-y-3 px-4 py-4">
                <div className="h-3 w-24 rounded bg-panel/70" />
                <div className="h-3 w-3/4 rounded bg-panel/60" />
                <div className="h-3 w-2/3 rounded bg-panel/50" />
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function NoSearchResults({
  query,
  category,
}: {
  query: string;
  category: CatalogCategory;
}) {
  const where = CATEGORY_LABELS[category] ?? "the catalogue";
  return (
    <section className="rounded-xl border border-dashed border-line-strong/80 px-6 py-10">
      <p className="text-sm font-medium text-ink">
        {query
          ? `No titles matched “${query}” in ${where}`
          : `No titles found in ${where}`}
      </p>
      <p className="mt-2 max-w-md text-sm leading-relaxed text-muted">
        Try a shorter name, a different spelling, or the year — for example
        “hello 2023”. You can also switch category or clear the search to browse
        everything.
      </p>
    </section>
  );
}

function NetworkError({ message }: { message: string }) {
  return (
    <div
      role="alert"
      className="flex items-start gap-3 rounded-xl border border-rose/30 bg-rose/[0.07] p-4"
    >
      <AlertIcon size={18} className="mt-0.5 shrink-0 text-rose" />
      <div className="min-w-0">
        <p className="text-sm font-medium text-ink">
          Couldn&apos;t reach the scraper API
        </p>
        <p className="mt-1 text-sm leading-relaxed text-muted">
          {message}. Check that the API is running at{" "}
          <code className="font-mono text-xs text-ink">{API_HOST}</code> and try
          again.
        </p>
      </div>
    </div>
  );
}

function ApiError({
  error,
  errorType,
}: {
  error: string | null;
  errorType: string | null;
}) {
  const copy = (errorType && ERROR_COPY[errorType]) || ERROR_COPY.ScraperError;
  const showHint = !error || error.trim() !== copy.hint;

  return (
    <div
      role="alert"
      className="flex items-start gap-3 rounded-xl border border-rose/30 bg-rose/[0.07] p-4"
    >
      <AlertIcon size={18} className="mt-0.5 shrink-0 text-rose" />
      <div className="min-w-0">
        <p className="text-sm font-medium text-ink">{copy.title}</p>
        <p className="mt-1 break-words text-sm leading-relaxed text-muted">
          {error || copy.hint}
        </p>
        {showHint && (
          <p className="mt-1 text-xs leading-relaxed text-faint">{copy.hint}</p>
        )}
      </div>
    </div>
  );
}
