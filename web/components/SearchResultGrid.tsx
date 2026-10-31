"use client";

import { useState } from "react";
import type { SearchItem } from "@/lib/api";
import { formatCount } from "@/lib/format";
import { FilmIcon, StarIcon } from "./icons";

function Poster({ src }: { src: string | null }) {
  const [failed, setFailed] = useState(false);

  if (!src || failed) {
    return (
      <span className="flex h-full w-full items-center justify-center text-faint">
        <FilmIcon size={22} />
      </span>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={src}
      alt=""
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
      className="h-full w-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.04]"
    />
  );
}

function typeLabel(item: SearchItem) {
  const value = item.category ?? item.type;
  if (!value) return null;
  if (value === "download18plus" || value === "adult") return "Adult";
  return value.charAt(0).toUpperCase() + value.slice(1);
}

export function SearchResultGrid({
  items,
  query,
  total,
  onSelect,
  disabled,
}: {
  items: SearchItem[];
  query: string;
  total?: number;
  onSelect: (item: SearchItem) => void;
  disabled?: boolean;
}) {
  return (
    <section className="animate-rise">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5">
        <h2 className="text-sm font-medium text-ink">
          {formatCount(typeof total === "number" ? total : items.length)}{" "}
          {total === 1 ? "title" : "titles"}
          {query ? (
            <span className="text-muted"> for “{query}”</span>
          ) : (
            <span className="text-muted"> in the catalogue</span>
          )}
        </h2>
        <p className="text-xs text-faint">
          Pick one to pull every episode&apos;s stream and download links.
        </p>
      </div>

      <ul className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        {items.map((item) => {
          const label = typeLabel(item);
          return (
            <li key={item.url}>
              <button
                type="button"
                onClick={() => onSelect(item)}
                disabled={disabled}
                aria-label={`Scrape stream sources for ${item.title}`}
                className="group flex h-full w-full flex-col overflow-hidden rounded-2xl border border-line bg-surface text-left transition-colors duration-200 hover:border-amber/50 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <span className="relative block aspect-[3/4] w-full overflow-hidden bg-panel">
                  <Poster src={item.poster} />

                  {item.rating && (
                    <span className="absolute left-3 top-3 inline-flex items-center gap-1 rounded-md bg-canvas/90 px-2 py-0.5 text-[10px] font-medium tabular-nums text-amber-soft shadow-sm">
                      <StarIcon size={10} />
                      {item.rating}
                    </span>
                  )}
                  {item.adult && (
                    <span className="absolute right-3 top-3 rounded-md bg-rose/95 px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                      18+
                    </span>
                  )}
                  {!item.adult && item.quality && (
                    <span className="absolute right-3 top-3 rounded-md bg-canvas/90 px-2 py-0.5 text-[10px] font-medium text-ink shadow-sm">
                      {item.quality}
                    </span>
                  )}
                </span>

                <span className="flex flex-1 flex-col gap-1 p-3">
                  <span className="line-clamp-2 text-[13px] font-medium leading-snug text-ink group-hover:text-amber/90 transition-colors duration-200">
                    {item.title}
                  </span>
                  <span className="mt-auto flex items-center gap-1.5 text-[11px] text-faint">
                    {label && <span>{label}</span>}
                    {label && item.year && <span aria-hidden="true">·</span>}
                    {item.year && <span className="tabular-nums">{item.year}</span>}
                    {!item.year && item.language && (
                      <>
                        <span aria-hidden="true">·</span>
                        <span className="truncate">{item.language}</span>
                      </>
                    )}
                  </span>
                </span>
              </button>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
