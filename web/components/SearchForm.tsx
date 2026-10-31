"use client";

import { useEffect, useRef, useState } from "react";
import { cx } from "@/lib/cx";
import { parseInput } from "@/lib/input";
import { ArrowRightIcon, CloseIcon, SearchIcon, SpinnerIcon } from "./icons";

export function SearchForm({
  onSubmit,
  loading,
}: {
  onSubmit: (value: string) => void | Promise<void>;
  loading: boolean;
}) {
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const mode =
    value.trim().length === 0
      ? "browse"
      : parseInput(value).kind === "url"
        ? "scrape"
        : "search";

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if (event.key !== "/" || event.metaKey || event.ctrlKey || event.altKey) {
        return;
      }
      const active = document.activeElement;
      const typing =
        active instanceof HTMLElement &&
        (active.tagName === "INPUT" ||
          active.tagName === "TEXTAREA" ||
          active.isContentEditable);
      if (!typing) {
        event.preventDefault();
        inputRef.current?.focus();
      }
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    const parsed = parseInput(value);

    if (parsed.kind === "invalid-url") {
      setError("That URL doesn't look right — use https://movielinkbd.tv/movie/<slug>");
      inputRef.current?.focus();
      return;
    }

    if (parsed.kind === "query") {
      if (parsed.query.length === 1) {
        setError("Type at least 2 characters to search.");
        inputRef.current?.focus();
        return;
      }
    }

    setError(null);
    void onSubmit(parsed.kind === "url" ? parsed.url : parsed.query);
  }

  return (
    <form onSubmit={handleSubmit} noValidate>
      <div className="flex flex-col gap-3 sm:flex-row">
        <div className="relative flex-1">
          <SearchIcon
            size={17}
            className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-faint"
          />
          <input
            ref={inputRef}
            type="text"
            autoComplete="off"
            spellCheck={false}
            aria-label="Search a title or paste a MovieLinkBD page URL"
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? "input-error" : undefined}
            placeholder="Search a title, or paste a movielinkbd.tv URL"
            value={value}
            onChange={(event) => {
              setValue(event.target.value);
              if (error) setError(null);
            }}
            className={cx(
              "h-12 w-full rounded-2xl border bg-canvas pl-11 pr-11 text-sm text-ink transition-colors duration-200",
              error
                ? "border-rose/60"
                : "border-line hover:border-line-strong focus:border-amber/60"
            )}
          />
          {value.length > 0 && (
            <button
              type="button"
              onClick={() => {
                setValue("");
                setError(null);
                inputRef.current?.focus();
              }}
              aria-label="Clear search"
              className="absolute right-3 top-1/2 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-md text-faint transition-colors duration-200 hover:bg-raised hover:text-ink"
            >
              <CloseIcon size={14} />
            </button>
          )}
        </div>

        <button
          type="submit"
          disabled={loading}
          className="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-amber px-6 text-sm font-semibold text-[#1a1205] transition-colors duration-200 hover:bg-amber-soft active:bg-amber-deep disabled:cursor-not-allowed disabled:opacity-60 sm:min-w-[9rem]"
        >
          {loading ? (
            <>
              <SpinnerIcon size={16} />
              {mode === "scrape"
                ? "Scraping"
                : mode === "browse"
                  ? "Loading"
                  : "Searching"}
            </>
          ) : (
            <>
              {mode === "scrape"
                ? "Scrape"
                : mode === "browse"
                  ? "Browse"
                  : "Search"}
              <ArrowRightIcon size={16} />
            </>
          )}
        </button>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-faint">
        <span>Title or detail page URL</span>
        <code className="font-mono text-[11px] text-muted">
          movielinkbd.tv/movie/&lt;slug&gt;
        </code>
        <span className="ml-auto hidden items-center gap-1.5 sm:inline-flex">
          Press
          <kbd className="rounded border border-line bg-panel px-1.5 py-0.5 font-mono text-[10px] leading-none text-muted">
            /
          </kbd>
          to focus
        </span>
      </div>

      {error && (
        <p
          id="input-error"
          role="alert"
          className="mt-3 rounded-lg border border-rose/30 bg-rose/10 px-3 py-2 text-sm text-rose"
        >
          {error}
        </p>
      )}
    </form>
  );
}
