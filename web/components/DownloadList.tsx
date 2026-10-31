import type { ScrapeDownload } from "@/lib/api";
import { formatBytes } from "@/lib/format";
import { CopyButton } from "./CopyButton";
import { ExternalIcon } from "./icons";

export function DownloadList({
  downloads,
  showHeading = true,
}: {
  downloads: ScrapeDownload[];
  showHeading?: boolean;
}) {
  const episodes = new Set(
    downloads.map((download) => download.episode).filter(Boolean)
  );

  return (
    <section className={showHeading ? "mt-8" : "pb-4"}>
      {showHeading && (
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1.5">
          <h3 className="text-sm font-medium text-ink">Download links</h3>
          <p className="text-xs text-faint">
            <span className="tabular-nums">{downloads.length}</span>{" "}
            {downloads.length === 1 ? "link" : "links"}
            {episodes.size > 1 && (
              <>
                {" "}
                across <span className="tabular-nums">{episodes.size}</span>{" "}
                episodes
              </>
            )}
          </p>
        </div>
      )}

      <ul
        className={`${
          showHeading ? "mt-4" : "mt-2"
        } divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface`}
      >
        {downloads.map((download, index) => {
          const size = formatBytes(download.size_bytes) ?? download.size;
          return (
            <li
              key={`${download.url}-${index}`}
              className="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3.5 transition-colors duration-200 hover:bg-panel/50"
            >
              <span className="w-16 shrink-0 rounded-md border border-line bg-raised px-1.5 py-0.5 text-center font-mono text-[11px] text-ink">
                {download.quality ?? "file"}
              </span>

              <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                {download.episode && (
                  <span className="truncate text-sm text-ink">
                    {download.episode}
                  </span>
                )}
                {download.audio && (
                  <span className="text-xs text-muted">{download.audio}</span>
                )}
                {size && (
                  <span className="text-xs tabular-nums text-faint">
                    {size}
                  </span>
                )}
              </div>

              <div className="flex shrink-0 items-center gap-1.5">
                <CopyButton value={download.url} />
                <a
                  href={download.url}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line px-2 text-xs font-medium text-muted transition-colors duration-200 hover:border-amber/50 hover:bg-amber/5 hover:text-amber"
                >
                  <ExternalIcon size={13} />
                  Open
                </a>
              </div>
            </li>
          );
        })}
      </ul>

      <p className="mt-3 text-xs leading-relaxed text-faint">
        Some titles link straight to the file host; others open MovieLinkBD&apos;s
        own download page, which may show an unlock or ad step before the file.
      </p>
    </section>
  );
}
