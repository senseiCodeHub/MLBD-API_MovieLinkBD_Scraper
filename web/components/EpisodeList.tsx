"use client";

import { useMemo, useState } from "react";
import type { Episode, EpisodeSource } from "@/lib/api";
import { formatBytes } from "@/lib/format";
import { CopyButton } from "./CopyButton";
import { ExternalIcon, SearchIcon } from "./icons";

function SourceRow({ source }: { source: EpisodeSource }) {
  const size = formatBytes(source.size_bytes);
  const name = source.name?.trim();

  return (
    <div className="rounded-xl border border-line/80 bg-panel/40 p-3">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
        <span className="shrink-0 rounded-md border border-line bg-raised px-1.5 py-0.5 font-mono text-[11px] text-ink">
          {source.quality ?? "auto"}
        </span>
        <span className="shrink-0 rounded-md border border-line/70 px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-faint">
          {source.type}
        </span>
        {source.audio && (
          <span className="shrink-0 text-[11px] text-muted">{source.audio}</span>
        )}
        {size && (
          <span className="shrink-0 text-[11px] tabular-nums text-faint">
            {size}
          </span>
        )}
        {source.provider && (
          <span className="hidden shrink-0 text-[11px] text-faint sm:inline">
            {source.provider}
          </span>
        )}
        {source.is_best && (
          <span className="shrink-0 rounded-md border border-amber/30 bg-amber/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-soft">
            BEST
          </span>
        )}

        <div className="ml-auto flex shrink-0 items-center gap-1.5">
          <CopyButton value={source.stream_url} label="Stream" />
          <a
            href={source.stream_url}
            target="_blank"
            rel="noreferrer"
            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line px-2 text-xs font-medium text-muted transition-colors duration-200 hover:border-amber/50 hover:bg-amber/5 hover:text-amber"
          >
            <ExternalIcon size={13} />
            Open
          </a>
          {source.download_url && (
            <a
              href={source.download_url}
              target="_blank"
              rel="noreferrer"
              className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line px-2 text-xs font-medium text-muted transition-colors duration-200 hover:border-amber/50 hover:bg-amber/5 hover:text-amber"
            >
              Download
            </a>
          )}
        </div>
      </div>

      <code
        className="mt-1.5 block truncate font-mono text-[11px] text-faint"
        title={source.stream_url}
      >
        {source.stream_url}
      </code>
      {name && (
        <p className="mt-0.5 truncate text-[11px] text-faint" title={name}>
          {name}
        </p>
      )}
    </div>
  );
}

export function EpisodeList({ episodes }: { episodes: Episode[] }) {
  const [filter, setFilter] = useState("");
  const showFilter = episodes.length > 10;
  const singleMovie =
    episodes.length === 1 &&
    (episodes[0].kind === "movie" ||
      episodes[0].label.trim().toLowerCase() === "movie");

  const visible = useMemo(() => {
    const needle = filter.trim().toLowerCase();
    if (!needle) return episodes;
    return episodes.filter((episode) => {
      const haystack = `${episode.label} ${episode.number ?? ""} ${
        episode.season ?? ""
      }`.toLowerCase();
      return haystack.includes(needle);
    });
  }, [episodes, filter]);

  const sourceCount = episodes.reduce(
    (total, episode) => total + episode.sources.length,
    0
  );

  return (
    <section>
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5">
        <h3 className="text-sm font-medium text-ink">
          {singleMovie ? "Stream sources" : "Episodes & stream links"}
        </h3>
        <p className="text-xs text-faint">
          <span className="tabular-nums">{episodes.length}</span>{" "}
          {episodes.length === 1 ? "entry" : "episodes"} ·{" "}
          <span className="tabular-nums">{sourceCount}</span>{" "}
          {sourceCount === 1 ? "source" : "sources"}
        </p>
      </div>

      {showFilter && (
        <div className="relative mt-3">
          <SearchIcon
            size={14}
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-faint"
          />
          <input
            type="text"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            placeholder="Filter episodes (e.g. 12 or S03)"
            aria-label="Filter episodes"
            className="h-9 w-full rounded-xl border border-line bg-canvas pl-8 pr-3 text-xs text-ink transition-colors duration-200 hover:border-line-strong focus:border-amber/60"
          />
        </div>
      )}

      <ul className="mt-4 divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface">
        {visible.map((episode) => (
          <li key={episode.id} className="px-5 py-3.5">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="rounded-md border border-line bg-raised px-2 py-0.5 font-mono text-[11px] text-ink">
                {episode.label}
              </span>
              <span className="text-[11px] text-faint">
                {episode.sources.length}{" "}
                {episode.sources.length === 1 ? "source" : "sources"}
              </span>
            </div>

            {episode.sources.length > 0 ? (
              <div className="mt-2.5 space-y-1.5">
                {episode.sources.map((source, index) => (
                  <SourceRow
                    key={`${source.stream_url}-${index}`}
                    source={source}
                  />
                ))}
              </div>
            ) : (
              <p className="mt-2.5 text-xs text-faint">
                No playable source was exposed for this episode.
              </p>
            )}
          </li>
        ))}
      </ul>

      {visible.length === 0 && (
        <p className="mt-3 text-xs text-faint">
          No episode matches “{filter.trim()}”.
        </p>
      )}
    </section>
  );
}
