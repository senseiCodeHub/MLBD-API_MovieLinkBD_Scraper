"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type ReactNode,
} from "react";
import { DownloadList } from "@/components/DownloadList";
import { EpisodeList } from "@/components/EpisodeList";
import { JsonViewer } from "@/components/JsonViewer";
import {
  AlertIcon,
  ArrowLeftIcon,
  BookIcon,
  ExternalIcon,
  FilmIcon,
  LayersIcon,
  SearchIcon,
  ServerIcon,
  SpinnerIcon,
} from "@/components/icons";
import { cx } from "@/lib/cx";
import { formatBytes, formatCount } from "@/lib/format";
import {
  cancelLibrarySync,
  getLibraryStats,
  getLibraryTitle,
  getSyncStatus,
  searchLibrary,
  startLibrarySync,
  type CatalogCategory,
  type LibraryItem,
  type LibrarySearchData,
  type LibraryStatus,
  type LibraryStats,
  type LibraryTitle,
  type SyncStatus,
} from "@/lib/api";

const CATEGORIES: Array<{ value: CatalogCategory; label: string }> = [
  { value: "all", label: "Everything" },
  { value: "movie", label: "Movies" },
  { value: "series", label: "Series" },
  { value: "drama", label: "Drama" },
  { value: "anime", label: "Anime" },
  { value: "adult", label: "Adult" },
];

const STATUS_FILTERS: Array<{ value: LibraryStatus; label: string }> = [
  { value: "saved", label: "With streams" },
  { value: "empty", label: "No streams" },
  { value: "all", label: "All" },
  { value: "pending", label: "Pending" },
  { value: "failed", label: "Failed" },
];

const CATEGORY_BADGE: Record<string, string> = {
  movie: "Movie",
  series: "Series",
  drama: "Drama",
  anime: "Anime",
  adult: "Adult",
  other: "Title",
};

