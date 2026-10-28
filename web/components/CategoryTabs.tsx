"use client";

import type { CatalogCategory } from "@/lib/api";
import { cx } from "@/lib/cx";

const TABS: Array<{ value: CatalogCategory; label: string }> = [
  { value: "all", label: "All" },
  { value: "movie", label: "Movies" },
  { value: "series", label: "Series" },
  { value: "drama", label: "Drama" },
  { value: "anime", label: "Anime" },
  { value: "adult", label: "Adult" },
];

export function CategoryTabs({
  value,
  onChange,
  disabled,
}: {
  value: CatalogCategory;
  onChange: (value: CatalogCategory) => void;
  disabled?: boolean;
}) {
  return (
    <div
      role="tablist"
      aria-label="Catalogue category"
      className="flex flex-wrap items-center gap-1.5"
    >
      {TABS.map((tab) => (
        <button
          key={tab.value}
          type="button"
          role="tab"
          aria-selected={value === tab.value}
          disabled={disabled}
          onClick={() => onChange(tab.value)}
          className={cx(
            "rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-60",
            value === tab.value
              ? "border-amber/50 bg-amber/10 text-amber-soft"
              : "border-line bg-surface text-muted hover:border-line-strong hover:text-ink"
          )}
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}
