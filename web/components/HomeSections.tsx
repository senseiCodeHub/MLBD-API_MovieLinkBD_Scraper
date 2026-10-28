import {
  BookIcon,
  ExternalIcon,
  GitHubIcon,
  LayersIcon,
  ServerIcon,
  ShieldIcon,
  StarIcon,
} from "@/components/icons";
import { API_BASE } from "@/lib/api";
import { cx } from "@/lib/cx";
import { AUTHOR } from "@/lib/site";

const FEATURES = [
  {
    icon: LayersIcon,
    tone: "text-amber",
    title: "Whole catalogue",
    body: "One index over every movie, series, drama, anime and adult title the site publishes — not just the first 25 search hits.",
  },
  {
    icon: ServerIcon,
    tone: "text-mint",
    title: "Every episode, every server",
    body: "S1E1, E2, E3 … each entry keeps all of its sources: quality, audio tracks, codec, size and provider.",
  },
  {
    icon: ShieldIcon,
    tone: "text-sky",
    title: "No ad gate touched",
    body: "The scraper decodes the page's own inline player payload. The unlock bridge, ad iframes and click scripts are never triggered.",
  },
];

const ENDPOINTS = [
  {
    method: "GET",
    path: "/search",
    hint: "q · category · limit · offset · scope",
  },
  {
    method: "GET",
    path: "/scrape",
    hint: "url · probe",
  },
  {
    method: "GET",
    path: "/catalog/status",
    hint: "index version, count, type counts",
  },
  {
    method: "POST",
    path: "/catalog/refresh",
    hint: "force a fresh shard crawl",
  },
];

export function StarButton({
  className,
  label = "Star on GitHub",
}: {
  className?: string;
  label?: string;
}) {
  return (
    <a
      href={AUTHOR.repo}
      target="_blank"
      rel="noreferrer"
      className={cx(
        "inline-flex h-9 items-center gap-2 rounded-lg border border-amber/40 bg-amber/10 px-3 text-xs font-semibold text-amber-soft transition-colors duration-150 hover:border-amber/70 hover:bg-amber/15 hover:text-amber",
        className
      )}
    >
      <StarIcon size={13} className="text-amber" />
      {label}
    </a>
  );
}

export function HomeExtras() {
  return (
    <div className="space-y-5">
      <section aria-labelledby="features-heading">
        <h2 id="features-heading" className="sr-only">
          What the scraper returns
        </h2>
        <div className="grid gap-3 sm:grid-cols-3">
          {FEATURES.map(({ icon: Icon, tone, title, body }) => (
            <article
              key={title}
              className="rounded-xl border border-line bg-surface p-4 transition-colors duration-150 hover:border-line-strong"
            >
              <span
                className={cx(
                  "grid h-8 w-8 place-items-center rounded-lg border border-line bg-panel",
                  tone
                )}
              >
                <Icon size={15} />
              </span>
              <h3 className="mt-3 text-sm font-medium text-ink">{title}</h3>
              <p className="mt-1.5 text-xs leading-relaxed text-faint">
                {body}
              </p>
            </article>
          ))}
        </div>
      </section>

      <section
        aria-labelledby="api-heading"
        className="overflow-hidden rounded-xl border border-line bg-surface"
      >
        <div className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3.5">
          <span className="grid h-8 w-8 place-items-center rounded-lg border border-line bg-panel text-sky">
            <BookIcon size={15} />
          </span>
          <div className="min-w-0">
            <h2 id="api-heading" className="text-sm font-medium text-ink">
              Build with the API
            </h2>
            <p className="text-xs text-faint">
              FastAPI · OpenAPI schema · CORS enabled
            </p>
          </div>
          <div className="ml-auto flex flex-wrap items-center gap-2">
            <a
              href={AUTHOR.apiDocs}
              target="_blank"
              rel="noreferrer"
              className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-panel px-2.5 text-[11px] font-medium text-muted transition-colors duration-150 hover:border-line-strong hover:text-ink"
            >
              API guide
              <ExternalIcon size={12} />
            </a>
            <a
              href={`${API_BASE}/docs`}
              target="_blank"
              rel="noreferrer"
              className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-panel px-2.5 text-[11px] font-medium text-muted transition-colors duration-150 hover:border-line-strong hover:text-ink"
            >
              OpenAPI docs
              <ExternalIcon size={12} />
            </a>
          </div>
        </div>
        <ul className="divide-y divide-line/70">
          {ENDPOINTS.map((endpoint) => (
            <li
              key={endpoint.path}
              className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 font-mono text-[11px]"
            >
              <span
                className={cx(
                  "rounded px-1.5 py-0.5 text-[10px] font-semibold",
                  endpoint.method === "GET"
                    ? "bg-mint/10 text-mint"
                    : "bg-lilac/10 text-lilac"
                )}
              >
                {endpoint.method}
              </span>
              <span className="text-ink">{endpoint.path}</span>
              <span className="ml-auto text-faint">{endpoint.hint}</span>
            </li>
          ))}
        </ul>
      </section>

      <section
        aria-labelledby="author-heading"
        className="relative overflow-hidden rounded-xl border border-line bg-surface p-5"
      >
        <div
          aria-hidden="true"
          className="pointer-events-none absolute -right-16 -top-20 h-48 w-48 rounded-full bg-amber/10 blur-3xl"
        />
        <div className="relative flex flex-col gap-4 sm:flex-row sm:items-center">
          <div className="flex min-w-0 items-center gap-3">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl border border-amber/30 bg-amber/10 text-base font-semibold text-amber-soft">
              RI
            </span>
            <div className="min-w-0">
              <p className="text-[11px] font-medium uppercase tracking-[0.14em] text-faint">
                Our details
              </p>
              <h2
                id="author-heading"
                className="truncate text-sm font-semibold text-ink"
              >
                {AUTHOR.name}{" "}
                <span className="font-normal text-muted">
                  · @{AUTHOR.handle}
                </span>
              </h2>
              <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <a
                  href={AUTHOR.github}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1.5 text-muted transition-colors duration-150 hover:text-amber"
                >
                  <GitHubIcon size={13} />
                  github.com/{AUTHOR.handle}
                </a>
                <a
                  href={AUTHOR.repo}
                  target="_blank"
                  rel="noreferrer"
                  className="inline-flex items-center gap-1.5 text-muted transition-colors duration-150 hover:text-amber"
                >
                  {AUTHOR.repoName}
                  <ExternalIcon size={12} />
                </a>
              </div>
            </div>
          </div>
          <div className="flex flex-col items-start gap-2 sm:ml-auto sm:items-end">
            <StarButton />
            <p className="text-[11px] text-faint">
              A star helps other people find the project.
            </p>
          </div>
        </div>
      </section>
    </div>
  );
}