function formatWhen(value: string | null) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString([], {
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatDuration(seconds: number | null) {
  if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) return "—";
  const total = Math.round(seconds);
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const secs = total % 60;
  if (hours > 0) return `${hours}h ${minutes}m`;
  if (minutes > 0) return `${minutes}m ${secs}s`;
  return `${secs}s`;
}

export function LibraryView() {
  const [stats, setStats] = useState<LibraryStats | null>(null);
  const [statsError, setStatsError] = useState<string | null>(null);
  const [sync, setSync] = useState<SyncStatus | null>(null);
  const [starting, setStarting] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const [category, setCategory] = useState<CatalogCategory>("all");
  const [includeAdult, setIncludeAdult] = useState(true);
  const [force, setForce] = useState(false);
  const [probe, setProbe] = useState(false);
  const [concurrency, setConcurrency] = useState(3);
  const [limit, setLimit] = useState("");
  const [requireStreams, setRequireStreams] = useState(true);
  const [retryEmpty, setRetryEmpty] = useState(false);

  const [query, setQuery] = useState("");
  const [searchCategory, setSearchCategory] =
    useState<CatalogCategory>("all");
  const [searchStatus, setSearchStatus] = useState<LibraryStatus>("saved");
  const [library, setLibrary] = useState<LibrarySearchData | null>(null);
  const [listLoading, setListLoading] = useState(false);
  const [listError, setListError] = useState<string | null>(null);

  const [selectedPageUrl, setSelectedPageUrl] = useState<string | null>(null);
  const [detail, setDetail] = useState<LibraryTitle | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [detailTab, setDetailTab] = useState<"sources" | "json">("sources");

  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const refreshStats = useCallback(async () => {
    try {
      const next = await getLibraryStats();
      if (!mounted.current) return;
      setStats(next);
      setSync(next.sync);
      setStatsError(null);
    } catch (error) {
      if (!mounted.current) return;
      setStatsError(
        error instanceof Error ? error.message : "Could not load the library"
      );
    }
  }, []);

  const loadLibrary = useCallback(
    async (
      nextQuery: string,
      nextCategory: CatalogCategory,
      nextStatus: LibraryStatus,
      offset: number,
      append: boolean
    ) => {
      setListLoading(true);
      setListError(null);
      try {
        const data = await searchLibrary(nextQuery, {
          category: nextCategory,
          status: nextStatus,
          limit: 24,
          offset,
        });
        if (!mounted.current) return;
        setLibrary((previous) => {
          if (!append || previous === null) return data;
          const seen = new Set(previous.results.map((item) => item.page_url));
          return {
            ...data,
            results: [
              ...previous.results,
              ...data.results.filter((item) => !seen.has(item.page_url)),
            ],
            offset: 0,
          };
        });
      } catch (error) {
        if (!mounted.current) return;
        setListError(
          error instanceof Error ? error.message : "Could not load saved titles"
        );
      } finally {
        if (mounted.current) setListLoading(false);
      }
    },
    []
  );

  useEffect(() => {
    void refreshStats();
    void loadLibrary("", "all", "saved", 0, false);
  }, [refreshStats, loadLibrary]);

  useEffect(() => {
    if (!sync?.running) return;
    let alive = true;
    const timer = setInterval(async () => {
      try {
        const next = await getSyncStatus();
        if (!alive) return;
        setSync(next);
        if (!next.running) {
          clearInterval(timer);
          void refreshStats();
          void loadLibrary(query, searchCategory, searchStatus, 0, false);
        }
      } catch {
        // Keep the last known progress; a transient error should not stop polling.
      }
    }, 1500);
    return () => {
      alive = false;
      clearInterval(timer);
    };
  }, [
    sync?.running,
    query,
    searchCategory,
    searchStatus,
    refreshStats,
    loadLibrary,
  ]);

  const running = Boolean(sync?.running);

  const handleStart = useCallback(async () => {
    setStarting(true);
    setActionError(null);
    try {
      const parsedLimit = limit.trim() === "" ? null : Number(limit);
      if (parsedLimit !== null && (!Number.isFinite(parsedLimit) || parsedLimit < 1)) {
        throw new Error("Limit must be a positive number");
      }
      const next = await startLibrarySync({
        category,
        include_adult: includeAdult,
        force,
        probe,
        concurrency,
        limit: parsedLimit === null ? null : Math.floor(parsedLimit),
        require_streams: requireStreams,
        retry_empty: retryEmpty,
      });
      setSync(next);
      void refreshStats();
    } catch (error) {
      setActionError(
        error instanceof Error ? error.message : "Could not start the sync"
      );
    } finally {
      setStarting(false);
    }
  }, [
    category,
    includeAdult,
    force,
    probe,
    concurrency,
    limit,
    requireStreams,
    retryEmpty,
    refreshStats,
  ]);

  const handleCancel = useCallback(async () => {
    setActionError(null);
    try {
      setSync(await cancelLibrarySync());
    } catch (error) {
      setActionError(
        error instanceof Error ? error.message : "Could not cancel the sync"
      );
    }
  }, []);

  const handleSearchSubmit = useCallback(
    (event: FormEvent) => {
      event.preventDefault();
      void loadLibrary(query, searchCategory, searchStatus, 0, false);
    },
    [loadLibrary, query, searchCategory, searchStatus]
  );

  const handleFilter = useCallback(
    (nextStatus: LibraryStatus) => {
      setSearchStatus(nextStatus);
      void loadLibrary(query, searchCategory, nextStatus, 0, false);
    },
    [loadLibrary, query, searchCategory]
  );

  const handleCategory = useCallback(
    (next: CatalogCategory) => {
      setSearchCategory(next);
      void loadLibrary(query, next, searchStatus, 0, false);
    },
    [loadLibrary, query, searchStatus]
  );

  const handleLoadMore = useCallback(() => {
    if (!library) return;
    void loadLibrary(
      query,
      searchCategory,
      searchStatus,
      library.results.length,
      true
    );
  }, [library, loadLibrary, query, searchCategory, searchStatus]);

  const openTitle = useCallback(async (page_url: string) => {
    setSelectedPageUrl(page_url);
    setDetail(null);
    setDetailError(null);
    setDetailLoading(true);
    setDetailTab("sources");
    try {
      const data = await getLibraryTitle(page_url);
      if (mounted.current) setDetail(data);
    } catch (error) {
      if (mounted.current) {
        setDetailError(
          error instanceof Error ? error.message : "Could not load this title"
        );
      }
    } finally {
      if (mounted.current) setDetailLoading(false);
    }
  }, []);

  if (selectedPageUrl !== null) {
    return (
      <LibraryDetail
        detail={detail}
        loading={detailLoading}
        error={detailError}
        tab={detailTab}
        onTabChange={setDetailTab}
        onBack={() => {
          setSelectedPageUrl(null);
          setDetail(null);
        }}
        onRetry={() => void openTitle(selectedPageUrl)}
      />
    );
  }

  const progress = sync ?? null;
  const percent = progress ? Math.min(100, Math.max(0, progress.percent)) : 0;

  return (
    <div className="space-y-7">
      {/* Stats */}
      <section className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line bg-line sm:grid-cols-3 lg:grid-cols-7">
        <Stat
          label="With streams"
          value={stats ? formatCount(stats.titles.saved) : "—"}
        />
        <Stat
          label="No streams"
          value={stats ? formatCount(stats.titles.empty) : "—"}
        />
        <Stat
          label="Pending"
          value={stats ? formatCount(stats.titles.pending) : "—"}
        />
        <Stat
          label="Episodes"
          value={stats ? formatCount(stats.episodes) : "—"}
        />
        <Stat
          label="Stream links"
          value={stats ? formatCount(stats.sources) : "—"}
        />
        <Stat
          label="Downloads"
          value={stats ? formatCount(stats.downloads) : "—"}
        />
        <Stat
          label="Database"
          value={
            stats
              ? stats.mysql
                ? "Cloud MySQL"
                : "Not connected"
              : "—"
          }
        />
      </section>

      {statsError && (
        <Notice tone="error" title="Library database unreachable">
          {statsError}
        </Notice>
      )}

      {stats?.mysql && !stats.mysql_writable && (
        <Notice tone="info" title="MySQL is connected read-only">
          The configured key can only read, so saves stay local. To mirror them
          to MySQL, add{" "}
          <code className="font-mono text-[11px] text-ink">
            MYSQL_PASSWORD
          </code>{" "}
          to <code className="font-mono text-[11px] text-ink">.env</code>{" "}
          (MySQL user needs INSERT/UPDATE/DELETE privileges), restart the API,
          then reload this page. Never expose credentials in the browser or
          commit them.
        </Notice>
      )}

      {/* One-click save */}
      <section className="rounded-2xl border border-line bg-surface p-5 sm:p-6">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
              <h2 className="flex items-center gap-2 text-sm font-semibold text-ink">
                <BookIcon size={16} className="text-amber" />
                Save every title to MySQL
              </h2>
              <p className="mt-1.5 max-w-2xl text-xs leading-relaxed text-muted">
                Scrapes each catalogue title and stores every episode, stream
                source and download link in MySQL
                {stats?.mysql_writable
                  ? ", ready to browse from any host."
                  : stats?.mysql
                    ? ". MySQL is configured read-only, so saving is disabled."
                    : "."}
            </p>
          </div>
          <span
            className={cx(
              "shrink-0 rounded-full border px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-wider",
              stats?.mysql_writable
                ? "border-mint/30 bg-mint/10 text-mint"
                : "border-line bg-panel text-faint"
            )}
          >
            {stats?.mysql_writable
              ? "Cloud MySQL on"
              : stats?.mysql
                ? "MySQL read-only"
                : "Local only"}
          </span>
        </div>

        <div className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Field label="Category">
            <select
              value={category}
              onChange={(event) =>
                setCategory(event.target.value as CatalogCategory)
              }
              disabled={running}
              aria-label="Sync category"
              className="h-9 w-full rounded-xl border border-line bg-canvas px-2.5 text-xs text-ink transition-colors duration-200 hover:border-line-strong focus:border-amber/60 disabled:opacity-60"
            >
              {CATEGORIES.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.label}
                </option>
              ))}
            </select>
          </Field>

          <Field label="Parallel titles">
            <select
              value={concurrency}
              onChange={(event) => setConcurrency(Number(event.target.value))}
              disabled={running}
              aria-label="Parallel titles"
              className="h-9 w-full rounded-xl border border-line bg-canvas px-2.5 text-xs text-ink transition-colors duration-200 hover:border-line-strong focus:border-amber/60 disabled:opacity-60"
            >
              {[1, 2, 3, 4, 5, 6, 8].map((value) => (
                <option key={value} value={value}>
                  {value} at a time
                </option>
              ))}
            </select>
          </Field>

          <Field label="Limit (testing)">
            <input
              type="number"
              min={1}
              value={limit}
              onChange={(event) => setLimit(event.target.value)}
              disabled={running}
              placeholder="All titles"
              aria-label="Limit (testing)"
              className="h-9 w-full rounded-xl border border-line bg-canvas px-2.5 text-xs text-ink transition-colors duration-200 hover:border-line-strong focus:border-amber/60 disabled:opacity-60"
            />
          </Field>

          <Field label="Run" group>
            <div className="flex h-9 items-center gap-2">
              {running ? (
                <button
                  type="button"
                  onClick={() => void handleCancel()}
                  disabled={sync?.cancelling}
                  className="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-xl border border-rose/40 bg-rose/10 px-3 text-xs font-medium text-rose transition-colors duration-200 hover:bg-rose/15 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {sync?.cancelling ? (
                    <SpinnerIcon size={13} />
                  ) : (
                    <AlertIcon size={13} />
                  )}
                  {sync?.cancelling ? "Cancelling…" : "Stop"}
                </button>
              ) : (
                <button
                  type="button"
                  onClick={() => void handleStart()}
                  disabled={starting}
                  className="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-xl bg-amber px-3 text-xs font-semibold text-[#1a1205] transition-colors duration-200 hover:bg-amber-soft disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {starting ? (
                    <SpinnerIcon size={13} />
                  ) : (
                    <BookIcon size={13} />
                  )}
                  {starting ? "Starting…" : "Save all titles"}
                </button>
              )}
            </div>
          </Field>
        </div>

        <div className="mt-4 flex flex-wrap gap-x-4 gap-y-2">
          <Check
            checked={includeAdult}
            onChange={setIncludeAdult}
            disabled={running}
            label="Include adult titles"
          />
          <Check
            checked={requireStreams}
            onChange={setRequireStreams}
            disabled={running}
            label="Only keep titles with stream links"
          />
          <Check
            checked={retryEmpty}
            onChange={setRetryEmpty}
            disabled={running}
            label="Retry no-stream titles"
          />
          <Check
            checked={force}
            onChange={setForce}
            disabled={running}
            label="Re-scrape saved titles"
          />
          <Check
            checked={probe}
            onChange={setProbe}
            disabled={running}
            label="Probe file sizes (slow)"
          />
        </div>

        {actionError && (
          <p className="mt-3 text-xs text-rose" role="alert">
            {actionError}
          </p>
        )}

        {progress && (progress.running || progress.started_at) && (
          <div className="mt-4 rounded-xl border border-line bg-canvas/60 p-3.5">
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
              <span className="font-medium text-ink">
                {progress.message ?? "Sync"}
              </span>
              <span className="font-mono text-[11px] text-faint">
                {formatCount(progress.processed)} /{" "}
                {formatCount(progress.total)}
              </span>
            </div>

            <div
              className="mt-2.5 h-2 overflow-hidden rounded-full bg-panel"
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={Math.round(percent)}
            >
              <div
                className={cx(
                  "h-full rounded-full transition-[width] duration-500",
                  progress.running ? "bg-amber" : "bg-mint"
                )}
                style={{ width: `${percent}%` }}
              />
            </div>

            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-faint">
              <span>
                Saved <span className="text-mint">{formatCount(progress.saved)}</span>
              </span>
              <span>
                No streams{" "}
                <span className="text-amber-soft">{formatCount(progress.empty)}</span>
              </span>
              <span>
                Failed <span className="text-rose">{formatCount(progress.failed)}</span>
              </span>
              <span>
                Skipped <span className="text-muted">{formatCount(progress.skipped)}</span>
              </span>
              <span>ETA {formatDuration(progress.eta_seconds)}</span>
              <span>{formatCount(progress.rate_per_minute)}/min</span>
            </div>

            {progress.current && progress.running && (
              <p className="mt-2.5 truncate text-[11px] text-muted">
                Now: {progress.current}
              </p>
            )}
          </div>
        )}

        <p className="mt-3 text-[11px] leading-relaxed text-faint">
          <span className="text-muted">Skipped</span> means the title was already
          scraped in an earlier run, so the job resumes instead of repeating it.
          Pages with downloads but no playable stream are stored as{" "}
          <span className="text-muted">No streams</span> and are not counted as
          saved. The job stops itself after 10 consecutive failures so the site
          is not hammered. A full catalogue run takes a few hours; keep this tab
          open to watch progress, or close it — the API keeps working.
        </p>
      </section>

      {/* Saved titles */}
      <section>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="flex items-center gap-2 text-sm font-semibold text-ink">
              <LayersIcon size={16} className="text-amber" />
              Saved titles
              {library && (
                <span className="text-xs font-normal text-faint">
                  {formatCount(library.total)}
                </span>
              )}
            </h2>
            <p className="mt-1 text-xs text-muted">
              Browse titles that have real stream links, then open one for its
              episode URLs. Use <span className="text-ink">No streams</span> to
              see pages that only exposed downloads.
            </p>
          </div>
          <button
            type="button"
            onClick={() => {
              void refreshStats();
              void loadLibrary(
                query,
                searchCategory,
                searchStatus,
                0,
                false
              );
            }}
            disabled={listLoading}
            className="inline-flex h-9 items-center gap-2 rounded-xl border border-line bg-panel px-3 text-xs font-medium text-muted transition-colors duration-200 hover:border-line-strong hover:text-ink disabled:cursor-not-allowed disabled:opacity-60"
          >
            {listLoading ? <SpinnerIcon size={13} /> : null}
            Refresh
          </button>
        </div>

        <form
          onSubmit={handleSearchSubmit}
          className="mt-3 flex flex-wrap items-center gap-2"
        >
          <div className="relative min-w-0 flex-1">
            <SearchIcon
              size={14}
              className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint"
            />
            <input
              type="text"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search saved titles…"
              aria-label="Search saved titles"
              className="h-9 w-full rounded-xl border border-line bg-canvas pl-9 pr-3 text-xs text-ink transition-colors duration-200 hover:border-line-strong focus:border-amber/60"
            />
          </div>
          <select
            value={searchCategory}
            onChange={(event) =>
              handleCategory(event.target.value as CatalogCategory)
            }
            aria-label="Filter saved titles by category"
            className="h-9 rounded-xl border border-line bg-canvas px-2.5 text-xs text-ink transition-colors duration-200 hover:border-line-strong focus:border-amber/60"
          >
            {CATEGORIES.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
          <button
            type="submit"
            className="inline-flex h-9 items-center rounded-xl border border-line bg-panel px-3 text-xs font-medium text-muted transition-colors duration-200 hover:border-line-strong hover:text-ink"
          >
            Search
          </button>
        </form>

        <div className="mt-2.5 flex flex-wrap gap-1.5">
          {STATUS_FILTERS.map((filter) => (
            <button
              key={filter.value}
              type="button"
              onClick={() => handleFilter(filter.value)}
              aria-pressed={searchStatus === filter.value}
              className={cx(
                "rounded-md border px-2 py-1 text-[11px] font-medium transition-colors duration-200",
                searchStatus === filter.value
                  ? "border-amber/50 bg-amber/10 text-amber-soft"
                  : "border-line bg-panel text-faint hover:border-line-strong hover:text-ink"
              )}
            >
              {filter.label}
            </button>
          ))}
        </div>

        {listError ? (
          <Notice tone="error" title="Could not load saved titles">
            {listError}
          </Notice>
        ) : listLoading && library === null ? (
          <LibrarySkeleton />
        ) : library && library.results.length === 0 ? (
          <div className="mt-5 rounded-2xl border border-dashed border-line-strong/80 px-6 py-10 text-center">
            <FilmIcon size={20} className="mx-auto text-faint" />
            <p className="mt-2 text-sm font-medium text-ink">
              {query.trim()
                ? `No saved titles match “${query.trim()}”`
                : "Nothing saved yet"}
            </p>
            <p className="mt-1 text-xs leading-relaxed text-muted">
              {query.trim()
                ? "Try a shorter query, a different status, or another category."
                : "Use “Save all titles” above and the library fills up as the scraper works through the catalogue."}
            </p>
          </div>
        ) : library ? (
          <>
            <ul className="mt-4 divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
              {library.results.map((item) => (
                <LibraryRow
                  key={item.page_url}
                  item={item}
                  onOpen={() => void openTitle(item.page_url)}
                />
              ))}
            </ul>

            {library.results.length < library.total && (
              <div className="mt-4 flex justify-center">
                <button
                  type="button"
                  onClick={handleLoadMore}
                  disabled={listLoading}
                  className="inline-flex h-9 items-center gap-2 rounded-xl border border-line bg-panel px-3 text-xs font-medium text-muted transition-colors duration-200 hover:border-line-strong hover:text-ink disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {listLoading && <SpinnerIcon size={13} />}
                  {listLoading
                    ? "Loading…"
                    : `Load more (${formatCount(
                        library.results.length
                      )} of ${formatCount(library.total)})`}
                </button>
              </div>
            )}
          </>
        ) : null}
      </section>
    </div>
  );
}

