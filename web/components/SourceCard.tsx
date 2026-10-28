import type { ScrapeSource } from "@/lib/api";
import { formatBytes } from "@/lib/format";
import { CopyButton } from "./CopyButton";
import { ExternalIcon } from "./icons";

const TYPE_STYLES: Record<ScrapeSource["type"], string> = {
  hls: "border-amber/25 bg-amber/10 text-amber-soft",
  mp4: "border-sky/25 bg-sky/10 text-sky",
  mkv: "border-lilac/25 bg-lilac/10 text-lilac",
  webm: "border-mint/25 bg-mint/10 text-mint",
  auto: "border-line bg-raised text-muted",
};

function formatBandwidth(bitsPerSecond: number | null) {
  if (!bitsPerSecond || bitsPerSecond <= 0) return null;
  if (bitsPerSecond >= 1_000_000) {
    const mbps = bitsPerSecond / 1_000_000;
    return `${mbps >= 10 ? Math.round(mbps) : mbps.toFixed(1)} Mbps`;
  }
  return `${Math.round(bitsPerSecond / 1000)} kbps`;
}

export function SourceCard({
  source,
  index,
}: {
  source: ScrapeSource;
  index: number;
}) {
  const name = source.server_name?.trim() || `Source ${index + 1}`;
  const isHls = source.type === "hls";
  const size = formatBytes(source.size_bytes);
  const directQuality = isHls ? null : source.variants[0]?.quality ?? null;
  const variants = source.variants.filter(
    (variant) => variant.url !== source.master_url
  );

  return (
    <article className="overflow-hidden rounded-xl border border-line bg-surface">
      <header className="flex flex-wrap items-center gap-x-2.5 gap-y-2 border-b border-line bg-panel/60 px-4 py-3">
        <span
          className={`shrink-0 rounded-md border px-1.5 py-0.5 font-mono text-[10px] font-medium uppercase tracking-wider ${TYPE_STYLES[source.type]}`}
        >
          {source.type}
        </span>
        <h3 className="min-w-0 flex-1 truncate text-sm font-medium text-ink">
          {name}
        </h3>
        {directQuality && (
          <span className="shrink-0 rounded-md border border-line bg-raised px-1.5 py-0.5 font-mono text-[11px] text-ink">
            {directQuality}
          </span>
        )}
        {size && (
          <span className="shrink-0 text-xs tabular-nums text-faint">
            {size}
          </span>
        )}
        {variants.length > 0 && (
          <span className="shrink-0 text-xs tabular-nums text-faint">
            {variants.length}{" "}
            {variants.length === 1 ? "variant" : "variants"}
          </span>
        )}
        <div className="flex w-full items-center justify-end gap-1.5 sm:w-auto">
          <CopyButton value={source.master_url} label="URL" />
          <a
            href={source.master_url}
            target="_blank"
            rel="noreferrer"
            className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line px-2 text-xs font-medium text-muted transition-colors duration-150 hover:border-line-strong hover:bg-raised hover:text-ink"
          >
            <ExternalIcon size={13} />
            Open
          </a>
        </div>
      </header>

      <div className="px-4 py-3">
        <p className="text-[10px] font-medium uppercase tracking-widest text-faint">
          {isHls ? "Master playlist" : "Direct file"}
        </p>
        <code
          className="mt-1 block truncate font-mono text-xs text-muted"
          title={source.master_url}
        >
          {source.master_url}
        </code>
      </div>

      {variants.length > 0 ? (
        <ul className="divide-y divide-line/70 border-t border-line/70">
          {variants.map((variant, variantIndex) => {
            const bandwidth = formatBandwidth(variant.bandwidth);
            return (
              <li
                key={`${variant.url}-${variantIndex}`}
                className="flex items-center gap-3 px-4 py-2.5 transition-colors duration-150 hover:bg-panel/50"
              >
                <span className="w-16 shrink-0 rounded-md border border-line bg-raised px-1.5 py-0.5 text-center font-mono text-[11px] text-ink">
                  {variant.quality}
                </span>
                <span className="hidden w-20 shrink-0 text-xs tabular-nums text-faint sm:block">
                  {bandwidth ?? "—"}
                </span>
                <code
                  className="min-w-0 flex-1 truncate font-mono text-[11px] text-muted"
                  title={variant.url}
                >
                  {variant.url}
                </code>
                <CopyButton value={variant.url} />
              </li>
            );
          })}
        </ul>
      ) : isHls ? (
        <p className="border-t border-line/70 px-4 py-3 text-xs text-faint">
          No variant playlist found — copy the master playlist URL above.
        </p>
      ) : null}
    </article>
  );
}