function LibraryRow({
  item,
  onOpen,
}: {
  item: LibraryItem;
  onOpen: () => void;
}) {
  return (
    <li>
      <button
        type="button"
        onClick={onOpen}
        className="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors duration-200 hover:bg-panel/50"
      >
        {item.poster ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={item.poster}
            alt=""
            referrerPolicy="no-referrer"
            loading="lazy"
            className="h-16 w-11 shrink-0 rounded-xl border border-line object-cover"
          />
        ) : (
          <span className="grid h-16 w-11 shrink-0 place-items-center rounded-xl border border-line bg-panel text-faint">
            <FilmIcon size={16} />
          </span>
        )}

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="truncate text-sm font-medium text-ink">
              {item.title}
            </span>
            <span className="rounded border border-line bg-raised px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-faint">
              {CATEGORY_BADGE[item.category] ?? "Title"}
            </span>
            {item.adult && (
              <span className="rounded bg-rose/90 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                18+
              </span>
            )}
            {item.status !== "saved" && (
              <span
                className={cx(
                  "rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wider",
                  item.status === "failed"
                    ? "bg-rose/15 text-rose"
                    : "bg-panel text-faint"
                )}
              >
                {item.status === "empty" ? "no streams" : item.status}
              </span>
            )}
          </div>

          <p className="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[11px] text-faint">
            {item.year && <span>{item.year}</span>}
            <span>saved {formatWhen(item.scraped_at)}</span>
          </p>

          {item.error && (
            <p className="mt-1 truncate text-[11px] text-rose" title={item.error}>
              {item.error}
            </p>
          )}
        </div>

        <span className="shrink-0 text-faint">
          <ExternalIcon size={14} />
        </span>
      </button>
    </li>
  );
}

function LibraryDetail({
  detail,
  loading,
  error,
  tab,
  onTabChange,
  onBack,
  onRetry,
}: {
  detail: LibraryTitle | null;
  loading: boolean;
  error: string | null;
  tab: "sources" | "json";
  onTabChange: (tab: "sources" | "json") => void;
  onBack: () => void;
  onRetry: () => void;
}) {
  return (
    <section className="animate-rise">
      <button
        type="button"
        onClick={onBack}
        className="inline-flex items-center gap-1.5 text-xs font-medium text-muted transition-colors duration-200 hover:text-amber"
      >
        <ArrowLeftIcon size={14} />
        Back to saved titles
      </button>

      {loading ? (
        <div className="mt-5 animate-pulse space-y-3">
          <div className="h-6 w-64 max-w-full rounded bg-panel" />
          <div className="h-4 w-80 max-w-full rounded bg-panel/70" />
          <div className="h-40 rounded-xl border border-line bg-surface" />
        </div>
      ) : error ? (
        <Notice tone="error" title="Could not open this title">
          {error}{" "}
          <button
            type="button"
            onClick={onRetry}
            className="ml-1 underline underline-offset-2 hover:text-ink"
          >
            Retry
          </button>
        </Notice>
      ) : detail ? (
        <>
          <div className="mt-5 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div className="flex min-w-0 gap-5">
              {detail.poster && (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={detail.poster}
                  alt=""
                  referrerPolicy="no-referrer"
                  className="hidden h-[8rem] w-24 shrink-0 rounded-2xl border border-line object-cover sm:block shadow-[0_10px_15px_-3px_rgba(0,0,0,0.4)]"
                />
              )}
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <h2
                    className="truncate text-xl font-semibold tracking-tight"
                    title={detail.title}
                  >
                    {detail.title}
                  </h2>
                  <span className="rounded-md border border-line bg-panel px-2 py-0.5 text-[10px] font-medium uppercase tracking-wider text-muted">
                    {CATEGORY_BADGE[detail.category] ?? "Title"}
                  </span>
                  {detail.adult && (
                    <span className="rounded-md bg-rose/90 px-2 py-0.5 text-[10px] font-semibold text-white">
                      18+
                    </span>
                  )}
                </div>

                {detail.page_url && (
                  <a
                    href={detail.page_url}
                    target="_blank"
                    rel="noreferrer"
                    className="mt-2 inline-flex max-w-full items-center gap-1.5 text-sm text-muted transition-colors duration-200 hover:text-amber"
                  >
                    <span className="truncate">{detail.page_url}</span>
                    <ExternalIcon size={13} className="shrink-0" />
                  </a>
                )}

                <p className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-faint">
                  <span>
                    <span className="tabular-nums">{detail.episodes.length}</span>{" "}
                    {detail.episodes.length === 1 ? "entry" : "episodes"}
                  </span>
                  <span aria-hidden="true">·</span>
                  <span>
                    <span className="tabular-nums">{detail.sources.length}</span>{" "}
                    {detail.sources.length === 1 ? "source" : "sources"}
                  </span>
                  <span aria-hidden="true">·</span>
                  <span>
                    <span className="tabular-nums">{detail.downloads.length}</span>{" "}
                    {detail.downloads.length === 1 ? "download" : "downloads"}
                  </span>
                  <span aria-hidden="true">·</span>
                  <span>saved {formatWhen(detail.scraped_at)}</span>
                </p>
              </div>
            </div>

            <div
              role="group"
              aria-label="Saved title view"
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
                    "rounded-md px-3 py-1.5 text-xs font-medium transition-colors duration-200",
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

          <div className="mt-6">
            {tab === "json" ? (
              <JsonViewer data={detail} />
            ) : detail.episodes.length > 0 ? (
              <>
                <EpisodeList episodes={detail.episodes} />
                {detail.downloads.length > 0 && (
                  <details className="mt-8 rounded-2xl border border-line bg-surface">
                    <summary className="cursor-pointer px-6 py-3.5 text-sm font-medium text-ink marker:text-faint list-none">
                      All download links
                      <span className="ml-2 text-xs font-normal text-faint">
                        {detail.downloads.length}
                      </span>
                    </summary>
                    <div className="border-t border-line px-6 pb-4">
                      <DownloadList
                        downloads={detail.downloads}
                        showHeading={false}
                      />
                    </div>
                  </details>
                )}
              </>
            ) : (
              <div className="rounded-2xl border border-dashed border-line-strong px-6 py-10 text-center text-sm text-faint">
                {detail.downloads.length > 0
                  ? "No playable stream was exposed for this title. The download links are below."
                  : "This saved title has no sources."}
                {detail.downloads.length > 0 && (
                  <div className="mt-4 text-left">
                    <DownloadList
                      downloads={detail.downloads}
                      showHeading={false}
                    />
                  </div>
                )}
              </div>
            )}
          </div>
        </>
      ) : null}
    </section>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-panel px-3.5 py-3">
      <p className="text-[10px] uppercase tracking-[0.14em] text-faint">
        {label}
      </p>
      <p className="mt-0.5 truncate text-base font-semibold tabular-nums tracking-tight text-ink">
        {value}
      </p>
    </div>
  );
}

function Field({
  label,
  children,
  group,
}: {
  label: string;
  children: ReactNode;
  group?: boolean;
}) {
  const labelNode = (
    <span className="mb-1 block text-[10px] uppercase tracking-[0.14em] text-faint">
      {label}
    </span>
  );

  if (group) {
    return (
      <div className="block">
        {labelNode}
        {children}
      </div>
    );
  }

  return (
    <label className="block">
      {labelNode}
      {children}
    </label>
  );
}

function Check({
  checked,
  onChange,
  disabled,
  label,
}: {
  checked: boolean;
  onChange: (value: boolean) => void;
  disabled?: boolean;
  label: string;
}) {
  return (
    <label
      className={cx(
        "inline-flex items-center gap-2 text-[11px] text-muted",
        disabled && "opacity-50"
      )}
    >
      <input
        type="checkbox"
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        disabled={disabled}
        className="h-3.5 w-3.5 rounded border-line bg-canvas accent-amber"
      />
      {label}
    </label>
  );
}

function Notice({
  tone,
  title,
  children,
}: {
  tone: "error" | "info";
  title: string;
  children: ReactNode;
}) {
  return (
    <div
      role={tone === "error" ? "alert" : "status"}
      className={cx(
        "flex items-start gap-3 rounded-xl border p-4",
        tone === "error"
          ? "border-rose/30 bg-rose/[0.07]"
          : "border-line bg-surface"
      )}
    >
      {tone === "error" ? (
        <AlertIcon size={18} className="mt-0.5 shrink-0 text-rose" />
      ) : (
        <ServerIcon size={18} className="mt-0.5 shrink-0 text-amber" />
      )}
      <div className="min-w-0">
        <p className="text-sm font-medium text-ink">{title}</p>
        <p className="mt-1 break-words text-sm leading-relaxed text-muted">
          {children}
        </p>
      </div>
    </div>
  );
}

function LibrarySkeleton() {
  return (
    <div className="mt-4 animate-pulse divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
      {Array.from({ length: 4 }).map((_, index) => (
        <div key={index} className="flex items-center gap-3 px-4 py-3.5">
          <div className="h-16 w-11 rounded-xl bg-panel" />
          <div className="flex-1 space-y-2">
            <div className="h-3.5 w-2/3 rounded bg-panel" />
            <div className="h-3 w-1/2 rounded bg-panel/70" />
          </div>
        </div>
      ))}
    </div>
  );
}
